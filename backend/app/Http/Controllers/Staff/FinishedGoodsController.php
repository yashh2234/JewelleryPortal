<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Ahc;
use App\Models\Dispatch;
use App\Models\FinishedItem;
use App\Models\HallmarkLot;
use App\Models\Karat;
use App\Models\Order;
use App\Models\Retailer;
use App\Services\Production\Dispatching;
use App\Services\Production\Hallmarking;
use App\Services\Production\Production;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRules;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Finished pieces, QC, hallmarking (Module 6) and dispatch. */
class FinishedGoodsController extends Controller
{
    public function __construct(
        private Production $production,
        private Hallmarking $hallmarking,
        private Dispatching $dispatching,
    ) {}

    // ── Finished pieces & QC ────────────────────────────────────────────────

    public function items(Request $request): JsonResponse
    {
        $items = FinishedItem::query()
            ->with(['design:id,code,name', 'karat:id,label', 'workOrder:id,wo_no', 'orderLine.order:id,order_no,retailer_id'])
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', explode(',', $request->string('status'))))
            ->when($request->filled('qc'), fn ($q) => $q->where('qc_status', $request->string('qc')))
            ->when($request->filled('karat_id'), fn ($q) => $q->where('karat_id', $request->integer('karat_id')))
            ->when($request->has('hallmarked'), fn ($q) => $request->boolean('hallmarked') ? $q->whereNotNull('huid') : $q->whereNull('huid'))
            ->when($request->filled('order_id'), fn ($q) => $q->whereHas('orderLine', fn ($l) => $l->where('order_id', $request->integer('order_id'))))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q
                ->where('tag_no', 'like', '%'.$request->string('search').'%')
                ->orWhere('huid', strtoupper((string) $request->string('search')))))
            ->latest('id')
            ->paginate($request->integer('per_page', 100));

        return response()->json($items);
    }

    public function qc(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => [TenantRules::exists('finished_items')],
            'result' => ['required', Rule::in(['passed', 'failed'])],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $n = FinishedItem::whereIn('id', $data['item_ids'])->where('status', FinishedItem::IN_STOCK)->get()
            ->each(fn ($i) => $i->forceFill(['qc_status' => $data['result'], 'qc_remarks' => $data['remarks'] ?? null])->save())
            ->count();

        return response()->json(['updated' => $n]);
    }

    public function rework(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['integer'],
            'process_ids' => ['required', 'array', 'min:1'],
            'process_ids.*' => [TenantRules::exists('processes')],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $wo = $this->production->rework($data['item_ids'], $data['process_ids'], $data['reason']);

        return response()->json(['data' => $wo->only(['id', 'wo_no'])], 201);
    }

    // ── AHCs ────────────────────────────────────────────────────────────────

    public function ahcs(): JsonResponse
    {
        return response()->json(['data' => Ahc::orderBy('name')->get()]);
    }

    public function saveAhc(Request $request, ?Ahc $ahc = null): JsonResponse
    {
        $data = $request->validate([
            'name' => [$ahc ? 'sometimes' : 'required', 'string', 'max:150'],
            'bis_recognition_no' => ['nullable', 'string', 'max:50'],
            'city' => ['nullable', 'string', 'max:100'],
            'contact' => ['nullable', 'string', 'max:150'],
            'charge_per_piece' => ['nullable', 'decimal:0,2', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $ahc ? $ahc->update($data) : $ahc = Ahc::create($data);

        return response()->json(['data' => $ahc], $request->isMethod('post') ? 201 : 200);
    }

    // ── Hallmark lots ───────────────────────────────────────────────────────

    public function lots(Request $request): JsonResponse
    {
        return response()->json(HallmarkLot::with(['ahc:id,name', 'karat:id,label'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest('id')->paginate(50));
    }

    public function lot(HallmarkLot $lot): JsonResponse
    {
        return response()->json(['data' => $lot->load(['ahc', 'karat', 'items.finishedItem.design:id,code,name'])]);
    }

    public function createLot(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ahc_id' => ['required', TenantRules::exists('ahcs')],
            'karat_id' => ['required', TenantRules::exists('karats')],
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['integer'],
        ]);

        $lot = $this->hallmarking->createLot(Ahc::findOrFail($data['ahc_id']), Karat::findOrFail($data['karat_id']), $data['item_ids']);

        return response()->json(['data' => $lot], 201);
    }

    public function sendLot(Request $request, HallmarkLot $lot): JsonResponse
    {
        $data = $request->validate(['manak_request_no' => ['nullable', 'string', 'max:50']]);

        return response()->json(['data' => $this->hallmarking->send($lot, $data['manak_request_no'] ?? null)]);
    }

    public function returnLot(Request $request, HallmarkLot $lot): JsonResponse
    {
        $data = $request->validate([
            'results' => ['required', 'array', 'min:1'],
            'results.*.finished_item_id' => ['required', 'integer'],
            'results.*.result' => ['required', Rule::in(['pass', 'fail'])],
            'results.*.huid' => ['nullable', 'string', 'max:6'],
            'results.*.returned_wt' => ['required', 'decimal:0,3', 'gt:0'],
            'results.*.remarks' => ['nullable', 'string', 'max:255'],
            'charges' => ['nullable', 'decimal:0,2', 'min:0'],
        ]);

        $lot = $this->hallmarking->receive($lot, $data['results'], isset($data['charges']) ? (string) $data['charges'] : null);

        return response()->json(['data' => $lot->load(['items.finishedItem'])]);
    }

    /** Parse the AHC's HUID list (CSV) into results for review. Nothing is saved. */
    public function importHuids(Request $request, HallmarkLot $lot): JsonResponse
    {
        $request->validate(['file' => ['required_without:text', 'file', 'mimes:csv,txt', 'max:2048'], 'text' => ['required_without:file', 'string']]);
        $csv = $request->hasFile('file') ? file_get_contents($request->file('file')->getRealPath()) : $request->string('text');

        return response()->json(['data' => $this->hallmarking->parseHuidCsv($lot, (string) $csv)]);
    }

    /** Lot sheet matching what staff type into Manak Online. */
    public function lotSheet(HallmarkLot $lot, TenantContext $context): Response
    {
        $lot->load(['ahc', 'karat', 'items.finishedItem.design.category']);

        return Pdf::loadView('pdf.hallmark-lot', ['lot' => $lot, 'tenant' => $context->get()])->stream(str_replace('/', '-', $lot->lot_no).'.pdf');
    }

    // ── Dispatch ────────────────────────────────────────────────────────────

    public function dispatches(Request $request): JsonResponse
    {
        return response()->json(Dispatch::with(['retailer:id,shop_name', 'order:id,order_no'])
            ->when($request->filled('retailer_id'), fn ($q) => $q->where('retailer_id', $request->integer('retailer_id')))
            ->latest('id')->paginate(50));
    }

    public function dispatch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'retailer_id' => ['required', TenantRules::exists('retailers')],
            'order_id' => ['nullable', TenantRules::exists('orders')],
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['integer'],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $dispatch = $this->dispatching->dispatch(
            Retailer::findOrFail($data['retailer_id']),
            $data['item_ids'],
            isset($data['order_id']) ? Order::find($data['order_id']) : null,
            array_filter(['date' => $data['date'] ?? null, 'remarks' => $data['remarks'] ?? null]),
        );

        return response()->json(['data' => $dispatch], 201);
    }

    public function challan(Dispatch $dispatch, TenantContext $context): Response
    {
        $dispatch->load(['retailer', 'order', 'lines.item.design', 'lines.item.karat']);

        return Pdf::loadView('pdf.challan', ['d' => $dispatch, 'tenant' => $context->get()])->stream(str_replace('/', '-', $dispatch->challan_no).'.pdf');
    }
}
