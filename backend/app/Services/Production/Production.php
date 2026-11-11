<?php

namespace App\Services\Production;

use App\Models\Design;
use App\Models\FinishedItem;
use App\Models\JobCard;
use App\Models\Karat;
use App\Models\MetalLocation;
use App\Models\MetalTransaction;
use App\Models\Order;
use App\Models\Retailer;
use App\Models\RouteTemplate;
use App\Models\WorkOrder;
use App\Models\WoStep;
use App\Services\Metal\Ledger;
use App\Services\Metal\LedgerException;
use App\Services\Metal\MetalOperations;
use App\Services\Portal\Catalogue;
use App\Services\Push\Notify;
use App\Support\Metal\Weight;
use App\Support\Money;
use App\Support\NumberSeries;
use Illuminate\Support\Facades\DB;

/**
 * Module 2: an order becomes one or more work orders; each work order moves through
 * its route as job cards; finished pieces come out with actual weights.
 *
 * Gold path: Vault → Kaarigar (first stage) → WO → Kaarigar (next stage) → … → WO
 *            → Finished goods (one row per piece) → Retailer.
 */
class Production
{
    public function __construct(private Ledger $ledger, private MetalOperations $ops, private OrderProgress $progress) {}

    // ── Orders ───────────────────────────────────────────────────────────────

    /** @param list<array<string, mixed>> $lines */
    public function createOrder(Retailer $retailer, array $lines, array $data, string $source = 'staff'): Order
    {
        if (! $retailer->isActive()) {
            throw new LedgerException("{$retailer->shop_name} is not an active retailer.");
        }

        return DB::transaction(function () use ($retailer, $lines, $data, $source) {
            $order = Order::create([
                'order_no' => NumberSeries::next('ORD'),
                'retailer_id' => $retailer->id,
                'source' => $source,
                // Portal orders arrive as "new"; orders keyed in by staff are already reviewed.
                'status' => $source === 'portal' ? Order::NEW : Order::PENDING_APPROVAL,
                'due_date' => $data['due_date'] ?? null,
                'remarks' => $data['remarks'] ?? null,
            ]);

            foreach ($lines as $line) {
                $order->lines()->create($line);
            }

            $order->forceFill(['estimated_value' => $this->estimate($order, $retailer)])->save();

            return $order->load('lines');
        });
    }

    /** Estimated order value at today's rate (metal + making), for the sales order. */
    public function estimate(Order $order, Retailer $retailer): ?string
    {
        $catalogue = app(Catalogue::class);
        $total = '0';
        $any = false;

        foreach ($order->lines()->with(['design', 'karat'])->get() as $line) {
            $weight = $line->target_wt_min && $line->target_wt_max
                ? Weight::round(bcdiv(Weight::add($line->target_wt_min, $line->target_wt_max), '2', 6))
                : ($line->target_wt_min ?? $line->target_wt_max);
            $est = $catalogue->estimate($line->design, $line->karat_id, (string) $line->karat->purity_pct, $retailer, $weight);
            if ($est) {
                $any = true;
                $total = Money::add($total, Money::mul((string) $line->qty, $est['total']));
            }
        }

        return $any ? $total : null;
    }

    /** Sales has reviewed a new order and passes it on for approval. */
    public function submitForApproval(Order $order): Order
    {
        $this->assertStatus($order, [Order::NEW]);
        $order->forceFill(['status' => Order::PENDING_APPROVAL])->save();
        app(Notify::class)->staffWith('orders.approve', "Order {$order->order_no} needs approval", $order->retailer->shop_name, "/staff/orders/{$order->id}", 'order.pending_approval');

        return $order;
    }

    public function approve(Order $order): Order
    {
        $this->assertStatus($order, [Order::NEW, Order::PENDING_APPROVAL]);
        $order->forceFill(['status' => Order::APPROVED, 'approved_at' => now(), 'confirmed_at' => now(), 'approved_by' => auth('staff')->id()])->save();
        app(Notify::class)->orderStatus($order, 'Approved and going into production.', 'order.approved');
        app(Notify::class)->staffWith('production.manage', "Order {$order->order_no} approved", 'Create the work orders.', "/staff/orders/{$order->id}", 'order.approved');

        return $order;
    }

