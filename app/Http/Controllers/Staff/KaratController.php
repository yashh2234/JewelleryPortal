<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Karat;
use App\Support\Tenancy\TenantRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Karats are never deleted (ledger lines reference them); deactivate instead. */
class KaratController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Karat::orderBy('sort')->orderByDesc('purity_pct')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $karat = Karat::create($this->validated($request));

        return response()->json(['data' => $karat], 201);
    }

    public function update(Request $request, Karat $karat): JsonResponse
    {
        $karat->update($this->validated($request, $karat));

        return response()->json(['data' => $karat]);
    }

    private function validated(Request $request, ?Karat $karat = null): array
    {
        $required = $karat ? 'sometimes' : 'required';

        return $request->validate([
            'label' => [$required, 'string', 'max:10', TenantRules::unique('karats', 'label')->ignore($karat?->id)],
            'purity_pct' => [$required, 'decimal:0,3', 'gt:0', 'max:100'],
            'sort' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
