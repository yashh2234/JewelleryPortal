<?php

namespace App\Http\Controllers\Kaarigar;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\JobCard;
use App\Models\KaarigarRequest;
use App\Models\KaarigarUser;
use App\Models\MetalTransaction;
use App\Services\Media\ImageStore;
use App\Services\Metal\Ledger;
use App\Services\Metal\MetalOperations;
use App\Services\Metal\Statements;
use App\Support\Metal\Weight;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The kaarigar's own data only. Every action here is a request for the store;
 * nothing a kaarigar does posts to the gold ledger directly.
 */
class AppController extends Controller
{
    public function __construct(private Ledger $ledger, private MetalOperations $ops) {}

    private function me(Request $request): KaarigarUser
    {
        return $request->user('kaarigar')->loadMissing('kaarigar');
    }

    public function home(Request $request): JsonResponse
    {
        $kaarigar = $this->me($request)->kaarigar;
        $balance = $this->ledger->balance($this->ledger->kaarigar($kaarigar));

        return response()->json([
            'gold' => $balance,
            'jobs_due_today' => JobCard::where('kaarigar_id', $kaarigar->id)->where('status', 'open')
                ->where('due_date', '<=', today()->toDateString())->count(),
            'open_jobs' => JobCard::where('kaarigar_id', $kaarigar->id)->where('status', 'open')->count(),
            'new_issues' => $this->pendingIssues($kaarigar->id)->count(),
            'labour_this_week' => $this->labourSince($kaarigar, today()->subDays(6)->toDateString()),
        ]);
    }

    /** Issues the kaarigar has not yet accepted or disputed. */
    public function issues(Request $request): JsonResponse
    {
        $kaarigar = $this->me($request)->kaarigar;

        $issues = $this->pendingIssues($kaarigar->id)
            ->with(['lines.karat:id,label', 'jobCard.process:id,name', 'jobCard.design.images'])
            ->latest('id')->get()
            ->map(fn (MetalTransaction $tx) => [
                'id' => $tx->id,
                'voucher_no' => $tx->voucher_no,
                'date' => $tx->date,
                'job_card' => $tx->jobCard?->only(['id', 'card_no', 'description', 'due_date', 'pieces']),
                'process' => $tx->jobCard?->process?->name,
                'design_photo' => $tx->jobCard?->design?->images->first()?->thumb_url,
                'lines' => $tx->lines->map(fn ($l) => ['karat' => $l->karat->label, 'gross_wt' => $l->gross_wt, 'fine_wt' => $l->fine_wt, 'pieces' => $l->pieces]),
            ]);

        return response()->json(['data' => $issues]);
    }

    public function accept(Request $request, MetalTransaction $transaction): JsonResponse
    {
        $this->ownIssue($request, $transaction);
        $transaction->forceFill(['kaarigar_ack' => 'accepted', 'kaarigar_ack_at' => now()])->save();

        return response()->json(['ok' => true]);
    }

