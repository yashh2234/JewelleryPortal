<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Kaarigar;
use App\Services\Metal\Statements;
use App\Support\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class KaarigarLedgerController extends Controller
{
    public function __construct(private Statements $statements) {}

    public function show(Request $request, Kaarigar $kaarigar): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json(['kaarigar' => $kaarigar->only(['id', 'name', 'phone', 'type'])] + $this->statements->kaarigar($kaarigar, $from, $to));
    }

    public function pdf(Request $request, Kaarigar $kaarigar, TenantContext $context): Response
    {
        [$from, $to] = $this->range($request);

        if (! $kaarigar->slip_token) {
            $kaarigar->forceFill(['slip_token' => Str::random(32)])->save();
        }

        return Pdf::loadView('pdf.kaarigar-statement', [
            'tenant' => $context->get(),
            'kaarigar' => $kaarigar,
            'from' => $from,
            'to' => $to,
            'statement' => $this->statements->kaarigar($kaarigar, $from, $to),
        ])->stream("khata-{$kaarigar->id}.pdf");
    }

    public function wastage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'group' => ['nullable', 'in:kaarigar,process'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $from = $data['from'] ?? today()->startOfMonth()->toDateString();
        $to = $data['to'] ?? today()->toDateString();

        return response()->json([
            'from' => $from,
            'to' => $to,
            'group' => $data['group'] ?? 'kaarigar',
            'data' => $this->statements->wastage($data['group'] ?? 'kaarigar', $from, $to),
        ]);
    }

    /** @return array{0: string, 1: ?string} */
    private function range(Request $request): array
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return [$data['from'] ?? today()->startOfMonth()->toDateString(), $data['to'] ?? null];
    }
}
