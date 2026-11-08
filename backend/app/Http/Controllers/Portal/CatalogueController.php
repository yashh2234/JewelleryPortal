<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Design;
use App\Models\Karat;
use App\Models\Retailer;
use App\Services\Portal\Catalogue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Retailer catalogue: published designs the retailer is allowed to see. */
class CatalogueController extends Controller
{
    public function __construct(private Catalogue $catalogue) {}

    private function retailer(Request $request): Retailer
    {
        return $request->user('retailer')->retailer->loadMissing('makingSlab');
    }

    public function filters(Request $request): JsonResponse
    {
        $retailer = $this->retailer($request);
        $visible = $this->catalogue->visible($retailer);
        $allowed = $retailer->collections()->pluck('collections.id');

        return response()->json([
            'categories' => Category::whereIn('id', (clone $visible)->select('category_id'))->orderBy('sort')->get(['id', 'name']),
            'collections' => Collection::when($allowed->isNotEmpty(), fn ($q) => $q->whereIn('id', $allowed))
                ->whereHas('designs', fn ($q) => $q->where('is_published', true))->orderBy('sort')->get(['id', 'name', 'slug']),
            'karats' => Karat::where('is_active', true)->orderBy('sort')->get(['id', 'label', 'purity_pct']),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $retailer = $this->retailer($request);
        $d = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer'],
            'collection' => ['nullable', 'string', 'max:100'],
            'karat_id' => ['nullable', 'integer'],
            'wt_min' => ['nullable', 'numeric', 'min:0'],
            'wt_max' => ['nullable', 'numeric', 'min:0'],
        ]);

        $designs = $this->catalogue->visible($retailer)
            ->with(['category:id,name', 'karats:id,label,purity_pct', 'images' => fn ($q) => $q->orderBy('sort')])
            ->when($d['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('code', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%")))
            ->when($d['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->when($d['collection'] ?? null, fn ($q, $slug) => $q->whereHas('collections', fn ($c) => $c->where('slug', $slug)))
            ->when($d['karat_id'] ?? null, fn ($q, $id) => $q->whereHas('karats', fn ($k) => $k->where('karats.id', $id)))
            ->when(isset($d['wt_min']), fn ($q) => $q->where('approx_weight_max', '>=', $d['wt_min']))
            ->when(isset($d['wt_max']), fn ($q) => $q->where('approx_weight_min', '<=', $d['wt_max']))
            ->orderByDesc('id')
            ->paginate(24);

        $designs->getCollection()->transform(fn (Design $design) => $this->present($design, $retailer, false));

        return response()->json($designs);
    }

    public function show(Request $request, string $code): JsonResponse
    {
        $retailer = $this->retailer($request);
        $design = $this->catalogue->visible($retailer)->with(['category:id,name', 'karats:id,label,purity_pct', 'images', 'collections:id,name'])
            ->where('code', $code)->firstOrFail();

        return response()->json(['data' => $this->present($design, $retailer, true)]);
    }

    public function goldRate(): JsonResponse
    {
        $karats = Karat::pluck('label', 'id');

        return response()->json(['date' => today()->toDateString(), 'data' => collect($this->catalogue->rates())
            ->map(fn ($rate, $id) => ['karat_id' => $id, 'karat' => $karats[$id] ?? '?', 'rate_per_g' => $rate])->values()]);
    }

    private function present(Design $design, Retailer $retailer, bool $full): array
    {
        return [
            'id' => $design->id, 'code' => $design->code, 'name' => $design->name,
            'category' => $design->category?->name,
            'approx_weight_min' => $design->approx_weight_min, 'approx_weight_max' => $design->approx_weight_max,
            'sizes' => $design->sizes ?? [],
            'description' => $full ? $design->description : null,
            'karats' => $design->karats->map(fn ($k) => [
                'id' => $k->id, 'label' => $k->label,
                'estimate' => $this->catalogue->estimate($design, $k->id, (string) $k->purity_pct, $retailer),
            ]),
            'images' => $full ? $design->images->map->only(['id', 'url', 'thumb_url']) : $design->images->take(1)->map->only(['id', 'url', 'thumb_url']),
            'collections' => $full ? $design->collections->pluck('name') : [],
        ];
    }
}
