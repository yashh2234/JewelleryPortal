<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\RouteTemplate;
use App\Support\Tenancy\TenantRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class RouteTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => RouteTemplate::with(['category:id,name', 'steps.process:id,name'])->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $template = $this->save(new RouteTemplate, $this->validated($request));

        return response()->json(['data' => $template], 201);
    }

    public function update(Request $request, RouteTemplate $routeTemplate): JsonResponse
    {
        return response()->json(['data' => $this->save($routeTemplate, $this->validated($request, true))]);
    }

    public function destroy(RouteTemplate $routeTemplate): JsonResponse
    {
        $routeTemplate->delete(); // work orders keep their own copy of the route

        return response()->json(['ok' => true]);
    }

    private function save(RouteTemplate $template, array $data): RouteTemplate
    {
        DB::transaction(function () use ($template, $data) {
            $template->fill(Arr::except($data, ['process_ids']))->save();

            if ($template->is_default) {
                RouteTemplate::whereKeyNot($template->id)->where('category_id', $template->category_id)
                    ->where('is_default', true)->get()->each->update(['is_default' => false]);
            }

            if (array_key_exists('process_ids', $data)) {
                $template->steps()->delete();
                foreach (array_values($data['process_ids']) as $i => $processId) {
                    $template->steps()->create(['process_id' => $processId, 'sequence' => $i + 1]);
                }
            }
        });

        return $template->load(['category:id,name', 'steps.process:id,name']);
    }

    private function validated(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:100'],
            'category_id' => ['nullable', TenantRules::exists('categories')],
            'is_default' => ['sometimes', 'boolean'],
            'process_ids' => [$required, 'array', 'min:1'],
            'process_ids.*' => [TenantRules::exists('processes')],
        ]);
    }
}