    /** Older clients: confirm = approve. */
    public function confirm(Order $order): Order
    {
        return $this->approve($order);
    }

    /** Closed by hand once dispatched / delivered and settled. */
    public function close(Order $order): Order
    {
        $this->assertStatus($order, [Order::DISPATCHED, Order::DELIVERED]);
        $order->forceFill(['status' => Order::CLOSED, 'closed_at' => now()])->save();

        return $order;
    }

    public function hold(Order $order): Order
    {
        $this->assertStatus($order, [Order::NEW, Order::PENDING_APPROVAL, Order::APPROVED, ...Order::PRODUCTION]);
        $order->forceFill(['status_before_hold' => $order->status, 'status' => Order::ON_HOLD])->save();

        return $order;
    }

    public function resume(Order $order): Order
    {
        $this->assertStatus($order, [Order::ON_HOLD]);
        $order->forceFill(['status' => $order->status_before_hold ?? Order::APPROVED, 'status_before_hold' => null])->save();

        return $this->progress->refresh($order);
    }

    public function cancel(Order $order, string $reason): Order
    {
        $this->assertStatus($order, [Order::NEW, Order::PENDING_APPROVAL, Order::APPROVED, Order::ON_HOLD, Order::WORK_ORDER_CREATED, Order::MATERIAL_ISSUED, Order::IN_PRODUCTION]);

        $active = WorkOrder::whereHas('orderLines', fn ($q) => $q->where('order_id', $order->id))->where('status', WorkOrder::IN_PROGRESS)->exists();
        if ($active) {
            throw new LedgerException('Work orders for this order are already in production. Cancel or finish them first.');
        }

        DB::transaction(function () use ($order, $reason) {
            WorkOrder::whereHas('orderLines', fn ($q) => $q->where('order_id', $order->id))
                ->where('status', WorkOrder::OPEN)->get()->each(fn ($wo) => $wo->forceFill(['status' => WorkOrder::CANCELLED])->save());
            $order->forceFill(['status' => Order::CANCELLED, 'cancel_reason' => $reason])->save();
        });
        app(Notify::class)->orderStatus($order, "Cancelled: {$reason}", 'order.cancelled');

        return $order;
    }

    // ── Work orders ──────────────────────────────────────────────────────────

    /**
     * Create work orders from order lines. Each group is a list of line ids with the
     * same design and karat (e.g. sizes of one design batched into one WO).
     * Default: one work order per line.
     *
     * @param  list<list<int>>|null  $groups
     * @return list<WorkOrder>
     */
    public function createWorkOrders(Order $order, ?array $groups = null): array
    {
        $this->assertStatus($order, [Order::APPROVED, ...Order::PRODUCTION]);

        $lines = $order->lines()->with(['design', 'workOrders'])->get()->keyBy('id');
        $groups ??= $lines->filter(fn ($l) => $l->workOrders->isEmpty())->keys()->map(fn ($id) => [$id])->values()->all();

        if ($groups === []) {
            throw new LedgerException('Every line of this order already has a work order.');
        }

        return DB::transaction(function () use ($order, $lines, $groups) {
            $created = [];

            foreach ($groups as $ids) {
                $group = collect($ids)->map(fn ($id) => $lines[$id] ?? throw new LedgerException("Line {$id} is not on this order."));

                if ($group->contains(fn ($l) => $l->workOrders->isNotEmpty())) {
                    throw new LedgerException('A line already has a work order.');
                }
                if ($group->pluck('design_id')->unique()->count() > 1 || $group->pluck('karat_id')->unique()->count() > 1) {
                    throw new LedgerException('Lines in one work order must have the same design and karat.');
                }

                $first = $group->first();
                $sizes = $group->groupBy(fn ($l) => $l->size ?? '-')->map->sum('qty')->all();

                $wo = $this->newWorkOrder($first->design, $first->karat_id, (int) $group->sum('qty'), [
                    'sizes' => $sizes,
                    'target_wt_min' => $first->target_wt_min,
                    'target_wt_max' => $first->target_wt_max,
                    'due_date' => $order->due_date?->toDateString(),
                    'remarks' => $order->order_no,
                ]);

                foreach ($group as $line) {
                    $wo->orderLines()->attach($line->id, ['qty' => $line->qty]);
                }

                $created[] = $wo;
            }

            if ($order->status === Order::APPROVED) {
                $order->forceFill(['status' => Order::WORK_ORDER_CREATED])->save();
            }
            $this->progress->refresh($order);

            foreach ($created as $wo) {
                app(Notify::class)->staffWith('production.manage', "Work order {$wo->wo_no} created", "{$order->order_no} · {$wo->qty} pcs", "/staff/work-orders/{$wo->id}", 'wo.created');
            }

            return $created;
        });
    }

