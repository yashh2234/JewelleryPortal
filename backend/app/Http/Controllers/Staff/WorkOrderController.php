<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Design;
use App\Models\JobCard;
use App\Models\Process;
use App\Models\WoStep;
use App\Models\WorkOrder;
use App\Services\Metal\MetalOperations;
use App\Services\Production\Production;
use App\Support\Metal\Weight;
use App\Support\Tenancy\TenantRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkOrderController extends Controller
{
    public function __construct(private Production $production, private MetalOperations $ops) {}

    public function index(Request $request): JsonResponse
    {
        $wos = WorkOrder::query()
            ->with(['design:id,code,name', 'karat:id,label', 'steps.process:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', explode(',', $request->string('status'))))
            ->when($request->filled('search'), fn ($q) => $q->where('wo_no', 'like', '%'.$request->string('search').'%'))
            ->latest('id')
            ->paginate($request->integer('per_page', 50));

        $wos->getCollection()->transform(fn (WorkOrder $wo) => $this->summary($wo));

        return response()->json($wos);
    }

    public function show(WorkOrder $workOrder): JsonResponse
    {
        $workOrder->load([
            'design.images', 'karat', 'steps.process', 'steps.assignee:id,name', 'steps.jobCards.kaarigar', 'steps.jobCards.process', 'steps.jobCards.karat',
            'orderLines.order:id,order_no,retailer_id', 'orderLines.order.retailer:id,shop_name', 'finishedItems',
        ]);

        return response()->json([
            'data' => $this->summary($workOrder) + [
                'design' => $workOrder->design,
                'order_lines' => $workOrder->orderLines,
                'finished_items' => $workOrder->finishedItems,
                'steps' => $workOrder->steps->map(fn (WoStep $s) => [
                    'id' => $s->id, 'sequence' => $s->sequence, 'status' => $s->status,
                    'process' => $s->process->only(['id', 'name']),
                    'assignee' => $s->assignee?->only(['id', 'name']), 'remarks' => $s->remarks,
                    'started_at' => $s->started_at, 'done_at' => $s->done_at,
                    'job_cards' => $s->jobCards->map(fn (JobCard $c) => MetalController::presentCard($c, $c->isOpen() ? $this->live($c) : null)),
                ]),
            ],
        ]);
    }

    /** A work order without a retailer order (stock making). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'design_id' => ['required', TenantRules::exists('designs')],
            'karat_id' => ['required', TenantRules::exists('karats')],
            'qty' => ['required', 'integer', 'min:1'],
            'due_date' => ['nullable', 'date'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'remarks' => ['nullable', 'string', 'max:500'],
            'process_ids' => ['nullable', 'array', 'min:1'],
            'process_ids.*' => [TenantRules::exists('processes')],
        ]);

        $wo = $this->production->newWorkOrder(Design::findOrFail($data['design_id']), $data['karat_id'], $data['qty'], array_filter([
            'due_date' => $data['due_date'] ?? null, 'priority' => $data['priority'] ?? null, 'remarks' => $data['remarks'] ?? null,
        ]), $data['process_ids'] ?? null);

        return response()->json(['data' => $this->summary($wo->load(['design', 'karat', 'steps.process']))], 201);
    }

    /**
     * Kanban: columns are processes in shop order; each active WO sits in the column
     * of its current stage, with its kaarigar and the gold in hand.
     */
    public function board(): JsonResponse
    {
        $wos = WorkOrder::with(['design:id,code,name', 'karat:id,label', 'steps.process:id,name', 'steps.jobCards.kaarigar:id,name'])
            ->whereIn('status', [WorkOrder::OPEN, WorkOrder::IN_PROGRESS])
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
            ->orderBy('due_date')
            ->get();

        $columns = Process::where('is_active', true)->orderBy('sequence')->get(['id', 'name'])
            ->map(fn ($p) => ['process' => $p, 'cards' => []])->keyBy(fn ($c) => $c['process']->id)->all();
        $ready = [];

        foreach ($wos as $wo) {
            $step = $wo->currentStep();
            $open = $step ? $step->jobCards->where('status', JobCard::OPEN) : collect();
            $card = $this->summary($wo) + [
                'step' => $step ? ['id' => $step->id, 'status' => $step->status, 'sequence' => $step->sequence, 'open_cards' => $open->count(), 'cards' => $step->jobCards->count()] : null,
                'kaarigars' => $open->pluck('kaarigar.name')->unique()->values(),
                'with_kaarigar_fine' => Weight::add('0', ...$open->map(fn ($c) => $this->live($c)['outstanding'])->all()),
                'alert' => $wo->steps->flatMap->jobCards->contains(fn ($c) => Weight::isPositive($c->excess_loss_fine) && ! $c->excess_resolution),
            ];

            if ($step && isset($columns[$step->process_id])) {
                $columns[$step->process_id]['cards'][] = $card;
            } else {
                $ready[] = $card;   // all stages done: record finished pieces
            }
        }

        return response()->json(['columns' => array_values($columns), 'ready_to_finish' => $ready]);
    }

    /** Issue gold for a stage (opens a job card on the step). Weight defaults to what is in the WO. */
    public function issue(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $data = $request->validate([
            'step_id' => ['required', Rule::exists('wo_steps', 'id')->where('work_order_id', $workOrder->id)],
            'kaarigar_id' => ['required', TenantRules::exists('kaarigars')],
            'items' => ['required', 'array', 'min:1'],
            'items.*.karat_id' => ['required', TenantRules::exists('karats')],
            'items.*.gross' => ['required', 'decimal:0,3', 'gt:0'],
            'items.*.purity' => ['nullable', 'decimal:0,3', 'gt:0', 'max:100'],
            'items.*.pieces' => ['nullable', 'integer', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'override_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $tx = $this->production->issueForStep(WoStep::findOrFail($data['step_id']), $data['kaarigar_id'], $data['items'], array_filter([
            'remarks' => $data['remarks'] ?? null, 'override_reason' => $data['override_reason'] ?? null,
        ]));

        return response()->json(['data' => MetalController::present($tx->load(['lines.from', 'lines.to', 'lines.karat', 'kaarigar', 'jobCard']))], 201);
    }

    /** Who is responsible for a stage, and notes on it (spec §5). */
    public function updateStep(Request $request, WorkOrder $workOrder, WoStep $step): JsonResponse
    {
        abort_unless($step->work_order_id === $workOrder->id, 404);
        $data = $request->validate([
            'assigned_user_id' => ['nullable', TenantRules::exists('users')],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);
        $step->update($data);

        if (! empty($data['assigned_user_id'])) {
            app(\App\Services\Push\Notify::class)->staffUser($data['assigned_user_id'], "Stage assigned: {$step->process->name}", "{$workOrder->wo_no}", "/staff/work-orders/{$workOrder->id}", 'wo.assigned');
        }

        return response()->json(['data' => $step->load('assignee:id,name')]);
    }

    public function advance(WorkOrder $workOrder, WoStep $step): JsonResponse
    {
        abort_unless($step->work_order_id === $workOrder->id, 404);

        return response()->json(['data' => $this->production->advance($step)]);
    }

    public function finish(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $data = $request->validate([
            'pieces' => ['required', 'array', 'min:1', 'max:500'],
            'pieces.*.gross' => ['required', 'decimal:0,3', 'gt:0'],
            'pieces.*.stone' => ['nullable', 'decimal:0,3', 'min:0'],
            'pieces.*.purity' => ['nullable', 'decimal:0,3', 'gt:0', 'max:100'],
            'pieces.*.size' => ['nullable', 'string', 'max:20'],
            'pieces.*.order_line_id' => ['nullable', 'integer'],
        ]);

        $items = $this->production->finish($workOrder, $data['pieces']);

        return response()->json(['data' => $items], 201);
    }

    public function complete(WorkOrder $workOrder): JsonResponse
    {
        return response()->json(['data' => $this->summary($this->production->complete($workOrder)->load(['design', 'karat', 'steps.process']))]);
    }

    public function cancel(WorkOrder $workOrder): JsonResponse
    {
        abort_unless($workOrder->status === WorkOrder::OPEN, 422, 'Only work orders not yet started can be cancelled.');
        $workOrder->forceFill(['status' => WorkOrder::CANCELLED])->save();

        return response()->json(['data' => $workOrder]);
    }

    private function summary(WorkOrder $wo): array
    {
        $step = $wo->relationLoaded('steps') ? $wo->currentStep() : null;

        return $wo->only(['id', 'wo_no', 'qty', 'sizes', 'status', 'priority', 'due_date', 'target_wt_min', 'target_wt_max', 'rework_of_id', 'remarks', 'completed_at']) + [
            'design' => $wo->design?->only(['id', 'code', 'name']),
            'karat' => $wo->karat?->only(['id', 'label']),
            'current_process' => $step?->process?->name,
            'stages' => $wo->relationLoaded('steps') ? $wo->steps->map(fn ($s) => ['name' => $s->process?->name, 'status' => $s->status]) : [],
            'gold_in_wo' => $this->production->goldInWo($wo)['fine'],
            'overdue' => $wo->isActive() && $wo->due_date && $wo->due_date->isPast() && ! $wo->due_date->isToday(),
        ];
    }

    private function live(JobCard $card): array
    {
        $f = $this->ops->cardFigures($card);

        return $f + ['outstanding' => Weight::sub($f['issued'], Weight::add($f['returned'], $f['scrap']))];
    }
}
