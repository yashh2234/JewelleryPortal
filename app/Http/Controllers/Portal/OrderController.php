<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Dispatch;
use App\Models\OrderLine;
use App\Models\Order;
use App\Models\Retailer;
use App\Services\Metal\LedgerException;
use App\Services\Production\Production;
use App\Services\Push\Notify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Retailer's own orders: place from the cart, track, re-order. */
class OrderController extends Controller
{
    /** Retailer-facing timeline (spec §10). Internal details (kaarigars, gold, costs) never leave the server. */
    public const TIMELINE = ['received', 'approved', 'in_production', 'quality_check', 'ready', 'dispatched', 'delivered'];

    public function __construct(private Production $production) {}

    private function retailer(Request $request): Retailer
    {
        return $request->user('retailer')->retailer;
    }

    public function index(Request $request): JsonResponse
    {
        $orders = Order::where('retailer_id', $this->retailer($request)->id)
            ->withCount('lines')->withSum('lines as qty_total', 'qty')->withSum('lines as qty_dispatched_total', 'qty_dispatched')
            ->latest('id')->paginate(30);

        $orders->getCollection()->transform(fn (Order $o) => $o->only(['id', 'order_no', 'status', 'due_date', 'created_at', 'lines_count', 'qty_total', 'qty_dispatched_total'])
            + ['stage' => $this->stage($o)]);

        return response()->json($orders);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->retailer_id === $this->retailer($request)->id, 404);
        $order->load(['lines.design.images', 'lines.karat:id,label', 'lines.workOrders.steps.process:id,name', 'dispatches.documents']);

        $payments = app(\App\Services\Accounting\OrderPayments::class)->summary($order);