    /** A work order with its route copied from the design's category template (or the default). */
    public function newWorkOrder(Design $design, int $karatId, int $qty, array $data = [], ?array $processIds = null): WorkOrder
    {
        $processIds ??= $this->routeFor($design);

        if ($processIds === []) {
            throw new LedgerException('No route template found. Add one in Settings → Route templates.');
        }

        $wo = WorkOrder::create([
            'wo_no' => NumberSeries::next('WO'),
            'design_id' => $design->id,
            'karat_id' => $karatId,
            'qty' => $qty,
        ] + $data);

        foreach (array_values($processIds) as $i => $processId) {
            $wo->steps()->create(['process_id' => $processId, 'sequence' => $i + 1]);
        }

        return $wo;
    }

    /** @return list<int> */
    private function routeFor(Design $design): array
    {
        $template = RouteTemplate::with('steps')
            ->where(fn ($q) => $q->where('category_id', $design->category_id)->orWhereNull('category_id'))
            ->orderByRaw('category_id is null')       // category-specific first
            ->orderByDesc('is_default')
            ->first();

        return $template?->steps->pluck('process_id')->all() ?? [];
    }

    /**
     * Issue gold for a stage: opens a job card on the step for the kaarigar.
     * First stage issues from the vault; later stages from the work order's own gold.
     */
    public function issueForStep(WoStep $step, int $kaarigarId, array $items, array $header = []): MetalTransaction
    {
        $wo = $step->loadMissing('workOrder.design')->workOrder;
        if (! $wo->isActive()) {
            throw new LedgerException("{$wo->wo_no} is {$wo->status}.");
        }
        if ($step->status === WoStep::DONE) {
            throw new LedgerException('This stage is already done.');
        }

        $source = $this->hasGoldInWo($wo) ? 'wo' : 'vault';

        return DB::transaction(function () use ($step, $wo, $kaarigarId, $items, $header, $source) {
            $card = $this->ops->openJobCard([
                'kaarigar_id' => $kaarigarId,
                'process_id' => $step->process_id,
                'karat_id' => $wo->karat_id,
                'wo_step_id' => $step->id,
                'design_id' => $wo->design_id,
                'description' => "{$wo->wo_no} · {$wo->qty} × {$wo->design->name}",
                'pieces' => $wo->qty,
                'due_date' => $wo->due_date?->toDateString(),
            ]);

            return $this->ops->issue($card->load('woStep.workOrder'), $items, $source, $header);
        });
    }

    /** Mark a stage done (all its job cards closed). The next stage becomes current. */
    public function advance(WoStep $step): WoStep
    {
        if ($step->jobCards()->where('status', JobCard::OPEN)->exists()) {
            throw new LedgerException('Close the open job cards on this stage first.');
        }

        $step->forceFill(['status' => $step->jobCards()->exists() ? WoStep::DONE : WoStep::SKIPPED, 'done_at' => now()])->save();
        $this->progress->forWorkOrder($step->work_order_id);

        return $step;
    }

