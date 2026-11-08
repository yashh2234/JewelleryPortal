<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Support\Tenancy\TenantRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Category::orderBy('sort')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => Category::create($this->validated($request))], 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $category->update($this->validated($request, $category));

        return response()->json(['data' => $category]);
    }

    public function destroy(Category $category): JsonResponse
    {
        $category->delete(); // designs keep working with category_id = null

        return response()->json(['ok' => true]);
    }

    private function validated(Request $request, ?Category $category = null): array
    {
        return $request->validate([
            'name' => [$category ? 'sometimes' : 'required', 'string', 'max:100'],
            'parent_id' => ['nullable', TenantRules::exists('categories'), Rule::notIn(array_filter([$category?->id]))],
            'sort' => ['sometimes', 'integer', 'min:0'],
        ]);
    }
}