        return response()->json(['data' => [
            'id' => $order->id, 'order_no' => $order->order_no, 'status' => $order->status, 'due_date' => $order->due_date?->toDateString(),
            'remarks' => $order->remarks, 'cancel_reason' => $order->cancel_reason, 'created_at' => $order->created_at,
            'approved_at' => $order->approved_at, 'delivered_at' => $order->delivered_at,
            'stage' => $this->stage($order),
            'timeline' => self::TIMELINE,
            'production_stage' => $this->productionStage($order),
            'expected_completion' => $this->expectedCompletion($order),
            'editable' => in_array($order->status, [Order::NEW, Order::PENDING_APPROVAL], true),
            'payments' => [
                'value' => $payments['value'], 'value_is_estimate' => $payments['value_is_estimate'],
                'paid' => $payments['paid'], 'balance' => $payments['balance'], 'status' => $payments['status'],
            ],
            'lines' => $order->lines->map(fn ($l) => [
                'id' => $l->id, 'qty' => $l->qty, 'qty_dispatched' => $l->qty_dispatched, 'size' => $l->size, 'remarks' => $l->remarks,
                'customization' => $l->customization,
                'reference_image' => $l->reference_image ? \Illuminate\Support\Facades\Storage::disk('public')->url($l->reference_image) : null,
                'target_wt_min' => $l->target_wt_min, 'target_wt_max' => $l->target_wt_max,
                'design' => ['code' => $l->design->code, 'name' => $l->design->name, 'photo' => $l->design->images->first()?->thumb_url],
                'karat' => $l->karat->label,
            ]),
            'dispatches' => $order->dispatches->map(fn ($d) => $d->only(['id', 'challan_no', 'date', 'pieces', 'net_wt', 'invoice_voucher_id', 'shipment_status', 'courier', 'tracking_no', 'tracking_url', 'shipped_at', 'delivered_at', 'delivery_remarks'])
                + ['documents' => $d->documents->map(fn ($doc) => ['id' => $doc->id, 'name' => basename($doc->path)])]),
        ]]);
    }

    /** Customisation and special instructions can change until the order is approved. */
    public function updateLine(Request $request, Order $order, OrderLine $line): JsonResponse
    {
        abort_unless($order->retailer_id === $this->retailer($request)->id && $line->order_id === $order->id, 404);
        if (! in_array($order->status, [Order::NEW, Order::PENDING_APPROVAL], true)) {
            throw new LedgerException('This order is already approved. Raise a customisation request instead.');
        }

        $data = $request->validate([
            'customization' => ['nullable', 'string', 'max:1000'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'reference' => ['nullable', 'image', 'max:8192'],
        ]);

        if ($request->hasFile('reference')) {
            $stored = app(\App\Services\Media\ImageStore::class)->store($request->file('reference'), $order->tenant_id, 'order-references');
            if ($line->reference_image) {
                app(\App\Services\Media\ImageStore::class)->delete($line->reference_image, str_replace('.webp', '_thumb.webp', $line->reference_image));
            }
            $line->reference_image = $stored['path'];
        }
        $line->fill(\Illuminate\Support\Arr::only($data, ['customization', 'remarks']))->save();

        return response()->json(['ok' => true]);
    }

    public function document(Request $request, Dispatch $dispatch, \App\Models\Attachment $attachment)
    {
        abort_unless($dispatch->retailer_id === $this->retailer($request)->id, 404);
        abort_unless($attachment->attachable_type === $dispatch->getMorphClass() && $attachment->attachable_id === $dispatch->id, 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response($attachment->path);
    }

    /** Place an order from the cart (or explicit lines). */
    public function store(Request $request, Notify $notify): JsonResponse
    {
        $retailer = $this->retailer($request);
        $data = $request->validate([
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $items = CartItem::with('design:id,code,name,min_order_qty,availability')->where('retailer_id', $retailer->id)->get();
        if ($items->isEmpty()) {
            throw new LedgerException('Your cart is empty.');
        }

        // Minimum order quantity per design (all sizes and karats together).
        foreach ($items->groupBy('design_id') as $group) {
            $design = $group->first()->design;
            if ($design->availability === 'unavailable') {
                throw new LedgerException("{$design->name} ({$design->code}) is no longer available. Remove it from the cart.");
            }
            if ($group->sum('qty') < ($design->min_order_qty ?? 1)) {
                throw new LedgerException("{$design->name} ({$design->code}) needs at least {$design->min_order_qty} pieces.");
            }
        }

        $order = DB::transaction(function () use ($retailer, $items, $data) {
            $order = $this->production->createOrder($retailer, $items->map(fn ($i) => $i->only([
                'design_id', 'karat_id', 'size', 'qty', 'target_wt_min', 'target_wt_max', 'remarks',
            ]))->all(), $data, 'portal');

            CartItem::where('retailer_id', $retailer->id)->delete();

            return $order;
        });

        $notify->staffWith('orders.manage', 'New portal order', "{$retailer->shop_name}: {$order->order_no}, {$items->sum('qty')} pieces.", "/staff/orders/{$order->id}", 'order.new');
        $notify->staffUser($retailer->salesperson_id, 'New order from your retailer', "{$retailer->shop_name}: {$order->order_no}", "/staff/orders/{$order->id}", 'order.new');
        $notify->orderStatus($order, 'Received. We will confirm it shortly.', 'order.received');

        return response()->json(['data' => $order->only(['id', 'order_no', 'status'])], 201);
    }

    /** Put a past order's lines back into the cart. */
    public function reorder(Request $request, Order $order): JsonResponse
    {
        $retailer = $this->retailer($request);
        abort_unless($order->retailer_id === $retailer->id, 404);

        DB::transaction(function () use ($order, $retailer, $request) {
            foreach ($order->lines as $l) {
                CartItem::create($l->only(['design_id', 'karat_id', 'size', 'qty', 'target_wt_min', 'target_wt_max', 'remarks'])
                    + ['retailer_id' => $retailer->id, 'retailer_user_id' => $request->user('retailer')->id]);
            }
        });

        return response()->json(['ok' => true, 'lines' => $order->lines()->count()]);
    }

    /** Map the internal status onto the retailer's timeline. */
    private function stage(Order $order): string
    {
        return match ($order->status) {
            Order::NEW, Order::PENDING_APPROVAL => 'received',
            Order::APPROVED, Order::WORK_ORDER_CREATED => 'approved',
            Order::MATERIAL_ISSUED, Order::IN_PRODUCTION, Order::REWORK_REQUIRED => 'in_production',
            Order::UNDER_QC, Order::PRODUCTION_COMPLETED => 'quality_check',
            Order::READY_FOR_DISPATCH => 'ready',
            Order::PARTIALLY_DISPATCHED, Order::DISPATCHED => 'dispatched',
            Order::DELIVERED, Order::CLOSED => 'delivered',
            default => $order->status,   // on_hold, cancelled
        };
    }

    /** The stage most of the work is at (e.g. "Polishing"), without kaarigar names. */
    private function productionStage(Order $order): ?string
    {
        if (! in_array($order->status, [Order::MATERIAL_ISSUED, Order::IN_PRODUCTION, Order::REWORK_REQUIRED], true)) {
            return null;
        }

        return $order->lines->flatMap->workOrders->filter->isActive()
            ->map(fn ($w) => $w->steps->sortBy('sequence')->first(fn ($s) => in_array($s->status, ['pending', 'in_progress'], true))?->process?->name)
            ->filter()->countBy()->sortDesc()->keys()->first();
    }

    /** Due date if agreed, else approval + the longest production time of its designs. */
    private function expectedCompletion(Order $order): ?string
    {
        if ($order->due_date) {
            return $order->due_date->toDateString();
        }
        $days = $order->lines->map(fn ($l) => $l->design->production_days)->filter()->max();

        return $days && $order->approved_at ? $order->approved_at->copy()->addDays($days)->toDateString() : null;
    }
}
