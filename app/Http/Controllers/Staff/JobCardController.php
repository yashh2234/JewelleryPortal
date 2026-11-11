<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\JobCard;
use App\Models\Kaarigar;
use App\Models\MetalTransaction;
use App\Services\Media\ImageStore;
use App\Services\Metal\LedgerException;
use App\Services\Metal\MetalOperations;
use App\Support\Metal\Weight;
use App\Support\Tenancy\TenantRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class JobCardController extends Controller
{
    public function __construct(private MetalOperations $ops) {}

    public function index(Request $request): JsonResponse
    {
        $cards = JobCard::query()
            ->with(['kaarigar:id,name', 'process:id,name', 'karat:id,label,purity_pct', 'design:id,code,name', 'parent:id,card_no,kaarigar_id', 'parent.kaarigar:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('kaarigar_id'), fn ($q) => $q->where('kaarigar_id', $request->integer('kaarigar_id')))
            ->when($request->boolean('alerts'), fn ($q) => $q->where('loss_status', JobCard::LOSS_PENDING))
            ->when($request->boolean('overdue'), fn ($q) => $q->where('status', 'open')->where('due_date', '<', today()->toDateString()))
            ->when($request->filled('search'), fn ($q) => $q->where('card_no', 'like', '%'.$request->string('search').'%'))
            ->latest('id')
            ->paginate($request->integer('per_page', 50));

        $cards->getCollection()->transform(fn (JobCard $c) => MetalController::presentCard(
            $c, $c->isOpen() ? $this->live($c) : null,
        ));

        return response()->json($cards);
    }

    public function show(JobCard $jobCard): JsonResponse
    {
        $jobCard->load([
            'kaarigar', 'process', 'karat', 'design', 'attachments', 'lossApprover:id,name',
            'parent:id,card_no,kaarigar_id', 'parent.kaarigar:id,name',
            'children.kaarigar:id,name', 'children.process:id,name',
        ]);

        // Gold onto this card and handoffs out of it.
        $transactions = MetalTransaction::with(['lines.from', 'lines.to', 'lines.karat', 'kaarigar', 'retailer', 'jobCard', 'fromJobCard', 'creator', 'postedByKaarigarUser'])
            ->where(fn ($q) => $q->where('job_card_id', $jobCard->id)->orWhere('from_job_card_id', $jobCard->id))
            ->orderBy('id')->get();

        return response()->json([
            'data' => MetalController::presentCard($jobCard, $this->live($jobCard)) + [
                'started_at' => $jobCard->started_at, 'ready_at' => $jobCard->ready_at, 'kaarigar_note' => $jobCard->kaarigar_note,
                'loss_approved_by' => $jobCard->lossApprover?->name, 'loss_approved_at' => $jobCard->loss_approved_at,
                'attachments' => $jobCard->attachments,
                'children' => $jobCard->children->map(fn (JobCard $c) => [
                    'id' => $c->id, 'card_no' => $c->card_no, 'status' => $c->status,
                    'kaarigar' => $c->kaarigar?->only(['id', 'name']), 'process' => $c->process?->only(['id', 'name']),
                    'outstanding' => $c->isOpen() ? $this->ops->outstanding($this->ops->cardFigures($c)) : null,
                ]),
            ],
            'transactions' => $transactions->map(fn ($tx) => MetalController::present($tx)),
            'loss_reasons' => JobCard::LOSS_REASONS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kaarigar_id' => ['required', TenantRules::exists('kaarigars')],
            'process_id' => ['required', TenantRules::exists('processes')],
            'karat_id' => ['required', TenantRules::exists('karats')],
            'design_id' => ['nullable', TenantRules::exists('designs')],
            'description' => ['nullable', 'string', 'max:255'],
            'pieces' => ['nullable', 'integer', 'min:1'],
            'due_date' => ['nullable', 'date'],
        ]);

        $card = $this->ops->openJobCard($data);

        return response()->json(['data' => MetalController::presentCard($card->load(['kaarigar', 'process', 'karat', 'design']))], 201);
    }

    /**
     * Pass work and gold from this card to another kaarigar (head → sub, or between
     * stage kaarigars). Opens a child card for the receiver; custody moves, nothing
     * new leaves the vault.
     */
    public function allocate(Request $request, JobCard $jobCard): JsonResponse
    {
        $data = $request->validate($this->handoffRules() + [
            'kaarigar_id' => ['required', TenantRules::exists('kaarigars')],
            'process_id' => ['nullable', TenantRules::exists('processes')],
            'description' => ['nullable', 'string', 'max:255'],
            'due_date' => ['nullable', 'date'],
            'override_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $tx = $this->ops->allocate(
            $jobCard,
            Kaarigar::findOrFail($data['kaarigar_id']),
            Arr::only($data, ['process_id', 'description', 'pieces', 'due_date', 'date']),
            [Arr::only($data, ['gross', 'stone', 'purity', 'pieces'])],
            $this->header($data, ['override_reason']),
        );

        return response()->json(['data' => MetalController::present($tx->load(['lines.from', 'lines.to', 'lines.karat', 'kaarigar', 'retailer', 'jobCard', 'fromJobCard']))], 201);
    }

    /** Hand gold on a child card back to the card it came from. */
    public function handBack(Request $request, JobCard $jobCard): JsonResponse
    {
        $data = $request->validate($this->handoffRules());

        if (! $jobCard->parent) {
            throw new LedgerException("{$jobCard->card_no} was not handed over from another card; receive it into stock instead.");
        }

        $tx = $this->ops->handoff($jobCard, $jobCard->parent, [Arr::only($data, ['gross', 'stone', 'purity', 'pieces'])], $this->header($data));

        return response()->json(['data' => MetalController::present($tx->load(['lines.from', 'lines.to', 'lines.karat', 'kaarigar', 'retailer', 'jobCard', 'fromJobCard']))], 201);
    }

    /** Stage photos / documents from staff (blueprint §2: attachments per stage). */
    public function attach(Request $request, JobCard $jobCard, ImageStore $images): JsonResponse
    {
        $request->validate(['photos' => ['required', 'array', 'min:1', 'max:5'], 'photos.*' => ['image', 'max:8192']]);

        $created = collect($request->file('photos'))->map(function ($file) use ($jobCard, $images) {
            $paths = $images->store($file, $jobCard->tenant_id, "job-cards/{$jobCard->id}");

            return $jobCard->attachments()->create($paths + ['kind' => 'stage_photo']);
        });

        return response()->json(['data' => $created->values()], 201);
    }

    public function close(JobCard $jobCard): JsonResponse
    {
        $card = $this->ops->closeJobCard($jobCard)->load(['kaarigar', 'process', 'karat', 'design']);

        return response()->json([
            'data' => MetalController::presentCard($card),
            'alert' => self::lossAlert($card),
        ]);
    }

    /** Authorised approval of the difference with a reason code and a note. */
    public function approveLoss(Request $request, JobCard $jobCard): JsonResponse
    {
        $data = $request->validate([
            'reason_code' => ['required', Rule::in(array_keys(JobCard::LOSS_REASONS))],
            'note' => ['required', 'string', 'min:3', 'max:500'],
            'recover_excess' => ['boolean'],
        ]);

        $card = $this->ops->approveLoss($jobCard, $data['reason_code'], $data['note'], (bool) ($data['recover_excess'] ?? false));

        return response()->json(['data' => MetalController::presentCard($card->load(['kaarigar', 'process', 'karat', 'design']))]);
    }

    public static function lossAlert(JobCard $card): ?string
    {
        if (! $card->lossPending()) {
            return null;
        }

        return Weight::isPositive($card->excess_loss_fine)
            ? "Difference {$card->loss_fine} g is above the allowed {$card->allowed_wastage_pct}% by {$card->excess_loss_fine} g. It stays on {$card->kaarigar->name}'s balance until approved with a reason."
            : "Difference {$card->loss_fine} g (within the allowed {$card->allowed_wastage_pct}%) stays on {$card->kaarigar->name}'s balance until approved with a reason.";
    }

    private function handoffRules(): array
    {
        return [
            'gross' => ['required', 'decimal:0,3', 'gt:0'],
            'stone' => ['nullable', 'decimal:0,3', 'min:0'],
            'purity' => ['nullable', 'decimal:0,3', 'gt:0', 'max:100'],
            'pieces' => ['nullable', 'integer', 'min:0'],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    private function header(array $data, array $extra = []): array
    {
        return array_filter(Arr::only($data, ['date', 'remarks', ...$extra]), fn ($v) => $v !== null);
    }

    /** @return array<string, mixed> live figures plus what is still with the kaarigar */
    private function live(JobCard $card): array
    {
        $f = $this->ops->cardFigures($card);

        return $f + ['outstanding' => $this->ops->outstanding($f)];
    }
}
