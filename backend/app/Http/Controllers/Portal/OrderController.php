<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\FinishedItem;
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
    /** Retailer-facing timeline (spec): received → in production → quality check → ready → dispatched. */
    public const TIMELINE = ['received', 'in_production', 'quality_check', 'ready', 'dispatched'];

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
        $order->load(['lines.design.images', 'lines.karat:id,label', 'dispatches']);

        return response()->json(['data' => [
            'id' => $order->id, 'order_no' => $order->order_no, 'status' => $order->status, 'due_date' => $order->due_date?->toDateString(),
            'remarks' => $order->remarks, 'cancel_reason' => $order->cancel_reason, 'created_at' => $order->created_at,
            'stage' => $this->stage($order),
            'timeline' => self::TIMELINE,
            'lines' => $order->lines->map(fn ($l) => [
                'id' => $l->id, 'qty' => $l->qty, 'qty_dispatched' => $l->qty_dispatched, 'size' => $l->size, 'remarks' => $l->remarks,
                'target_wt_min' => $l->target_wt_min, 'target_wt_max' => $l->target_wt_max,
                'design' => ['code' => $l->design->code, 'name' => $l->design->name, 'photo' => $l->design->images->first()?->thumb_url],
                'karat' => $l->karat->label,
            ]),
            'dispatches' => $order->dispatches->map(fn ($d) => $d->only(['id', 'challan_no', 'date', 'pieces', 'net_wt', 'fine_wt', 'invoice_voucher_id'])),
        ]]);
    }

    /** Place an order from the cart (or explicit lines). */
    public function store(Request $request, Notify $notify): JsonResponse
    {
        $retailer = $this->retailer($request);
        $data = $request->validate([
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $items = CartItem::where('retailer_id', $retailer->id)->get();
        if ($items->isEmpty()) {
            throw new LedgerException('Your cart is empty.');
        }

        $order = DB::transaction(function () use ($retailer, $items, $data) {
            $order = $this->production->createOrder($retailer, $items->map(fn ($i) => $i->only([
                'design_id', 'karat_id', 'size', 'qty', 'target_wt_min', 'target_wt_max', 'remarks',
            ]))->all(), $data, 'portal');

            CartItem::where('retailer_id', $retailer->id)->delete();

            return $order;
        });

        $notify->staffWith('orders.manage', 'New portal order', "{$retailer->shop_name}: {$order->order_no}, {$items->sum('qty')} pieces.", "/app/staff/orders/{$order->id}");

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

    /** Map the internal status onto the retailer's five-step timeline. */
    private function stage(Order $order): string
    {
        return match ($order->status) {
            Order::PLACED => 'received',
            Order::CONFIRMED => 'in_production',
            Order::IN_PRODUCTION => FinishedItem::whereHas('orderLine', fn ($q) => $q->where('order_id', $order->id))->exists() ? 'quality_check' : 'in_production',
            Order::READY => 'ready',
            Order::PARTIALLY_DISPATCHED, Order::DISPATCHED, Order::CLOSED => 'dispatched',
            default => $order->status,   // on_hold, cancelled
        };
    }
}
