<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Process;
use App\Models\ProcessWastageRule;
use App\Support\Tenancy\TenantRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Processes are deactivated, never deleted (job cards reference them). */
class ProcessController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Process::with('wastageRules')->orderBy('sequence')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $process = Process::create($this->validated($request));

        return response()->json(['data' => $process->load('wastageRules')], 201);
    }

    public function update(Request $request, Process $process): JsonResponse
    {
        $process->update($this->validated($request, $process));

        return response()->json(['data' => $process->load('wastageRules')]);
    }

    /** Replace the per-karat wastage overrides for a process. */
    public function syncWastageRules(Request $request, Process $process): JsonResponse
    {
        $data = $request->validate([
            'rules' => ['present', 'array'],
            'rules.*.karat_id' => ['required', 'distinct', TenantRules::exists('karats')],
            'rules.*.allowed_wastage_pct' => ['required', 'decimal:0,3', 'min:0', 'max:100'],
        ]);

        DB::transaction(function () use ($process, $data) {
            $keep = collect($data['rules'])->pluck('karat_id')->all();

            $process->wastageRules()->whereNotIn('karat_id', $keep)->get()->each->delete();

            foreach ($data['rules'] as $rule) {
                ProcessWastageRule::updateOrCreate(
                    ['process_id' => $process->id, 'karat_id' => $rule['karat_id']],
                    ['allowed_wastage_pct' => $rule['allowed_wastage_pct']],
                );
            }
        });

        return response()->json(['data' => $process->load('wastageRules')]);
    }

    private function validated(Request $request, ?Process $process = null): array
    {
        $required = $process ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:100', TenantRules::unique('processes', 'name')->ignore($process?->id)],
            'sequence' => ['sometimes', 'integer', 'min:0'],
            'default_wastage_pct' => [$required, 'decimal:0,3', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
