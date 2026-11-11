<?php

namespace App\Http\Controllers\Kaarigar;

use App\Http\Controllers\Controller;
use App\Models\JobCard;
use App\Models\Kaarigar;
use App\Models\KaarigarRequest;
use App\Models\KaarigarUser;
use App\Models\MetalTransaction;
use App\Models\Party;
use App\Models\Process;
use App\Models\VoucherEntry;
use App\Services\Accounting\Books;
use App\Services\Media\ImageStore;
use App\Services\Metal\Ledger;
use App\Services\Metal\LedgerException;
use App\Services\Metal\MetalOperations;
use App\Services\Metal\Statements;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The kaarigar's own data only (blueprint §6: assigned jobs, instructions,
 * acknowledgements and permitted progress updates).
 *
 * Returns to the store are requests the store weighs and approves. The one thing
 * a kaarigar posts himself is a handoff to another kaarigar (head → sub-kaarigar
 * or back to the head): it moves custody between two kaarigars, never factory
 * stock, and the receiving kaarigar must accept it.
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
            'is_head' => $kaarigar->isHead(),
            'head' => $kaarigar->head?->only(['id', 'name']),
        ]);
    }

    /** Gold given to the kaarigar (by the store or by another kaarigar) that he has not yet accepted or disputed. */
    public function issues(Request $request): JsonResponse
    {
        $kaarigar = $this->me($request)->kaarigar;

        $issues = $this->pendingIssues($kaarigar->id)
            ->with(['lines.karat:id,label', 'lines.from:id,type,name', 'jobCard.process:id,name', 'jobCard.design.images'])
            ->latest('id')->get()
            ->map(fn (MetalTransaction $tx) => [
                'id' => $tx->id,
                'voucher_no' => $tx->voucher_no,
                'type' => $tx->type,
                'date' => $tx->date,
                // For a handoff: the kaarigar it came from.
                'from' => $tx->type === MetalTransaction::HANDOFF ? $tx->lines->first()?->from?->name : null,
                'job_card' => $tx->jobCard?->only(['id', 'card_no', 'description', 'due_date', 'pieces']),
                'process' => $tx->jobCard?->process?->name,
                'design_photo' => $tx->jobCard?->design?->images->first()?->thumb_url,
                'remarks' => $tx->remarks,
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

        $jobs = JobCard::with(['process:id,name', 'karat:id,label', 'design.images', 'parent.kaarigar:id,name', 'children.kaarigar:id,name', 'attachments'])
            ->where('kaarigar_id', $kaarigar->id)->where('status', 'open')
            ->orderByRaw('due_date is null')->orderBy('due_date')->get()
            ->map(fn (JobCard $c) => [
                'id' => $c->id, 'card_no' => $c->card_no, 'description' => $c->description,
                'process' => $c->process->name, 'karat' => $c->karat->label, 'pieces' => $c->pieces,
                'due_date' => $c->due_date?->toDateString(), 'kaarigar_status' => $c->kaarigar_status,
                'started_at' => $c->started_at?->toIso8601String(), 'ready_at' => $c->ready_at?->toIso8601String(),
                'note' => $c->kaarigar_note,
                'photos' => $c->attachments->where('kind', 'progress_photo')->values()->map(fn ($a) => $a->thumb_url ?? $a->url),
                'design' => $c->design ? ['code' => $c->design->code, 'name' => $c->design->name, 'photo' => $c->design->images->first()?->thumb_url] : null,
                'outstanding_fine' => $this->ops->outstanding($this->ops->cardFigures($c)),
                'return_pending' => KaarigarRequest::where('job_card_id', $c->id)->where('type', 'return')->where('status', 'pending')->exists(),
                // Work that came from a head kaarigar can be handed back to him.
                'from_kaarigar' => $c->parent?->kaarigar?->name,
                'can_hand_back' => (bool) $c->parent?->isOpen(),
                'given_to' => $c->children->where('status', JobCard::OPEN)->map(fn ($ch) => ['card_no' => $ch->card_no, 'kaarigar' => $ch->kaarigar->name])->values(),
            ]);

        return response()->json(['data' => $jobs]);
    }

    /**
     * Progress update for the stage: started / ready, a note, and photos
     * (blueprint §2: start and completion times, remarks, attachments).
     */
    public function setJobStatus(Request $request, JobCard $jobCard, ImageStore $images): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['started', 'ready'])],
            'note' => ['nullable', 'string', 'max:500'],
            'photos' => ['nullable', 'array', 'max:5'],
            'photos.*' => ['image', 'max:8192'],
        ]);
        $me = $this->me($request);
        abort_unless($jobCard->kaarigar_id === $me->kaarigar_id && $jobCard->isOpen(), 404);

        DB::transaction(function () use ($request, $jobCard, $data, $me, $images) {
            $jobCard->kaarigar_status = $data['status'];
            if ($data['status'] === 'started' && ! $jobCard->started_at) {
                $jobCard->started_at = now();
            }
            if ($data['status'] === 'ready') {
                $jobCard->started_at ??= now();
                $jobCard->ready_at = now();
            }
            if (! empty($data['note'])) {
                $jobCard->kaarigar_note = $data['note'];
            }
            $jobCard->save();

            foreach ($request->file('photos', []) as $file) {
                $paths = $images->store($file, $me->tenant_id, "job-cards/{$jobCard->id}");
                $jobCard->attachments()->create($paths + ['kind' => 'progress_photo']);
            }
        });

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
            'pieces' => ['nullable', 'integer', 'min:0'],
            'raw_gross' => ['nullable', 'decimal:0,3', 'min:0'],
            'scrap_gross' => ['nullable', 'decimal:0,3', 'min:0'],
            'dust_gross' => ['nullable', 'decimal:0,3', 'min:0'],
            'rejected_gross' => ['nullable', 'decimal:0,3', 'min:0'],
            'rejected_pieces' => ['nullable', 'integer', 'min:0'],
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
                'data' => collect($data)->only(['work_gross', 'stone', 'pieces', 'raw_gross', 'scrap_gross', 'dust_gross', 'rejected_gross', 'rejected_pieces', 'note'])
                    ->filter(fn ($v) => $v !== null)->all(),
            ]);

            foreach (['work_photos' => 'work_photo', 'scale_photos' => 'scale_photo'] as $field => $kind) {
                foreach ($request->file($field, []) as $file) {
                    $paths = $images->store($file, $me->tenant_id, "requests/{$req->id}");
                    $req->attachments()->create($paths + ['kind' => $kind]);
                }
            }

            $jobCard->forceFill(['kaarigar_status' => 'ready', 'ready_at' => now()])->save();

            return $req;
        });

        return response()->json(['data' => $req->load('attachments')], 201);
    }

    /** Head kaarigar: his sub-kaarigars, the gold each holds and their open work. */
    public function team(Request $request): JsonResponse
    {
        $kaarigar = $this->me($request)->kaarigar;
        $subs = $kaarigar->subKaarigars()->where('is_active', true)->orderBy('name')->get();

        return response()->json([
            'data' => $subs->map(fn (Kaarigar $k) => [
                'id' => $k->id, 'name' => $k->name, 'phone' => $k->phone,
                'gold' => $this->ledger->balance($this->ledger->kaarigar($k)),
                'open_jobs' => JobCard::with('process:id,name')->where('kaarigar_id', $k->id)->where('status', 'open')->get()
                    ->map(fn (JobCard $c) => ['card_no' => $c->card_no, 'process' => $c->process->name, 'kaarigar_status' => $c->kaarigar_status, 'due_date' => $c->due_date?->toDateString()]),
            ]),
            'processes' => Process::where('is_active', true)->orderBy('sequence')->get(['id', 'name']),
        ]);
    }

    /** Head kaarigar passes part of a job (work and gold) to one of his sub-kaarigars. */
    public function handoff(Request $request, JobCard $jobCard): JsonResponse
    {
        $me = $this->me($request);
        abort_unless($jobCard->kaarigar_id === $me->kaarigar_id && $jobCard->isOpen(), 404);

        $data = $request->validate($this->weightRules() + [
            'kaarigar_id' => ['required', 'integer'],
            'process_id' => ['nullable', 'integer'],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        $sub = Kaarigar::where('id', $data['kaarigar_id'])->where('head_kaarigar_id', $me->kaarigar_id)->where('is_active', true)->first()
            ?? throw new LedgerException('You can only hand work to the kaarigars under you.');
        $process = isset($data['process_id']) ? Process::findOrFail($data['process_id']) : null;

        $tx = $this->ops->allocate($jobCard, $sub,
            ['process_id' => $process?->id, 'description' => $data['note'] ?? null, 'pieces' => $data['pieces'] ?? null, 'due_date' => $data['due_date'] ?? null],
            [['gross' => (string) $data['gross'], 'pieces' => $data['pieces'] ?? null]],
            $this->appHeader($me, $data),
        );

        return response()->json(['data' => ['voucher_no' => $tx->voucher_no, 'job_card' => $tx->jobCard?->card_no]], 201);
    }

    /** Sub-kaarigar hands the work (and gold) back to the head kaarigar's card it came from. */
    public function handBack(Request $request, JobCard $jobCard): JsonResponse
    {
        $me = $this->me($request);
        abort_unless($jobCard->kaarigar_id === $me->kaarigar_id && $jobCard->isOpen(), 404);

        if (! $jobCard->parent) {
            throw new LedgerException('This job came from the store. Use "Return work" to give it back.');
        }

        $data = $request->validate($this->weightRules());

        $tx = $this->ops->handoff($jobCard, $jobCard->parent,
            [['gross' => (string) $data['gross'], 'pieces' => $data['pieces'] ?? null]],
            $this->appHeader($me, $data),
        );

        return response()->json(['data' => ['voucher_no' => $tx->voucher_no]], 201);
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

    /** Labour (making charges) earned and payments, and what the store owes him now. */
    public function earnings(Request $request, Books $books): JsonResponse
    {
        $kaarigar = $this->me($request)->kaarigar;
        $party = $books->party($kaarigar);
        $from = today()->subDays(60)->toDateString();

        $rows = VoucherEntry::with('voucher:id,voucher_no,type,date,narration,status')
            ->where('party_id', $party->id)
            ->whereHas('voucher', fn ($q) => $q->where('date', '>=', $from))
            ->get()
            ->sortByDesc(fn ($e) => [$e->voucher->date, $e->voucher_id])
            ->map(fn ($e) => [
                'date' => $e->voucher->date, 'voucher_no' => $e->voucher->voucher_no, 'type' => $e->voucher->type,
                'narration' => $e->voucher->narration,
                // From the kaarigar's side: credit = earned, debit = paid / deducted.
                'earned' => Money::isZero($e->credit) ? null : $e->credit,
                'paid' => Money::isZero($e->debit) ? null : $e->debit,
            ])->values();

        $balance = $books->partyBalance($party)['money'];

        return response()->json([
            'balance_due' => Money::sub('0', $balance),   // positive = store owes the kaarigar
            'this_week' => $this->labourSince($kaarigar, today()->subDays(6)->toDateString()),
            'rows' => $rows,
        ]);
    }

    private function weightRules(): array
    {
        return [
            'gross' => ['required', 'decimal:0,3', 'gt:0'],
            'pieces' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function appHeader(KaarigarUser $me, array $data): array
    {
        return array_filter([
            'posted_by_kaarigar_user_id' => $me->id,
            'remarks' => trim('From the kaarigar app'.(! empty($data['note']) ? ': '.$data['note'] : '')),
        ]);
    }

    private function labourSince(Kaarigar $kaarigar, string $from): string
    {
        $party = Party::where('ref_type', $kaarigar->getMorphClass())->where('ref_id', $kaarigar->id)->first();
        if (! $party) {
            return '0.00';
        }

        $sum = VoucherEntry::where('party_id', $party->id)
            ->whereHas('voucher', fn ($q) => $q->where('type', 'labour')->where('status', 'posted')->where('date', '>=', $from))
            ->sum('credit');

        return Money::fromDb($sum);
    }

    private function pendingIssues(int $kaarigarId)
    {
        return MetalTransaction::whereIn('type', [MetalTransaction::ISSUE, MetalTransaction::HANDOFF])->where('kaarigar_id', $kaarigarId)
            ->whereNull('kaarigar_ack')->whereNull('reversed_by_id');
    }

    private function ownIssue(Request $request, MetalTransaction $tx): void
    {
        abort_unless(
            $tx->needsAck() && $tx->kaarigar_id === $this->me($request)->kaarigar_id && $tx->kaarigar_ack === null,
            404,
        );
    }
}
