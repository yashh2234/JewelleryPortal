<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Dispatch;
use App\Models\DispatchLine;
use App\Models\FinishedItem;
use App\Models\Order;
use App\Models\Retailer;
use App\Services\Production\Dispatching;
use App\Services\Production\OrderProgress;
use App\Services\Production\Production;
use App\Services\Push\Notify;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRules;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/** Finished pieces, QC, storage and dispatch. */
class FinishedGoodsController extends Controller
{
    public function __construct(
        private Production $production,
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
            ->when($request->filled('order_id'), fn ($q) => $q->whereHas('orderLine', fn ($l) => $l->where('order_id', $request->integer('order_id'))))
            ->when($request->filled('search'), fn ($q) => $q->where('tag_no', 'like', '%'.$request->string('search').'%'))
            ->latest('id')
            ->paginate($request->integer('per_page', 100));

        return response()->json($items);
    }

    /** Spec §8 checklist. Every point must be ticked to pass. */
    public const QC_CHECKS = ['design', 'quantity', 'weight', 'purity', 'finishing', 'stone_setting'];

    /**
     * Quality check. Pass needs the whole checklist; a fail records the defects and,
     * when a route is given, sends the pieces straight back to production as rework.
     */
    public function qc(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => [TenantRules::exists('finished_items')],
            'result' => ['required', Rule::in(['passed', 'failed'])],
            'remarks' => ['nullable', 'string', 'max:500'],
            'checklist' => ['nullable', 'array'],
            'checklist.*' => ['boolean'],
            'defects' => ['nullable', 'string', 'max:255'],
            'rework_process_ids' => ['nullable', 'array'],
            'rework_process_ids.*' => [TenantRules::exists('processes')],
        ]);

        $checklist = array_merge(array_fill_keys(self::QC_CHECKS, $data['result'] === 'passed'), array_intersect_key($data['checklist'] ?? [], array_flip(self::QC_CHECKS)));

        if ($data['result'] === 'passed' && in_array(false, $checklist, true)) {
            throw ValidationException::withMessages(['checklist' => 'Tick every check to pass, or fail the pieces.']);
        }
        if ($data['result'] === 'failed' && empty($data['defects']) && empty($data['remarks'])) {
            throw ValidationException::withMessages(['defects' => 'Record what is wrong.']);
        }

        $items = FinishedItem::whereIn('id', $data['item_ids'])->where('status', FinishedItem::IN_STOCK)->get();
        $items->each(fn ($i) => $i->forceFill([
            'qc_status' => $data['result'], 'qc_remarks' => $data['remarks'] ?? null, 'qc_checklist' => $checklist,
            'qc_defects' => $data['result'] === 'failed' ? ($data['defects'] ?? null) : null,
            'qc_by' => $request->user('staff')->id, 'qc_at' => now(),
        ])->save());

        $rework = null;
        if ($data['result'] === 'failed' && ! empty($data['rework_process_ids']) && $items->isNotEmpty()) {
            $rework = $this->production->rework($items->pluck('id')->all(), $data['rework_process_ids'], $data['defects'] ?? $data['remarks'] ?? 'QC failed');
        } elseif ($data['result'] === 'failed') {
            app(Notify::class)->staffWith('production.manage', 'Quality check failed', $items->count().' piece(s): '.($data['defects'] ?? $data['remarks']), '/staff/finished', 'qc.failed');
        }

        app(OrderProgress::class)->forItems($items);

        return response()->json(['updated' => $items->count(), 'rework' => $rework?->only(['id', 'wo_no'])]);
    }

    /** Where the pieces sit (tray, locker, safe). */
    public function location(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => [TenantRules::exists('finished_items')],
            'storage_location' => ['nullable', 'string', 'max:60'],
        ]);

        $n = FinishedItem::whereIn('id', $data['item_ids'])->update(['storage_location' => $data['storage_location'] ?? null]);

        return response()->json(['updated' => $n]);
    }

    /** Every movement of one piece: ledger lines, QC, dispatch (spec §7). */
    public function history(FinishedItem $item): JsonResponse
    {
        $item->load(['design:id,code,name', 'karat:id,label', 'workOrder:id,wo_no', 'orderLine.order:id,order_no']);

        $events = collect([['at' => $item->created_at, 'event' => 'Finished', 'detail' => "From {$item->workOrder?->wo_no}, {$item->gross_wt} g gross", 'by' => null]]);

        foreach (DispatchLine::with('dispatch:id,challan_no,date,shipment_status,created_at')->where('finished_item_id', $item->id)->get() as $d) {
            $events->push(['at' => $d->dispatch->created_at, 'event' => 'Dispatched', 'detail' => "{$d->dispatch->challan_no} · ".str_replace('_', ' ', $d->dispatch->shipment_status), 'by' => null]);
        }

        Activity::query()
            ->where('subject_type', $item->getMorphClass())->where('subject_id', $item->id)->where('event', 'updated')
            ->with('causer:id,name')->oldest('id')->get()
            ->each(function ($a) use ($events) {
                $changes = collect($a->properties['attributes'] ?? [])->only(['status', 'qc_status', 'qc_defects', 'storage_location']);
                if ($changes->isNotEmpty()) {
                    $events->push(['at' => $a->created_at, 'event' => 'Updated', 'detail' => $changes->map(fn ($v, $k) => str_replace('_', ' ', $k).': '.(is_scalar($v) ? $v : json_encode($v)))->implode(' · '), 'by' => $a->causer?->name]);
                }
            });

        return response()->json(['data' => $item, 'history' => $events->sortBy('at')->values()]);
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

    // ── Dispatch ────────────────────────────────────────────────────────────

    public function dispatches(Request $request): JsonResponse
    {
        return response()->json(Dispatch::with(['retailer:id,shop_name,city', 'order:id,order_no', 'documents'])
            ->when($request->filled('retailer_id'), fn ($q) => $q->where('retailer_id', $request->integer('retailer_id')))
            ->when($request->filled('shipment_status'), fn ($q) => $q->whereIn('shipment_status', explode(',', $request->string('shipment_status'))))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q
                ->where('challan_no', 'like', '%'.$request->string('search').'%')
                ->orWhere('tracking_no', 'like', '%'.$request->string('search').'%')))
            ->latest('id')->paginate(50));
    }

    public function couriers(): JsonResponse
    {
        return response()->json(['data' => array_keys(Dispatching::COURIERS), 'statuses' => Dispatching::SHIPMENT_STATUSES]);
    }

    public function updateShipment(Request $request, Dispatch $dispatch): JsonResponse
    {
        $data = $request->validate([
            'shipment_status' => ['sometimes', Rule::in(Dispatching::SHIPMENT_STATUSES)],
            'courier' => ['nullable', 'string', 'max:60'],
            'tracking_no' => ['nullable', 'string', 'max:60'],
            'tracking_url' => ['nullable', 'url', 'max:255'],
            'delivery_remarks' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json(['data' => $this->dispatching->updateShipment($dispatch, $data)->load(['retailer:id,shop_name', 'order:id,order_no', 'documents'])]);
    }

    /** Courier receipt, insurance slip, parcel photo: kept privately, served only to signed-in users. */
    public function uploadDocument(Request $request, Dispatch $dispatch, TenantContext $context): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:8192']]);
        $file = $request->file('file');
        $path = $file->storeAs("tenants/{$context->id()}/shipments/{$dispatch->id}", Str::uuid().'.'.$file->extension(), 'local');

        $doc = $dispatch->documents()->create(['kind' => 'shipment_doc', 'path' => $path]);

        return response()->json(['data' => $doc], 201);
    }

    public function document(Dispatch $dispatch, Attachment $attachment)
    {
        abort_unless($attachment->attachable_type === $dispatch->getMorphClass() && $attachment->attachable_id === $dispatch->id, 404);

        return Storage::disk('local')->response($attachment->path);
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
            'shipment_status' => ['nullable', Rule::in(['packing', 'dispatched', 'in_transit'])],
            'courier' => ['nullable', 'string', 'max:60'],
            'tracking_no' => ['nullable', 'string', 'max:60'],
            'tracking_url' => ['nullable', 'url', 'max:255'],
        ]);

        $dispatch = $this->dispatching->dispatch(
            Retailer::findOrFail($data['retailer_id']),
            $data['item_ids'],
            isset($data['order_id']) ? Order::find($data['order_id']) : null,
            array_filter(Arr::only($data, ['date', 'remarks', 'shipment_status', 'courier', 'tracking_no', 'tracking_url']), fn ($v) => $v !== null),
        );

        return response()->json(['data' => $dispatch], 201);
    }

    public function challan(Dispatch $dispatch, TenantContext $context): Response
    {
        $dispatch->load(['retailer', 'order', 'lines.item.design', 'lines.item.karat']);

        return Pdf::loadView('pdf.challan', ['d' => $dispatch, 'tenant' => $context->get()])->stream(str_replace('/', '-', $dispatch->challan_no).'.pdf');
    }
}
