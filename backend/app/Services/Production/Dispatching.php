<?php

namespace App\Services\Production;

use App\Models\Dispatch;
use App\Models\FinishedItem;
use App\Models\Karat;
use App\Models\MetalLocation;
use App\Models\MetalTransaction;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Retailer;
use App\Services\Metal\Ledger;
use App\Services\Metal\LedgerException;
use App\Support\Metal\Weight;
use App\Support\NumberSeries;
use Illuminate\Support\Facades\DB;

/** Delivery challan: pieces leave finished goods for a retailer, using actual weights. */
class Dispatching
{
    public function __construct(private Ledger $ledger) {}

    /** @param list<int> $itemIds */
    public function dispatch(Retailer $retailer, array $itemIds, ?Order $order, array $data = []): Dispatch
    {
        $items = FinishedItem::whereIn('id', $itemIds)->get();

        if ($items->isEmpty() || $items->count() !== count(array_unique($itemIds))) {
            throw new LedgerException('Pick the pieces to dispatch.');
        }
        if ($order && $order->retailer_id !== $retailer->id) {
            throw new LedgerException('That order belongs to another retailer.');
        }
        if ($order && in_array($order->status, [Order::CANCELLED, Order::ON_HOLD, Order::CLOSED], true)) {
            throw new LedgerException("Order {$order->order_no} is ".str_replace('_', ' ', $order->status).'.');
        }

        foreach ($items as $item) {
            if ($item->status !== FinishedItem::IN_STOCK || $item->qc_status !== 'passed') {
                throw new LedgerException("{$item->tag_no} is not ready to dispatch (must be in stock and QC-passed).");
            }
            if ($retailer->hallmark_mandatory && ! $item->huid) {
                throw new LedgerException("{$item->tag_no} has no HUID. Hallmarking is mandatory for {$retailer->shop_name}.");
            }
        }

        return DB::transaction(function () use ($retailer, $items, $order, $data) {
            $finished = $this->ledger->of(MetalLocation::FINISHED);
            $outside = $this->ledger->of(MetalLocation::OUTSIDE);
            $karats = Karat::whereIn('id', $items->pluck('karat_id'))->get()->keyBy('id');
            $challan = NumberSeries::next('DC', $data['date'] ?? null);

            $tx = $this->ledger->post(MetalTransaction::DISPATCH, [
                'retailer_id' => $retailer->id, 'reference' => $challan, 'remarks' => $data['remarks'] ?? null,
            ] + (isset($data['date']) ? ['date' => $data['date']] : []), $items->map(fn ($i) => [
                'from' => $finished, 'to' => $outside, 'karat' => $karats[$i->karat_id], 'kind' => 'metal',
                'gross' => $i->gross_wt, 'stone' => $i->stone_wt, 'purity' => $i->purity_pct, 'pieces' => 1,
            ])->all());

            $dispatch = Dispatch::create([
                'challan_no' => $challan,
                'retailer_id' => $retailer->id,
                'order_id' => $order?->id,
                'date' => $tx->date,
                'pieces' => $items->count(),
                'gross_wt' => Weight::add('0', ...$items->pluck('gross_wt')->all()),
                'net_wt' => Weight::add('0', ...$items->pluck('net_wt')->all()),
                'fine_wt' => Weight::add('0', ...$items->pluck('fine_wt')->all()),
                'metal_transaction_id' => $tx->id,
                'remarks' => $data['remarks'] ?? null,
            ]);

            foreach ($items as $item) {
                $dispatch->lines()->create(['finished_item_id' => $item->id, 'order_line_id' => $item->order_line_id]);
                $item->forceFill(['status' => FinishedItem::DISPATCHED])->save();
            }

            // Order progress from the pieces' own order lines.
            $lineCounts = $items->whereNotNull('order_line_id')->countBy('order_line_id');
            foreach ($lineCounts as $lineId => $n) {
                OrderLine::whereKey($lineId)->increment('qty_dispatched', $n);
            }

            $orders = Order::whereIn('id', OrderLine::whereIn('id', $lineCounts->keys())->pluck('order_id'))
                ->when($order, fn ($q) => $q->orWhere('id', $order->id))->get();
            foreach ($orders as $o) {
                $lines = $o->lines()->get();
                $all = $lines->every(fn ($l) => $l->qty_dispatched >= $l->qty);
                $o->forceFill(['status' => $all ? Order::DISPATCHED : Order::PARTIALLY_DISPATCHED])->save();
                app(\App\Services\Push\Notify::class)->orderStatus($o, ($all ? 'Dispatched' : 'Partly dispatched')." on challan {$challan}.");
            }

            if ($orders->isEmpty()) app(\App\Services\Push\Notify::class)->retailer(
                $retailer->id,
                'Your order is on the way',
                "Challan {$challan}: {$items->count()} piece(s), {$dispatch->net_wt} g net.",
                '/app/portal',
            );

            return $dispatch->load('lines.item');
        });
    }
}
