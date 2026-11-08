<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Retailer;
use App\Services\Production\Production;
use App\Support\Tenancy\TenantRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Retailer orders: placed → confirmed → in production → ready → dispatched. */
class OrderController extends Controller
{
    public function __construct(private Production $production) {}

    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->with('retailer:id,shop_name,city')
            ->withCount('lines')
            ->withSum('lines as qty_total', 'qty')
            ->withSum('lines as qty_dispatched_total', 'qty_dispatched')
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', explode(',', $request->string('status'))))
            ->when($request->filled('retailer_id'), fn ($q) => $q->where('retailer_id', $request->integer('retailer_id')))
            ->when($request->filled('search'), fn ($q) => $q->where('order_no', 'like', '%'.$request->string('search').'%'))
            ->latest('id')
            ->paginate($request->integer('per_page', 50));

        return response()->json($orders);
    }

    public function show(Order $order): JsonResponse
    {
        $order->load([
            'retailer', 'lines.design.images', 'lines.karat:id,label',
            'lines.workOrders:id,wo_no,status,qty,due_date', 'dispatches:id,order_id,challan_no,date,pieces,fine_wt',
        ]);

        return response()->json(['data' => $order]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'retailer_id' => ['required', TenantRules::exists('retailers')],
            'due_date' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.design_id' => ['required', TenantRules::exists('designs')],
            'lines.*.karat_id' => ['required', TenantRules::exists('karats')],
            'lines.*.size' => ['nullable', 'string', 'max:20'],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:100000'],
            'lines.*.target_wt_min' => ['nullable', 'decimal:0,3', 'min:0'],
            'lines.*.target_wt_max' => ['nullable', 'decimal:0,3', 'min:0'],
            'lines.*.stone_details' => ['nullable', 'string', 'max:255'],
            'lines.*.remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $order = $this->production->createOrder(Retailer::findOrFail($data['retailer_id']), $data['lines'], $data);

        return response()->json(['data' => $order->load('lines', 'retailer')], 201);
    }

    public function confirm(Order $order): JsonResponse
    {
        return response()->json(['data' => $this->production->confirm($order)]);
    }

    public function hold(Order $order): JsonResponse
    {
        return response()->json(['data' => $this->production->hold($order)]);
    }

    public function resume(Order $order): JsonResponse
    {
        return response()->json(['data' => $this->production->resume($order)]);
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json(['data' => $this->production->cancel($order, $data['reason'])]);
    }

    /** Create work orders from the order's lines (default: one per line). */
    public function workOrders(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'groups' => ['nullable', 'array'],
            'groups.*' => ['array', 'min:1'],
            'groups.*.*' => ['integer'],
        ]);

        $wos = $this->production->createWorkOrders($order, $data['groups'] ?? null);

        return response()->json(['data' => collect($wos)->map->only(['id', 'wo_no', 'qty'])], 201);
    }
}
