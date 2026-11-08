<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Support\Tenancy\TenantRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CollectionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Collection::withCount('designs')->orderBy('sort')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'sort' => ['sometimes', 'integer', 'min:0']]);
        $data['slug'] = Str::slug($data['name']);
        $request->merge(['slug' => $data['slug']])->validate(['slug' => [TenantRules::unique('collections', 'slug')]]);

        return response()->json(['data' => Collection::create($data)], 201);
    }

    public function update(Request $request, Collection $collection): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:100'], 'sort' => ['sometimes', 'integer', 'min:0']]);

        if (isset($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
            $request->merge(['slug' => $data['slug']])
                ->validate(['slug' => [TenantRules::unique('collections', 'slug')->ignore($collection->id)]]);
        }

        $collection->update($data);

        return response()->json(['data' => $collection]);
    }

    public function destroy(Collection $collection): JsonResponse
    {
        $collection->delete();

        return response()->json(['ok' => true]);
    }
}
