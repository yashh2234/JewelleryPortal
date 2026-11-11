<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Retailer;
use App\Services\Accounting\Books;
use App\Services\Accounting\OrderPayments;
use App\Services\Accounting\Vouchers;
use App\Services\Production\Production;
use App\Support\Money;
use App\Support\Tenancy\TenantRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sales orders through their whole life (see Order for the 13 statuses).
 * People move: new → pending approval → approved, hold, cancel, close.
 * Production, QC, dispatch and delivery move the rest by themselves.
 */
class OrderController extends Controller
{
    /** Filter groups used by the dashboard tiles. */
    private const GROUPS = [
        'awaiting' => [Order::NEW, Order::PENDING_APPROVAL],
        'production' => [Order::APPROVED, Order::WORK_ORDER_CREATED, Order::MATERIAL_ISSUED, Order::IN_PRODUCTION, Order::UNDER_QC, Order::REWORK_REQUIRED, Order::PRODUCTION_COMPLETED],
        'pending' => [Order::NEW, Order::PENDING_APPROVAL, Order::APPROVED, Order::WORK_ORDER_CREATED, Order::MATERIAL_ISSUED, Order::IN_PRODUCTION, Order::UNDER_QC, Order::REWORK_REQUIRED, Order::PRODUCTION_COMPLETED, Order::READY_FOR_DISPATCH, Order::PARTIALLY_DISPATCHED, Order::ON_HOLD],
        'completed' => [Order::DISPATCHED, Order::DELIVERED, Order::CLOSED],
    ];

    public function __construct(private Production $production, private OrderPayments $payments) {}

    public function index(Request $request): JsonResponse
    {
        $statuses = $request->filled('status')
            ? collect(explode(',', $request->string('status')))->flatMap(fn ($s) => self::GROUPS[$s] ?? [$s])->all()
            : null;

        $orders = Order::query()
            ->with('retailer:id,shop_name,city')
            ->withCount('lines')
            ->withSum('lines as qty_total', 'qty')
            ->withSum('lines as qty_dispatched_total', 'qty_dispatched')
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->when($request->filled('retailer_id'), fn ($q) => $q->where('retailer_id', $request->integer('retailer_id')))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->string('source')))
            ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', $request->string('payment_status')))
            ->when($request->boolean('overdue'), fn ($q) => $q->whereNotNull('due_date')->where('due_date', '<', today()->toDateString())->whereIn('status', self::GROUPS['pending']))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q
                ->where('order_no', 'like', '%'.$request->string('search').'%')
                ->orWhereHas('retailer', fn ($r) => $r->where('shop_name', 'like', '%'.$request->string('search').'%'))))
            ->latest('id')
            ->paginate($request->integer('per_page', 50));

        return response()->json($orders);
    }

    public function show(Order $order): JsonResponse
    {
        $order->load([
            'retailer.salesperson:id,name', 'approver:id,name', 'lines.design.images', 'lines.karat:id,label',
            'lines.workOrders:id,wo_no,status,qty,due_date',
            'dispatches:id,order_id,challan_no,date,pieces,gross_wt,net_wt,fine_wt,shipment_status,courier,tracking_no,tracking_url,delivered_at,invoice_voucher_id',
        ]);

        // Sales checks the retailer's metal / credit position before approving.
        $books = app(Books::class);
        $balance = $books->partyBalance($books->party($order->retailer));

        $receipts = \App\Models\Voucher::where('type', 'receipt')->where('source_type', 'order')->where('source_id', $order->id)
            ->latest('id')->get(['id', 'voucher_no', 'date', 'amount', 'status', 'meta']);

        return response()->json([
            'data' => $order,
            'position' => [
                'outstanding' => $balance['money'],
                'metal' => $balance['fine'],
                'credit_limit' => $order->retailer->credit_limit,
                'over_limit' => Money::compare($order->retailer->credit_limit, '0') > 0
                    && Money::compare($balance['money'], $order->retailer->credit_limit) > 0,
            ],
            'payments' => $this->payments->summary($order) + ['receipts' => $receipts],
            'flow' => Order::FLOW,
        ]);
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
            'lines.*.customization' => ['nullable', 'string', 'max:1000'],
        ]);

        $order = $this->production->createOrder(Retailer::findOrFail($data['retailer_id']), $data['lines'], $data);

        return response()->json(['data' => $order->load('lines', 'retailer')], 201);
    }

    public function submit(Order $order): JsonResponse
    {
        return response()->json(['data' => $this->production->submitForApproval($order)]);
    }

    public function approve(Order $order): JsonResponse
    {
        return response()->json(['data' => $this->production->approve($order)]);
    }

    /** Older clients. */
    public function confirm(Order $order): JsonResponse
    {
        return $this->approve($order);
    }

    public function close(Order $order): JsonResponse
    {
        return response()->json(['data' => $this->production->close($order)]);
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

    /** Advance or part payment received against this order (posts a receipt voucher). */
    public function advance(Request $request, Order $order, Vouchers $vouchers, Books $books): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'mode' => ['required', Rule::in(['cash', 'bank'])],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'narration' => ['nullable', 'string', 'max:255'],
        ]);

        $voucher = $vouchers->receipt(
            $books->party($order->retailer), (string) $data['amount'], $data['mode'], $data['date'] ?? today()->toDateString(),
            $data['narration'] ?? "Against order {$order->order_no}",
            ['source_type' => 'order', 'source_id' => $order->id],
        );
        $this->payments->refresh($order);

        return response()->json(['data' => $voucher->only(['id', 'voucher_no', 'amount']), 'payments' => $this->payments->summary($order->fresh())], 201);
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