    /** Fine gold sitting in the work order between stages. */
    public function goldInWo(WorkOrder $wo): array
    {
        return $this->ledger->balance($this->ledger->workOrder($wo));
    }

    private function hasGoldInWo(WorkOrder $wo): bool
    {
        return Weight::isPositive($this->goldInWo($wo)['fine']);
    }

    /**
     * Record finished pieces with their actual weights. Gold moves from the work
     * order to finished goods, one ledger line and one finished_item per piece.
     *
     * @param  list<array{gross: string, stone?: string, size?: string|null, order_line_id?: int|null, purity?: string|null}>  $pieces
     * @return list<FinishedItem>
     */
    public function finish(WorkOrder $wo, array $pieces, array $header = []): array
    {
        if (! $wo->isActive()) {
            throw new LedgerException("{$wo->wo_no} is {$wo->status}.");
        }
        if ($wo->jobCards()->where('job_cards.status', JobCard::OPEN)->exists()) {
            throw new LedgerException('Close the open job cards before recording finished pieces.');
        }

        $karat = Karat::findOrFail($wo->karat_id);
        $woLoc = $this->ledger->workOrder($wo);
        $finished = $this->ledger->of(MetalLocation::FINISHED);

        return DB::transaction(function () use ($wo, $pieces, $header, $karat, $woLoc, $finished) {
            $tx = $this->ledger->post(MetalTransaction::FINISH, ['work_order_id' => $wo->id, 'remarks' => "Finished pieces of {$wo->wo_no}"] + $header, array_map(fn ($p) => [
                'from' => $woLoc, 'to' => $finished, 'karat' => $karat, 'kind' => 'work',
                'gross' => (string) $p['gross'], 'stone' => (string) ($p['stone'] ?? '0'),
                'purity' => $p['purity'] ?? null, 'pieces' => 1,
            ], $pieces));

            $orderLineIds = $wo->orderLines()->pluck('order_lines.id')->all();

            $items = [];
            foreach ($tx->lines as $i => $line) {
                $p = $pieces[$i];
                $items[] = FinishedItem::create([
                    'tag_no' => NumberSeries::next('TAG', pad: 5),
                    'work_order_id' => $wo->id,
                    'order_line_id' => $p['order_line_id'] ?? ($orderLineIds[0] ?? null),
                    'design_id' => $wo->design_id,
                    'karat_id' => $wo->karat_id,
                    'size' => $p['size'] ?? null,
                    'gross_wt' => $line->gross_wt,
                    'stone_wt' => $line->stone_wt,
                    'net_wt' => $line->net_wt,
                    'purity_pct' => $line->purity_pct,
                    'fine_wt' => $line->fine_wt,
                ]);
            }

            $this->progress->forWorkOrder($wo);

            return $items;
        });
    }