    public function dispute(Request $request, MetalTransaction $transaction): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'weight_received' => ['nullable', 'decimal:0,3', 'min:0'],
        ]);
        $me = $this->me($request);
        $this->ownIssue($request, $transaction);

        DB::transaction(function () use ($transaction, $data, $me) {
            $transaction->forceFill(['kaarigar_ack' => 'disputed', 'kaarigar_ack_at' => now()])->save();

            KaarigarRequest::create([
                'kaarigar_id' => $me->kaarigar_id, 'kaarigar_user_id' => $me->id, 'type' => KaarigarRequest::DISPUTE,
                'job_card_id' => $transaction->job_card_id, 'metal_transaction_id' => $transaction->id, 'data' => $data,
            ]);
        });

        return response()->json(['ok' => true]);
    }

    public function jobs(Request $request): JsonResponse
    {
        $kaarigar = $this->me($request)->kaarigar;

        $jobs = JobCard::with(['process:id,name', 'karat:id,label', 'design.images'])
            ->where('kaarigar_id', $kaarigar->id)->where('status', 'open')
            ->orderByRaw('due_date is null')->orderBy('due_date')->get()
            ->map(fn (JobCard $c) => [
                'id' => $c->id, 'card_no' => $c->card_no, 'description' => $c->description,
                'process' => $c->process->name, 'karat' => $c->karat->label, 'pieces' => $c->pieces,
                'due_date' => $c->due_date?->toDateString(), 'kaarigar_status' => $c->kaarigar_status,
                'design' => $c->design ? ['code' => $c->design->code, 'name' => $c->design->name, 'photo' => $c->design->images->first()?->thumb_url] : null,
                'outstanding_fine' => (fn ($f) => Weight::sub($f['issued'], Weight::add($f['returned'], $f['scrap'])))($this->ops->cardFigures($c)),
                'return_pending' => KaarigarRequest::where('job_card_id', $c->id)->where('type', 'return')->where('status', 'pending')->exists(),
            ]);

        return response()->json(['data' => $jobs]);
    }

    public function setJobStatus(Request $request, JobCard $jobCard): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['started', 'ready'])]]);
        abort_unless($jobCard->kaarigar_id === $this->me($request)->kaarigar_id && $jobCard->isOpen(), 404);

        $jobCard->update(['kaarigar_status' => $data['status']]);

        return response()->json(['ok' => true]);
    }

    /**
     * Return request with photos of the work and the scale. The store weighs it
     * and posts the real receive when approving.
     */
    public function requestReturn(Request $request, JobCard $jobCard, ImageStore $images): JsonResponse
    {
        $me = $this->me($request);
        abort_unless($jobCard->kaarigar_id === $me->kaarigar_id && $jobCard->isOpen(), 404);

        $data = $request->validate([
            'work_gross' => ['nullable', 'decimal:0,3', 'min:0'],
            'stone' => ['nullable', 'decimal:0,3', 'min:0'],
            'scrap_gross' => ['nullable', 'decimal:0,3', 'min:0'],
            'pieces' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
            'client_key' => ['nullable', 'string', 'max:100'],
            'work_photos' => ['nullable', 'array', 'max:5'],
            'work_photos.*' => ['image', 'max:8192'],
            'scale_photos' => ['nullable', 'array', 'max:3'],
            'scale_photos.*' => ['image', 'max:8192'],
        ]);

        // Offline replays carry the same client_key; answer with the existing request.
        if (! empty($data['client_key']) && ($existing = KaarigarRequest::where('kaarigar_id', $me->kaarigar_id)->where('client_key', $data['client_key'])->first())) {
            return response()->json(['data' => $existing], 200);
        }

        $req = DB::transaction(function () use ($request, $data, $me, $jobCard, $images) {
            $req = KaarigarRequest::create([
                'kaarigar_id' => $me->kaarigar_id, 'kaarigar_user_id' => $me->id, 'type' => KaarigarRequest::RETURN,
                'job_card_id' => $jobCard->id, 'client_key' => $data['client_key'] ?? null,
                'data' => collect($data)->only(['work_gross', 'stone', 'scrap_gross', 'pieces', 'note'])->filter(fn ($v) => $v !== null)->all(),
            ]);

            foreach (['work_photos' => 'work_photo', 'scale_photos' => 'scale_photo'] as $field => $kind) {
                foreach ($request->file($field, []) as $file) {
                    $paths = $images->store($file, $me->tenant_id, "requests/{$req->id}");
                    $req->attachments()->create($paths + ['kind' => $kind]);
                }
            }

            $jobCard->update(['kaarigar_status' => 'ready']);

            return $req;
        });

        return response()->json(['data' => $req->load('attachments')], 201);
    }

    public function requestAdvance(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $data = $request->validate(['amount' => ['required', 'decimal:0,2', 'gt:0'], 'note' => ['nullable', 'string', 'max:300']]);

        $req = KaarigarRequest::create([
            'kaarigar_id' => $me->kaarigar_id, 'kaarigar_user_id' => $me->id, 'type' => KaarigarRequest::ADVANCE, 'data' => $data,
        ]);

        return response()->json(['data' => $req], 201);
    }

    public function requests(Request $request): JsonResponse
    {
        return response()->json(['data' => KaarigarRequest::with('jobCard:id,card_no')
            ->where('kaarigar_id', $this->me($request)->kaarigar_id)->latest('id')->limit(50)->get()]);
    }

    public function khata(Request $request, Statements $statements): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return response()->json($statements->kaarigar(
            $this->me($request)->kaarigar,
            $data['from'] ?? today()->subDays(30)->toDateString(),
            $data['to'] ?? null,
        ));
    }

    /** Labour earned, advances and payments, and what the store owes him now. */
    public function earnings(Request $request, \App\Services\Accounting\Books $books): JsonResponse
    {
        $kaarigar = $this->me($request)->kaarigar;
        $party = $books->party($kaarigar);
        $from = today()->subDays(60)->toDateString();

        $rows = \App\Models\VoucherEntry::with('voucher:id,voucher_no,type,date,narration,status')
            ->where('party_id', $party->id)
            ->whereHas('voucher', fn ($q) => $q->where('date', '>=', $from))
            ->get()
            ->sortByDesc(fn ($e) => [$e->voucher->date, $e->voucher_id])
            ->map(fn ($e) => [
                'date' => $e->voucher->date, 'voucher_no' => $e->voucher->voucher_no, 'type' => $e->voucher->type,
                'narration' => $e->voucher->narration,
                // From the kaarigar's side: credit = earned, debit = paid / advanced / deducted.
                'earned' => \App\Support\Money::isZero($e->credit) ? null : $e->credit,
                'paid' => \App\Support\Money::isZero($e->debit) ? null : $e->debit,
            ])->values();

        $balance = $books->partyBalance($party)['money'];

        return response()->json([
            'balance_due' => \App\Support\Money::sub('0', $balance),   // positive = store owes the kaarigar
            'this_week' => $this->labourSince($kaarigar, today()->subDays(6)->toDateString()),
            'rows' => $rows,
        ]);
    }

    private function labourSince(\App\Models\Kaarigar $kaarigar, string $from): string
    {
        $party = \App\Models\Party::where('ref_type', $kaarigar->getMorphClass())->where('ref_id', $kaarigar->id)->first();
        if (! $party) {
            return '0.00';
        }

        $sum = \App\Models\VoucherEntry::where('party_id', $party->id)
            ->whereHas('voucher', fn ($q) => $q->where('type', 'labour')->where('status', 'posted')->where('date', '>=', $from))
            ->sum('credit');

        return \App\Support\Money::fromDb($sum);
    }

    private function pendingIssues(int $kaarigarId)
    {
        return MetalTransaction::where('type', MetalTransaction::ISSUE)->where('kaarigar_id', $kaarigarId)
            ->whereNull('kaarigar_ack')->whereNull('reversed_by_id');
    }

    private function ownIssue(Request $request, MetalTransaction $tx): void
    {
        abort_unless(
            $tx->type === MetalTransaction::ISSUE && $tx->kaarigar_id === $this->me($request)->kaarigar_id && $tx->kaarigar_ack === null,
            404,
        );
    }
}
