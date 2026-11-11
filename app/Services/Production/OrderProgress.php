<?php

namespace App\Services\Production;

use App\Models\FinishedItem;
use App\Models\JobCard;
use App\Models\MetalTransaction;
use App\Models\Order;
use App\Models\WorkOrder;
use App\Models\WoStep;
use App\Services\Push\Notify;

/**
 * Works out an order's production status from what is actually happening on the
 * floor, so nobody has to move it by hand:
 *
 *   work_order_created  every line planned, nothing issued yet
 *   material_issued     gold has gone to a kaarigar for one of its work orders
 *   in_production       work has come back / stages are moving
 *   under_qc            all work orders done, pieces waiting for QC
 *   rework_required     a piece failed QC or is out for rework
 *   ready_for_dispatch  every piece passed QC
 *
 * Only orders already in one of these statuses are touched: people decide
 * approval, hold, cancel and close; dispatches decide dispatched/delivered.
 */
class OrderProgress
{
    public function __construct(private Notify $notify) {}

    public function forWorkOrder(WorkOrder|int|null $wo): void
    {
        if ($wo === null) {
            return;
        }
        $id = $wo instanceof WorkOrder ? $wo->id : $wo;
        $ids = WorkOrder::whereKey($id)->value('rework_of_id') ? [$id, WorkOrder::whereKey($id)->value('rework_of_id')] : [$id];

        Order::whereHas('lines.workOrders', fn ($q) => $q->whereIn('work_orders.id', $ids))->get()->each(fn (Order $o) => $this->refresh($o));
    }

    /** @param iterable<FinishedItem> $items */
    public function forItems(iterable $items): void
    {
        $lineIds = collect($items)->pluck('order_line_id')->filter()->unique();
        Order::whereHas('lines', fn ($q) => $q->whereIn('order_lines.id', $lineIds))->get()->each(fn (Order $o) => $this->refresh($o));
    }

    public function forJobCard(JobCard $card): void
    {
        $this->forWorkOrder($card->woStep?->work_order_id);
    }

    public function refresh(Order $order): Order
    {
        if (! in_array($order->status, Order::PRODUCTION, true)) {
            return $order;
        }

        $next = $this->compute($order);
        if ($next !== $order->status) {
            $order->forceFill(['status' => $next])->save();
            $this->announce($order);
        }

        return $order;
    }

    public function compute(Order $order): string
    {
        $lines = $order->lines()->with('workOrders')->get();
        $wos = $lines->flatMap->workOrders->unique('id');
        $active = $wos->where('status', '!=', WorkOrder::CANCELLED);

        if ($active->isEmpty()) {
            return Order::WORK_ORDER_CREATED;
        }

        $woIds = $active->pluck('id');
        $items = FinishedItem::whereIn('order_line_id', $lines->pluck('id'))->where('status', '!=', FinishedItem::DISPATCHED)->get();

        $rework = $items->contains(fn ($i) => $i->status === FinishedItem::REWORK || ($i->qc_status === 'failed' && $i->status === FinishedItem::IN_STOCK))
            || $active->contains(fn ($w) => $w->rework_of_id && $w->isActive());
        if ($rework) {
            return Order::REWORK_REQUIRED;
        }

        $allPlanned = $lines->every(fn ($l) => $l->workOrders->isNotEmpty());
        $allDone = $active->every(fn ($w) => $w->status === WorkOrder::COMPLETED);

        if ($allPlanned && $allDone) {
            if ($items->contains(fn ($i) => $i->qc_status === 'pending')) {
                return Order::UNDER_QC;
            }

            return Order::READY_FOR_DISPATCH;
        }

        $cardIds = JobCard::whereIn('wo_step_id', WoStep::whereIn('work_order_id', $woIds)->select('id'))->pluck('id');
        $posted = fn (string $type) => $cardIds->isNotEmpty() && MetalTransaction::where('type', $type)->whereNull('reversed_by_id')->whereIn('job_card_id', $cardIds)->exists();

        $moving = $posted(MetalTransaction::RECEIVE)
            || WoStep::whereIn('work_order_id', $woIds)->where('status', WoStep::DONE)->exists()
            || $items->isNotEmpty();
        if ($moving) {
            return Order::IN_PRODUCTION;
        }

        $issued = $posted(MetalTransaction::ISSUE);

        return $issued ? Order::MATERIAL_ISSUED : Order::WORK_ORDER_CREATED;
    }

    private function announce(Order $order): void
    {
        $url = "/staff/orders/{$order->id}";

        match ($order->status) {
            Order::MATERIAL_ISSUED => $this->notify->orderStatus($order, 'Production has started.', 'order.production_started'),
            Order::UNDER_QC => $this->notify->staffWith('qc.manage', "Order {$order->order_no} ready for QC", 'All work orders are complete.', '/staff/finished', 'order.under_qc'),
            Order::REWORK_REQUIRED => $this->notify->staffWith('production.manage', "Rework on {$order->order_no}", 'A piece failed quality check.', $url, 'qc.failed'),
            Order::READY_FOR_DISPATCH => (function () use ($order, $url) {
                $this->notify->orderStatus($order, 'Ready for dispatch.', 'order.ready');
                $this->notify->staffWith('dispatch.manage', "Order {$order->order_no} ready for dispatch", $order->retailer?->shop_name ?? '', $url, 'order.ready');
            })(),
            default => null,
        };
    }

    public static function label(string $status): string
    {
        return ucfirst(str_replace('_', ' ', $status));
    }
}