    /**
     * Complete the work order. Gold left in the WO (filings, offcuts) goes to the
     * scrap pool so the WO location ends at zero.
     */
    public function complete(WorkOrder $wo): WorkOrder
    {
        if (! $wo->isActive()) {
            throw new LedgerException("{$wo->wo_no} is {$wo->status}.");
        }
        if ($wo->jobCards()->where('job_cards.status', JobCard::OPEN)->exists()) {
            throw new LedgerException('Close the open job cards first.');
        }
        if (! $wo->finishedItems()->exists()) {
            throw new LedgerException('Record the finished pieces before completing the work order.');
        }
        // Blueprint rule 4: gold is reconciled first — every difference approved with a reason.
        $unresolved = $wo->jobCards()->where('job_cards.loss_status', JobCard::LOSS_PENDING)->pluck('card_no');
        if ($unresolved->isNotEmpty()) {
            throw new LedgerException('Approve the gold difference on '.$unresolved->implode(', ').' before completing the work order.');
        }

        return DB::transaction(function () use ($wo) {
            $woLoc = $this->ledger->workOrder($wo);
            $left = collect($this->ledger->balanceRows([$woLoc->id]))->filter(fn ($r) => Weight::isPositive($r['fine']));

            if ($left->isNotEmpty()) {
                $this->ledger->post(MetalTransaction::TRANSFER, ['work_order_id' => $wo->id, 'remarks' => "Leftover from {$wo->wo_no} to scrap"],
                    $left->map(fn ($r) => [
                        'from' => $woLoc, 'to' => $this->ledger->of(MetalLocation::SCRAP), 'karat' => Karat::find($r['karat_id']),
                        'kind' => 'scrap', 'fine' => $r['fine'],
                    ])->values()->all());
            }

            $wo->steps()->whereIn('status', [WoStep::PENDING, WoStep::IN_PROGRESS])->get()
                ->each(fn ($s) => $s->forceFill(['status' => $s->jobCards()->exists() ? WoStep::DONE : WoStep::SKIPPED, 'done_at' => now()])->save());
            $wo->forceFill(['status' => WorkOrder::COMPLETED, 'completed_at' => now()])->save();

            $this->progress->forWorkOrder($wo);

            return $wo;
        });
    }

    /**
     * Send pieces back for rework (QC failure): a new work order is
     * opened on the chosen route and the pieces' gold moves into it.
     *
     * @param  list<int>  $itemIds
     * @param  list<int>  $processIds
     */
    public function rework(array $itemIds, array $processIds, string $reason): WorkOrder
    {
        $items = FinishedItem::whereIn('id', $itemIds)->get();

        if ($items->count() !== count(array_unique($itemIds))) {
            throw new LedgerException('Some pieces were not found.');
        }
        if ($items->contains(fn ($i) => $i->status !== FinishedItem::IN_STOCK)) {
            throw new LedgerException('Only pieces in stock can go for rework.');
        }
        if ($items->pluck('design_id')->unique()->count() > 1 || $items->pluck('karat_id')->unique()->count() > 1) {
            throw new LedgerException('Rework pieces must be the same design and karat.');
        }

        return DB::transaction(function () use ($items, $processIds, $reason) {
            $first = $items->first();
            $wo = $this->newWorkOrder($first->design, $first->karat_id, $items->count(), [
                'rework_of_id' => $first->work_order_id,
                'priority' => 'high',
                'remarks' => 'Rework: '.$reason,
            ], $processIds);

            if ($first->order_line_id) {
                $wo->orderLines()->attach($first->order_line_id, ['qty' => $items->count()]);
            }

            $karat = Karat::findOrFail($first->karat_id);
            $this->ledger->post(MetalTransaction::TRANSFER, ['work_order_id' => $wo->id, 'remarks' => "Pieces to rework {$wo->wo_no}: {$reason}"],
                $items->map(fn ($i) => [
                    'from' => $this->ledger->of(MetalLocation::FINISHED), 'to' => $this->ledger->workOrder($wo), 'karat' => $karat,
                    'kind' => 'metal', 'gross' => $i->gross_wt, 'stone' => $i->stone_wt, 'purity' => $i->purity_pct, 'pieces' => 1,
                ])->all());

            $items->each(fn ($i) => $i->forceFill(['status' => FinishedItem::REWORK, 'qc_remarks' => trim(($i->qc_remarks ?? '').' Rework: '.$reason)])->save());
            $this->progress->forItems($items);
            app(Notify::class)->staffWith('production.manage', "Rework {$wo->wo_no}", $reason, "/staff/work-orders/{$wo->id}", 'qc.failed');

            return $wo;
        });
    }

    /** Kept for callers: the order's production status is worked out by OrderProgress. */
    public function refreshOrders(WorkOrder $wo): void
    {
        $this->progress->forWorkOrder($wo);
    }

    private function assertStatus(Order $order, array $allowed): void
    {
        if (! in_array($order->status, $allowed, true)) {
            throw new LedgerException("Order {$order->order_no} is ".str_replace('_', ' ', $order->status).'.');
        }
    }
}
