<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Design;
use App\Models\MakingSlab;
use App\Services\Media\ImageStore;
use App\Support\PlanLimits;
use App\Support\Tenancy\TenantRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DesignController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $designs = Design::query()
            ->with(['category:id,name', 'karats:id,label', 'images' => fn ($q) => $q->limit(1)])
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($q) => $q
                ->where('code', 'like', '%'.$request->string('search').'%')
                ->orWhere('name', 'like', '%'.$request->string('search').'%')))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->when($request->has('published'), fn ($q) => $q->where('is_published', $request->boolean('published')))
            ->orderBy('code')
            ->paginate($request->integer('per_page', 30));

        return response()->json($designs);
    }

    public function show(Design $design): JsonResponse
    {
        return response()->json(['data' => $design->load(['category', 'karats', 'collections', 'images'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        PlanLimits::ensureRoomFor('designs');

        $design = DB::transaction(function () use ($data) {
            $design = Design::create(Arr::except($data, ['karat_ids', 'collection_ids']));
            $design->karats()->sync($data['karat_ids'] ?? []);
            $design->collections()->sync($data['collection_ids'] ?? []);

            return $design;
        });

        return response()->json(['data' => $design->load(['category', 'karats', 'collections', 'images'])], 201);
    }

    public function update(Request $request, Design $design): JsonResponse
    {
        $data = $this->validated($request, $design);

        DB::transaction(function () use ($design, $data) {
            $design->update(Arr::except($data, ['karat_ids', 'collection_ids']));

            if (array_key_exists('karat_ids', $data)) {
                $design->karats()->sync($data['karat_ids']);
            }
            if (array_key_exists('collection_ids', $data)) {
                $design->collections()->sync($data['collection_ids']);
            }
        });

        return response()->json(['data' => $design->load(['category', 'karats', 'collections', 'images'])]);
    }

    public function destroy(Design $design, ImageStore $images): JsonResponse
    {
        $paths = $design->images->flatMap(fn ($i) => [$i->path, $i->thumb_path])->all();

        $design->delete();
        $images->delete(...$paths);

        return response()->json(['ok' => true]);
    }

    private function validated(Request $request, ?Design $design = null): array
    {
        $required = $design ? 'sometimes' : 'required';

        return $request->validate([
            'code' => [$required, 'string', 'max:50', TenantRules::unique('designs', 'code')->ignore($design?->id)],
            'name' => [$required, 'string', 'max:150'],
            'category_id' => ['nullable', TenantRules::exists('categories')],
            'approx_weight_min' => ['nullable', 'decimal:0,3', 'min:0'],
            'approx_weight_max' => ['nullable', 'decimal:0,3', 'min:0', 'gte:approx_weight_min'],
            'sizes' => ['nullable', 'array'],
            'sizes.*' => ['string', 'max:20'],
            'making_charge' => ['nullable', 'decimal:0,3', 'min:0'],
            'making_type' => ['nullable', 'required_with:making_charge', Rule::in(MakingSlab::BASES)],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_published' => ['sometimes', 'boolean'],
            'stone_details' => ['nullable', 'string', 'max:255'],
            'specifications' => ['nullable', 'string', 'max:2000'],
            'min_order_qty' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'production_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'availability' => ['sometimes', Rule::in(\App\Models\Design::AVAILABILITY)],
            'karat_ids' => ['sometimes', 'array'],
            'karat_ids.*' => [TenantRules::exists('karats')],
            'collection_ids' => ['sometimes', 'array'],
            'collection_ids.*' => [TenantRules::exists('collections')],
        ]);
    }
}
