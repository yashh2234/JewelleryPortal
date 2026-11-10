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

/**
 * Delivery challan: pieces leave finished goods for a retailer, using actual weights
 * (spec 16.8: dispatch reduces finished stock). The challan then carries the
 * shipment: courier, tracking, documents and status up to delivery.
 */
class Dispatching
{
    public const SHIPMENT_STATUSES = ['packing', 'dispatched', 'in_transit', 'out_for_delivery', 'delivered', 'failed'];

    /** Tracking pages for common couriers; {no} is replaced with the tracking number. */
    public const COURIERS = [
        'Blue Dart' => 'https://www.bluedart.com/web/guest/trackdartresult?trackFor=0&trackNo={no}',
        'Delhivery' => 'https://www.delhivery.com/track/package/{no}',
        'DTDC' => 'https://www.dtdc.in/tracking.asp?strCnno={no}',
        'India Post' => 'https://www.indiapost.gov.in/_layouts/15/dop.portal.tracking/trackconsignment.aspx',
        'Sequel Logistics' => 'https://www.sequel247.com/track?awb={no}',
        'BVC' => 'https://www.bvclogistics.com/tracking?awb={no}',
        'Hand delivery' => null,
    ];

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
        if ($order && in_array($order->status, [Order::CANCELLED, Order::ON_HOLD, Order::CLOSED, Order::NEW, Order::PENDING_APPROVAL], true)) {
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
            $this->applyShipment($dispatch, $data + ['shipment_status' => $data['shipment_status'] ?? 'dispatched']);

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
                $track = $dispatch->tracking_no ? " {$dispatch->courier} {$dispatch->tracking_no}." : '';
                app(\App\Services\Push\Notify::class)->orderStatus($o, ($all ? 'Dispatched' : 'Partly dispatched')." on challan {$challan}.{$track}", 'shipment.dispatched');
            }

            if ($orders->isEmpty()) app(\App\Services\Push\Notify::class)->retailer(
                $retailer->id,
                'Your order is on the way',
                "Challan {$challan}: {$items->count()} piece(s), {$dispatch->net_wt} g net.",
                '/portal',
            );

            return $dispatch->load('lines.item');
        });
    }

    /** Courier, tracking and status changes after the challan. Delivered on every challan → order delivered. */
    public function updateShipment(Dispatch $dispatch, array $data): Dispatch
    {
        return DB::transaction(function () use ($dispatch, $data) {
            $before = $dispatch->shipment_status;
            $this->applyShipment($dispatch, $data);

            if ($dispatch->shipment_status !== $before) {
                $label = str_replace('_', ' ', $dispatch->shipment_status);
                $msg = "Challan {$dispatch->challan_no}: {$label}".($dispatch->delivery_remarks ? " ({$dispatch->delivery_remarks})" : '').'.';
                $notify = app(\App\Services\Push\Notify::class);

                if ($dispatch->order) {
                    $notify->orderStatus($dispatch->order, ucfirst($msg), 'shipment.'.$dispatch->shipment_status);
                } else {
                    $notify->retailer($dispatch->retailer_id, 'Shipment update', ucfirst($msg), '/portal/orders', 'shipment.'.$dispatch->shipment_status, whatsapp: true);
                }
                if ($dispatch->shipment_status === 'failed') {
                    $notify->staffWith('dispatch.manage', "Delivery failed: {$dispatch->challan_no}", $dispatch->delivery_remarks ?? '', '/staff/dispatches', 'shipment.failed');
                }
                if ($dispatch->shipment_status === 'delivered') {
                    $notify->staffWith('orders.manage', "Delivered: {$dispatch->challan_no}", $dispatch->retailer->shop_name, '/staff/dispatches', 'shipment.delivered');
                }
            }

            $this->refreshDelivery($dispatch->order);

            return $dispatch;
        });
    }

    private function applyShipment(Dispatch $dispatch, array $data): void
    {
        $fill = array_intersect_key($data, array_flip(['shipment_status', 'courier', 'tracking_no', 'tracking_url', 'delivery_remarks']));

        if (array_key_exists('courier', $fill) || array_key_exists('tracking_no', $fill)) {
            $courier = $fill['courier'] ?? $dispatch->courier;
            $no = $fill['tracking_no'] ?? $dispatch->tracking_no;
            $template = self::COURIERS[$courier] ?? null;
            if (empty($fill['tracking_url']) && $template && $no) {
                $fill['tracking_url'] = str_replace('{no}', rawurlencode($no), $template);
            }
        }

        $status = $fill['shipment_status'] ?? $dispatch->shipment_status;
        if ($status !== 'packing' && ! $dispatch->shipped_at) {
            $fill['shipped_at'] = now();
        }
        if ($status === 'delivered' && ! $dispatch->delivered_at) {
            $fill['delivered_at'] = now();
        }
        if ($status !== 'delivered') {
            $fill['delivered_at'] = null;
        }

        $dispatch->forceFill($fill)->save();
    }

    /** All pieces dispatched and every challan delivered → order delivered. */
    private function refreshDelivery(?Order $order): void
    {
        if (! $order || ! in_array($order->status, [Order::DISPATCHED, Order::DELIVERED], true)) {
            return;
        }

        $allDelivered = $order->dispatches()->where('shipment_status', '!=', 'delivered')->doesntExist();
        $next = $allDelivered ? Order::DELIVERED : Order::DISPATCHED;

        if ($next !== $order->status) {
            $order->forceFill(['status' => $next, 'delivered_at' => $allDelivered ? now() : null])->save();
        }
    }
}
