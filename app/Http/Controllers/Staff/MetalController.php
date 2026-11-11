<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\JobCard;
use App\Models\Kaarigar;
use App\Models\KaarigarRequest;
use App\Models\Karat;
use App\Models\MetalLocation;
use App\Models\MetalTransaction;
use App\Models\Process;
use App\Models\Retailer;
use App\Models\WorkOrder;
use App\Models\WoStep;
use App\Services\Metal\IssueRules;
use App\Services\Metal\Ledger;
use App\Services\Metal\LedgerException;
use App\Services\Metal\MetalOperations;
use App\Services\Production\Production;
use App\Support\Metal\Weight;
use App\Support\Permissions;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRules;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Gold ledger endpoints (Module 1). Business rules live in MetalOperations / Ledger. */
class MetalController extends Controller
{
    public function __construct(private Ledger $ledger, private MetalOperations $ops) {}

    // ── Postings ─────────────────────────────────────────────────────────────

    public function purchase(Request $request): JsonResponse
    {
        $data = $request->validate($this->headerRules() + $this->itemRules('items') + [
            'opening' => ['boolean'],
            'party_name' => ['nullable', 'string', 'max:150'],
        ]);

        $tx = $this->ops->purchase($data['items'], $this->header($data, ['party_name']), $data['opening'] ?? false);

        return $this->posted($tx);
    }

    public function deposit(Request $request): JsonResponse
    {
        $data = $request->validate($this->headerRules() + $this->itemRules('items') + [
            'retailer_id' => ['required', TenantRules::exists('retailers')],
        ]);

        $tx = $this->ops->deposit(Retailer::findOrFail($data['retailer_id']), $data['items'], $this->header($data));

        return $this->posted($tx);
    }

    /** Issue against an existing job card, or open one on the fly. */
    public function issue(Request $request): JsonResponse
    {
        $data = $request->validate($this->headerRules() + $this->itemRules('items') + [
            'source' => ['required', Rule::in(['vault', 'finished', 'wo'])],
            'job_card_id' => ['nullable', TenantRules::exists('job_cards')],
            'kaarigar_id' => ['required_without:job_card_id', TenantRules::exists('kaarigars')],
            'process_id' => ['required_without_all:job_card_id,work_order_id', TenantRules::exists('processes')],
            'karat_id' => ['required_without_all:job_card_id,work_order_id', TenantRules::exists('karats')],
            'design_id' => ['nullable', TenantRules::exists('designs')],
            'description' => ['nullable', 'string', 'max:255'],
            'pieces' => ['nullable', 'integer', 'min:1'],
            'due_date' => ['nullable', 'date'],
            'work_order_id' => ['nullable', TenantRules::exists('work_orders')],
            'override_reason' => ['nullable', 'string', 'max:255'],
        ]);

        // Spec 16.3: every issue belongs to a work order (unless the workspace allows loose job cards).
        if (isset($data['work_order_id']) && ! isset($data['job_card_id'])) {
            $wo = WorkOrder::findOrFail($data['work_order_id']);
            $step = $wo->steps()->whereIn('status', [WoStep::PENDING, WoStep::IN_PROGRESS])->orderBy('sequence')->first()
                ?? throw new LedgerException("{$wo->wo_no} has no stage left to issue for.");
            $tx = app(Production::class)->issueForStep($step, (int) $data['kaarigar_id'], $data['items'], $this->header($data, ['override_reason']));

            return $this->posted($tx);
        }

        $tx = DB::transaction(function () use ($data) {
            $card = isset($data['job_card_id'])
                ? JobCard::findOrFail($data['job_card_id'])
                : $this->ops->openJobCard(Arr::only($data, ['kaarigar_id', 'process_id', 'karat_id', 'design_id', 'description', 'pieces', 'due_date', 'date']));

            if (! $card->kaarigar->is_active) {
                throw new LedgerException("{$card->kaarigar->name} is inactive.");
            }
            if (! $card->wo_step_id && $this->requireWorkOrder()) {
                throw new LedgerException('Pick the work order this gold is for. (Loose job cards are turned off in Settings → Gold rules.)');
            }

            return $this->ops->issue($card, $data['items'], $data['source'], $this->header($data, ['override_reason']));
        });

        return $this->posted($tx);
    }

