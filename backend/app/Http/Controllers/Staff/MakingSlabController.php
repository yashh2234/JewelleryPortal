<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\MakingSlab;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MakingSlabController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => MakingSlab::orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $slab = DB::transaction(fn () => $this->applyDefault(MakingSlab::create($this->validated($request))));

        return response()->json(['data' => $slab], 201);
    }

    public function update(Request $request, MakingSlab $makingSlab): JsonResponse
    {
        DB::transaction(function () use ($request, $makingSlab) {
            $makingSlab->update($this->validated($request, true));
            $this->applyDefault($makingSlab);
        });

        return response()->json(['data' => $makingSlab]);
    }

    public function destroy(MakingSlab $makingSlab): JsonResponse
    {
        $makingSlab->delete(); // retailers fall back to the default slab

        return response()->json(['ok' => true]);
    }

    private function applyDefault(MakingSlab $slab): MakingSlab
    {
        if ($slab->is_default) {
            MakingSlab::whereKeyNot($slab->id)->where('is_default', true)->get()->each->update(['is_default' => false]);
        }

        return $slab;
    }

    private function validated(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:100'],
            'basis' => [$required, Rule::in(MakingSlab::BASES)],
            'rate' => [$required, 'decimal:0,3', 'min:0'],
            'is_default' => ['sometimes', 'boolean'],
        ]);
    }
}
