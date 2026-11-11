<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\KaarigarRequest;
use App\Services\Metal\LedgerException;
use App\Services\Metal\MetalOperations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Store inbox for kaarigar app requests: approve returns, resolve disputes. */
class KaarigarRequestController extends Controller
{
    public function __construct(private MetalOperations $ops) {}

    public function index(Request $request): JsonResponse
    {
        $rows = KaarigarRequest::with(['kaarigar:id,name', 'jobCard:id,card_no,karat_id,process_id', 'jobCard.karat:id,label,purity_pct', 'jobCard.process:id,name', 'transaction:id,voucher_no', 'attachments'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')), fn ($q) => $q->where('status', 'pending'))
            ->latest('id')
            ->paginate($request->integer('per_page', 50));

        return response()->json($rows);
    }

    /**
     * Approve. For a return, the store enters the weighed figures (defaults to what
     * the kaarigar reported) and the real receive is posted now.
     */
    public function approve(Request $request, KaarigarRequest $kaarigarRequest): JsonResponse
    {
        $data = $request->validate([
            'destination' => ['nullable', Rule::in(['vault', 'finished', 'wo'])],
            'work_gross' => ['nullable', 'decimal:0,3', 'min:0'],
            'stone' => ['nullable', 'decimal:0,3', 'min:0'],
            'scrap_gross' => ['nullable', 'decimal:0,3', 'min:0'],
            'raw_gross' => ['nullable', 'decimal:0,3', 'min:0'],
            'dust_gross' => ['nullable', 'decimal:0,3', 'min:0'],
            'rejected_gross' => ['nullable', 'decimal:0,3', 'min:0'],
            'rejected_pieces' => ['nullable', 'integer', 'min:0'],
            'purity' => ['nullable', 'decimal:0,3', 'gt:0', 'max:100'],
            'pieces' => ['nullable', 'integer', 'min:0'],
            'close' => ['boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $req = $kaarigarRequest;
        if ($req->status !== KaarigarRequest::PENDING) {
            throw new LedgerException('This request has already been handled.');
        }

        $result = DB::transaction(function () use ($req, $data) {
            $tx = null;
            $closed = null;

            if ($req->type === KaarigarRequest::RETURN) {
                // The store's weighed figures win; otherwise what the kaarigar reported.
                $asked = $req->data ?? [];
                $w = fn (string $k) => $data[$k] ?? $asked[$k] ?? null;
                $purity = $data['purity'] ?? null;
                $part = fn (?string $gross, array $extra = []) => $gross !== null && (float) $gross > 0
                    ? ['gross' => (string) $gross, 'purity' => $purity] + $extra : null;

                $tx = $this->ops->receiveParts(
                    $req->jobCard,
                    [
                        'work' => $part($w('work_gross'), ['stone' => (string) ($w('stone') ?? '0'), 'pieces' => $w('pieces')]),
                        'raw' => $part($w('raw_gross')),
                        'rejected' => $part($w('rejected_gross'), ['pieces' => $w('rejected_pieces')]),
                        'scrap' => $part($w('scrap_gross')),
                        'dust' => $part($w('dust_gross')),
                    ],
                    $data['destination'] ?? ($req->jobCard->wo_step_id ? 'wo' : 'finished'),
                    ['remarks' => 'From kaarigar app return request #'.$req->id],
                );

                if ($data['close'] ?? false) {
                    $closed = $this->ops->closeJobCard($req->jobCard);
                }
            }

            $req->forceFill([
                'status' => KaarigarRequest::APPROVED,
                'review_note' => $data['note'] ?? null,
                'reviewed_by' => Auth::guard('staff')->id(),
                'reviewed_at' => now(),
                'result_transaction_id' => $tx?->id,
            ])->save();

            return [$tx, $closed];
        });

        return response()->json([
            'data' => $req->fresh(['kaarigar', 'jobCard']),
            'transaction' => $result[0] ? MetalController::present($result[0]->load(['lines.from', 'lines.to', 'lines.karat'])) : null,
            'job_card' => $result[1] ? MetalController::presentCard($result[1]) : null,
        ]);
    }

    public function reject(Request $request, KaarigarRequest $kaarigarRequest): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);

        if ($kaarigarRequest->status !== KaarigarRequest::PENDING) {
            throw new LedgerException('This request has already been handled.');
        }

        $kaarigarRequest->forceFill([
            'status' => KaarigarRequest::REJECTED, 'review_note' => $data['note'],
            'reviewed_by' => Auth::guard('staff')->id(), 'reviewed_at' => now(),
        ])->save();

        return response()->json(['data' => $kaarigarRequest]);
    }
}