    private function requireWorkOrder(): bool
    {
        return (bool) (app(TenantContext::class)->get()->settings['require_work_order'] ?? true);
    }

    /** Can this kaarigar take more gold? Shown on the issue screen before saving. */
    public function issueCheck(Request $request): JsonResponse
    {
        $data = $request->validate(['kaarigar_id' => ['required', TenantRules::exists('kaarigars')]]);
        $kaarigar = Kaarigar::findOrFail($data['kaarigar_id']);

        return response()->json([
            'blockers' => app(IssueRules::class)->blockers($kaarigar),
            'can_override' => $request->user('staff')->can(Permissions::METAL_APPROVE_LOSS),
            'held' => app(Ledger::class)->balance($this->ops->kaarigarLocation($kaarigar))['fine'],
            'limit' => $kaarigar->max_fine_balance,
        ]);
    }

    /** Staff record the kaarigar's signature on the printed issue slip (no app). */
    public function acknowledge(MetalTransaction $transaction): JsonResponse
    {
        if (! $transaction->needsAck() || $transaction->isReversed()) {
            throw new LedgerException('Only live gold issues and handoffs can be acknowledged.');
        }
        if ($transaction->kaarigar_ack === 'accepted') {
            return response()->json(['data' => $this->present($transaction)]);
        }

        $transaction->forceFill(['kaarigar_ack' => 'accepted', 'kaarigar_ack_at' => now()])->save();
        activity('gold-issue')->performedOn($transaction)->causedBy(request()->user('staff'))->log("Signed slip recorded for {$transaction->voucher_no}");

        return response()->json(['data' => $this->present($transaction->load(['lines.from', 'lines.to', 'lines.karat', 'kaarigar', 'retailer', 'jobCard']))]);
    }

    /**
     * Return voucher: finished work, raw gold, semi-finished or rejected pieces,
     * scrap and dust, each as its own line (blueprint §3).
     */
    public function receive(Request $request): JsonResponse
    {
        $part = fn (string $k, bool $stone = true) => [
            $k => ['nullable', 'array'],
            "{$k}.gross" => ["required_with:{$k}", 'decimal:0,3', 'gt:0'],
            "{$k}.purity" => ['nullable', 'decimal:0,3', 'gt:0', 'max:100'],
            "{$k}.pieces" => ['nullable', 'integer', 'min:0'],
        ] + ($stone ? ["{$k}.stone" => ['nullable', 'decimal:0,3', 'min:0'], "{$k}.findings" => ['nullable', 'decimal:0,3', 'min:0']] : []);

        $data = $request->validate($this->headerRules() + [
            'job_card_id' => ['required', TenantRules::exists('job_cards')],
            'destination' => ['required', Rule::in(['vault', 'finished', 'wo'])],
            'close' => ['boolean'],
        ] + $part('work') + $part('semi') + $part('rejected') + $part('raw', false) + $part('scrap', false) + $part('dust', false));

        $card = JobCard::findOrFail($data['job_card_id']);
        $parts = Arr::only($data, [...MetalOperations::RETURN_KINDS, ...MetalOperations::SCRAP_KINDS]);

        [$tx, $closed] = DB::transaction(function () use ($card, $data, $parts) {
            $tx = $this->ops->receiveParts($card, $parts, $data['destination'], $this->header($data));
            $closed = ($data['close'] ?? false) ? $this->ops->closeJobCard($card, $data['date'] ?? null) : null;

            return [$tx, $closed];
        });

        return response()->json([
            'data' => $this->present($tx),
            'job_card' => $closed ? $this->presentCard($closed) : null,
            // The receive screen shows the difference and that it waits for approval.
            'alert' => $closed ? JobCardController::lossAlert($closed->load('kaarigar')) : null,
        ], 201);
    }

    public function melt(Request $request): JsonResponse
    {
        $data = $request->validate($this->headerRules() + $this->itemRules('inputs') + [
            'alloy_gross' => ['nullable', 'decimal:0,3', 'min:0'],
            'output' => ['required', 'array'],
            'output.karat_id' => ['required', TenantRules::exists('karats')],
            'output.gross' => ['required', 'decimal:0,3', 'gt:0'],
            'output.purity' => ['nullable', 'decimal:0,3', 'gt:0', 'max:100'],
            'process_id' => ['nullable', TenantRules::exists('processes')],
        ]);

        $process = isset($data['process_id']) ? Process::find($data['process_id']) : Process::where('name', 'Melting')->first();
        $tx = $this->ops->melt($data['inputs'], (string) ($data['alloy_gross'] ?? '0'), $data['output'], $process, $this->header($data));

        return $this->posted($tx);
    }

    public function refine(Request $request): JsonResponse
    {
        $data = $request->validate($this->headerRules() + $this->itemRules('sent') + [
            'returned' => ['required', 'array'],
            'returned.karat_id' => ['required', TenantRules::exists('karats')],
            'returned.gross' => ['required', 'decimal:0,3', 'min:0'],
            'returned.purity' => ['nullable', 'decimal:0,3', 'gt:0', 'max:100'],
            'party_name' => ['nullable', 'string', 'max:150'],
        ]);

        return $this->posted($this->ops->refine($data['sent'], $data['returned'], $this->header($data, ['party_name'])));
    }

    public function transfer(Request $request): JsonResponse
    {
        $data = $request->validate($this->headerRules() + $this->itemRules('items') + [
            'from' => ['required', Rule::in(['vault', 'finished', 'scrap'])],
            'to' => ['required', Rule::in(['vault', 'finished', 'scrap']), 'different:from'],
        ]);

        return $this->posted($this->ops->transfer($data['from'], $data['to'], $data['items'], $this->header($data)));
    }

    public function reverse(Request $request, MetalTransaction $transaction): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->posted($this->ledger->reverse($transaction, $data['reason']));
    }

    // ── Reading ──────────────────────────────────────────────────────────────

    public function transactions(Request $request): JsonResponse
    {
        $rows = MetalTransaction::query()
            ->with(['lines.from:id,type,name', 'lines.to:id,type,name', 'lines.karat:id,label', 'kaarigar:id,name', 'retailer:id,shop_name', 'jobCard:id,card_no', 'fromJobCard:id,card_no', 'creator:id,name', 'postedByKaarigarUser:id,name'])
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('kaarigar_id'), fn ($q) => $q->where('kaarigar_id', $request->integer('kaarigar_id')))
            ->when($request->filled('job_card_id'), fn ($q) => $q->where(fn ($q) => $q->where('job_card_id', $request->integer('job_card_id'))->orWhere('from_job_card_id', $request->integer('job_card_id'))))
            ->when($request->filled('from'), fn ($q) => $q->where('date', '>=', $request->date('from')->toDateString()))
            ->when($request->filled('to'), fn ($q) => $q->where('date', '<=', $request->date('to')->toDateString()))
            ->when($request->boolean('unconfirmed'), fn ($q) => $q->whereIn('type', [MetalTransaction::ISSUE, MetalTransaction::HANDOFF])->whereNull('kaarigar_ack')->whereNull('reversed_by_id'))
            ->latest('id')
            ->paginate($request->integer('per_page', 50));

        $rows->getCollection()->transform(fn ($tx) => $this->present($tx));

        return response()->json($rows);
    }

    public function show(MetalTransaction $transaction): JsonResponse
    {
        return response()->json(['data' => $this->present($transaction->load(['lines.from', 'lines.to', 'lines.karat', 'kaarigar', 'retailer', 'jobCard', 'fromJobCard', 'creator', 'postedByKaarigarUser']))]);
    }

    /** Stock per karat at a stock location (vault, scrap, finished). */
    public function stock(Request $request): JsonResponse
    {
        $type = $request->validate(['location' => ['required', Rule::in(['vault', 'scrap', 'finished'])]])['location'];
        $location = $this->ledger->of($type === 'vault' ? MetalLocation::VAULT : $type);
        $karats = Karat::pluck('label', 'id');

        $rows = collect($this->ledger->balanceRows([$location->id]))
            ->filter(fn ($r) => Weight::compare($r['fine'], '0') !== 0 || Weight::compare($r['gross'], '0') !== 0)
            ->map(fn ($r) => ['karat_id' => $r['karat_id'], 'karat' => $karats[$r['karat_id']] ?? '?', 'gross' => $r['gross'], 'fine' => $r['fine']])
            ->values();

        return response()->json(['location' => $location->only(['id', 'type', 'name']), 'data' => $rows, 'total_fine' => Weight::add('0', ...$rows->pluck('fine')->all())]);
    }

    /** Live balances for every location of a type (e.g. all kaarigars). */
    public function balances(Request $request): JsonResponse
    {
        $type = $request->validate(['location_type' => ['required', Rule::in([MetalLocation::KAARIGAR, MetalLocation::VAULT, MetalLocation::SCRAP, MetalLocation::FINISHED, MetalLocation::LOSS])]])['location_type'];

        $locations = MetalLocation::where('type', $type)->get()->keyBy('id');
        $sums = [];
        foreach ($this->ledger->balanceRows($locations->keys()->all()) as $r) {
            $sums[$r['location_id']]['fine'] = Weight::add($sums[$r['location_id']]['fine'] ?? '0', $r['fine']);
            $sums[$r['location_id']]['gross'] = Weight::add($sums[$r['location_id']]['gross'] ?? '0', $r['gross']);
        }

        $rows = $locations->map(fn (MetalLocation $l) => [
            'location_id' => $l->id, 'name' => $l->name, 'ref_id' => $l->ref_id,
            'fine' => $sums[$l->id]['fine'] ?? '0.000', 'gross' => $sums[$l->id]['gross'] ?? '0.000',
        ])->values();

        return response()->json(['data' => $rows]);
    }

    /** Owner dashboard: where the gold is, today's movements and alerts. */
    public function summary(): JsonResponse
    {
        $totals = $this->ledger->totalsByType();
        $today = today()->toDateString();

        $todays = MetalTransaction::where('date', $today)->whereIn('type', [MetalTransaction::ISSUE, MetalTransaction::RECEIVE])
            ->withSum('lines', 'fine_wt')->get()->groupBy('type');

        $unconfirmedHours = 24;

        return response()->json([
            'gold' => collect([...MetalLocation::HELD, MetalLocation::LOSS])->mapWithKeys(fn ($t) => [$t => $totals[$t] ?? '0.000']),
            'today' => collect([MetalTransaction::ISSUE, MetalTransaction::RECEIVE])->mapWithKeys(fn ($t) => [$t => [
                'count' => $todays->get($t)?->count() ?? 0,
                'fine' => Weight::add('0', ...($todays->get($t)?->map(fn ($tx) => Weight::fromDb($tx->lines_sum_fine_wt))->all() ?? [])),
            ]]),
            'overdue_job_cards' => JobCard::where('status', JobCard::OPEN)->whereNotNull('due_date')->where('due_date', '<', $today)->count(),
            'open_job_cards' => JobCard::where('status', JobCard::OPEN)->count(),
            'wastage_alerts' => JobCard::where('loss_status', JobCard::LOSS_PENDING)->count(),
            'pending_loss_fine' => Weight::fromDb((string) JobCard::where('loss_status', JobCard::LOSS_PENDING)->sum('loss_fine')),
            'unconfirmed_issues' => MetalTransaction::whereIn('type', [MetalTransaction::ISSUE, MetalTransaction::HANDOFF])->whereNull('kaarigar_ack')->whereNull('reversed_by_id')
                ->where('created_at', '<', now()->subHours($unconfirmedHours))->count(),
            'disputed_issues' => MetalTransaction::where('kaarigar_ack', 'disputed')->whereNull('reversed_by_id')->count(),
            'pending_requests' => KaarigarRequest::where('status', 'pending')->count(),
        ]);
    }

    /** Printable issue / receive slip with signature lines. */
    public function slip(MetalTransaction $transaction): Response
    {
        $transaction->load(['lines.from', 'lines.to', 'lines.karat', 'kaarigar', 'retailer', 'jobCard.process', 'creator']);
        $tenant = app(TenantContext::class)->get();

        $pdf = Pdf::loadView('pdf.metal-slip', ['tx' => $transaction, 'tenant' => $tenant])->setPaper('a5');

        return $pdf->stream(str_replace('/', '-', $transaction->voucher_no).'.pdf');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function headerRules(): array
    {
        return [
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:100'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    private function itemRules(string $key): array
    {
        return [
            $key => ['required', 'array', 'min:1', 'max:50'],
            "{$key}.*.karat_id" => ['required', TenantRules::exists('karats')],
            "{$key}.*.gross" => ['required', 'decimal:0,3', 'gt:0'],
            "{$key}.*.stone" => ['nullable', 'decimal:0,3', 'min:0'],
            "{$key}.*.findings" => ['nullable', 'decimal:0,3', 'min:0'],
            "{$key}.*.purity" => ['nullable', 'decimal:0,3', 'gt:0', 'max:100'],
            "{$key}.*.pieces" => ['nullable', 'integer', 'min:0'],
        ];
    }

    private function header(array $data, array $extra = []): array
    {
        return array_filter(Arr::only($data, ['date', 'reference', 'remarks', ...$extra]), fn ($v) => $v !== null);
    }

    private function posted(MetalTransaction $tx): JsonResponse
    {
        return response()->json(['data' => $this->present($tx->load(['lines.from', 'lines.to', 'lines.karat', 'kaarigar', 'retailer', 'jobCard', 'fromJobCard']))], 201);
    }

    public static function present(MetalTransaction $tx): array
    {
        return [
            'id' => $tx->id,
            'voucher_no' => $tx->voucher_no,
            'type' => $tx->type,
            'date' => $tx->date,
            'kaarigar' => $tx->kaarigar?->only(['id', 'name']),
            'retailer' => $tx->retailer?->only(['id', 'shop_name']),
            'job_card' => $tx->jobCard?->only(['id', 'card_no']),
            'from_job_card' => $tx->relationLoaded('fromJobCard') ? $tx->fromJobCard?->only(['id', 'card_no']) : null,
            'party_name' => $tx->party_name,
            'reference' => $tx->reference,
            'remarks' => $tx->remarks,
            'over_limit' => $tx->over_limit,
            'reversed_by_id' => $tx->reversed_by_id,
            'reverses_id' => $tx->reverses_id,
            'kaarigar_ack' => $tx->kaarigar_ack,
            // Handoffs posted from the kaarigar app name the kaarigar, not a staff user.
            'created_by' => $tx->relationLoaded('postedByKaarigarUser') && $tx->postedByKaarigarUser
                ? $tx->postedByKaarigarUser->name.' (kaarigar app)'
                : ($tx->relationLoaded('creator') ? $tx->creator?->name : null),
            'created_at' => $tx->created_at?->toIso8601String(),
            'fine_total' => Weight::add('0', ...$tx->lines->whereNotIn('kind', ['loss', 'alloy'])
                ->filter(fn ($l) => $l->relationLoaded('from') ? ! in_array($l->from?->type, [MetalLocation::MELT, MetalLocation::REFINER], true) : true)
                ->pluck('fine_wt')->all()),
            'lines' => $tx->lines->map(fn ($l) => [
                'from' => $l->relationLoaded('from') ? $l->from?->only(['id', 'type', 'name']) : null,
                'to' => $l->relationLoaded('to') ? $l->to?->only(['id', 'type', 'name']) : null,
                'karat' => $l->relationLoaded('karat') ? $l->karat?->label : null,
                'kind' => $l->kind,
                'gross_wt' => $l->gross_wt, 'stone_wt' => $l->stone_wt, 'findings_wt' => $l->findings_wt,
                'net_wt' => $l->net_wt, 'purity_pct' => $l->purity_pct, 'fine_wt' => $l->fine_wt, 'pieces' => $l->pieces,
            ])->values(),
        ];
    }

    public static function presentCard(JobCard $card, ?array $live = null): array
    {
        return $card->only([
            'id', 'card_no', 'status', 'kaarigar_status', 'description', 'pieces', 'due_date', 'wo_step_id', 'parent_job_card_id',
            'issued_fine', 'handed_in_fine', 'returned_fine', 'scrap_fine', 'handed_out_fine', 'loss_fine', 'allowed_loss_fine', 'excess_loss_fine',
            'allowed_wastage_pct', 'excess_resolution', 'loss_status', 'loss_reason_code', 'loss_note', 'started_at', 'ready_at',
            'closed_at', 'created_at',
        ]) + [
            'parent' => $card->relationLoaded('parent') && $card->parent
                ? ['id' => $card->parent->id, 'card_no' => $card->parent->card_no, 'kaarigar' => $card->parent->relationLoaded('kaarigar') ? $card->parent->kaarigar?->only(['id', 'name']) : null]
                : null,
            'kaarigar' => $card->kaarigar?->only(['id', 'name']),
            'process' => $card->process?->only(['id', 'name']),
            'karat' => $card->karat?->only(['id', 'label', 'purity_pct']),
            'design' => $card->design?->only(['id', 'code', 'name']),
            'live' => $live,
        ];
    }
}
