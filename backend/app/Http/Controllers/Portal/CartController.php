<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Retailer;
use App\Services\Portal\Catalogue;
use App\Support\Metal\Weight;
use App\Support\Money;
use App\Support\Tenancy\TenantRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The retailer's cart (shared by the shop's users), with an estimated summary. */
class CartController extends Controller
{
    public function __construct(private Catalogue $catalogue) {}

    private function retailer(Request $request): Retailer
    {
        return $request->user('retailer')->retailer->loadMissing('makingSlab');
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->summary($this->retailer($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $retailer = $this->retailer($request);
        $d = $this->validated($request);

        abort_unless($this->catalogue->visible($retailer)->whereKey($d['design_id'])->whereHas('karats', fn ($q) => $q->where('karats.id', $d['karat_id']))->exists(), 422, 'That design or karat is not available.');
        abort_if(\App\Models\Design::whereKey($d['design_id'])->value('availability') === 'unavailable', 422, 'This design is not available for orders right now.');

        // Same design + karat + size + weight + remarks → add to the existing line.
        $existing = CartItem::where('retailer_id', $retailer->id)->where('design_id', $d['design_id'])->where('karat_id', $d['karat_id'])
            ->where('size', $d['size'] ?? null)->where('remarks', $d['remarks'] ?? null)
            ->where('target_wt_min', $d['target_wt_min'] ?? null)->where('target_wt_max', $d['target_wt_max'] ?? null)->first();

        if ($existing) {
            $existing->increment('qty', $d['qty']);
        } else {
            CartItem::create($d + ['retailer_id' => $retailer->id, 'retailer_user_id' => $request->user('retailer')->id]);
        }

        return response()->json($this->summary($retailer), 201);
    }

    public function update(Request $request, CartItem $item): JsonResponse
    {
        $retailer = $this->retailer($request);
        abort_unless($item->retailer_id === $retailer->id, 404);

        $item->update($request->validate([
            'qty' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'size' => ['nullable', 'string', 'max:20'],
            'target_wt_min' => ['nullable', 'decimal:0,3', 'min:0'],
            'target_wt_max' => ['nullable', 'decimal:0,3', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]));

        return response()->json($this->summary($retailer));
    }

    public function destroy(Request $request, CartItem $item): JsonResponse
    {
        $retailer = $this->retailer($request);
        abort_unless($item->retailer_id === $retailer->id, 404);
        $item->delete();

        return response()->json($this->summary($retailer));
    }

    /** Estimated fine gold and value of the cart; the final bill uses actual weights. */
    public function summary(Retailer $retailer): array
    {
        $items = CartItem::with(['design.images', 'karat:id,label,purity_pct'])->where('retailer_id', $retailer->id)->orderBy('id')->get();

        $lines = $items->map(function (CartItem $i) use ($retailer) {
            $weight = $i->target_wt_min && $i->target_wt_max
                ? Weight::round(bcdiv(Weight::add($i->target_wt_min, $i->target_wt_max), '2', 6))
                : null;
            $est = $this->catalogue->estimate($i->design, $i->karat_id, (string) $i->karat->purity_pct, $retailer, $weight);

            return [
                'id' => $i->id, 'qty' => $i->qty, 'size' => $i->size, 'remarks' => $i->remarks,
                'target_wt_min' => $i->target_wt_min, 'target_wt_max' => $i->target_wt_max,
                'design' => ['id' => $i->design->id, 'code' => $i->design->code, 'name' => $i->design->name,
                    'sizes' => $i->design->sizes ?? [], 'photo' => $i->design->images->first()?->thumb_url,
                    'approx_weight_min' => $i->design->approx_weight_min, 'approx_weight_max' => $i->design->approx_weight_max,
                    'min_order_qty' => $i->design->min_order_qty ?? 1, 'production_days' => $i->design->production_days],
                'karat' => $i->karat->only(['id', 'label']),
                'est_fine' => $est ? Weight::round(bcmul($est['fine'], (string) $i->qty, 6)) : null,
                'est_making' => $est ? Money::mul($est['making'], $i->qty) : null,
                'est_value' => $est ? Money::mul($est['total'], $i->qty) : null,
            ];
        });

        return [
            'data' => $lines->values(),
            'totals' => [
                'pieces' => $items->sum('qty'),
                'est_fine' => Weight::add('0', ...$lines->pluck('est_fine')->filter()->all()),
                'est_making' => Money::add('0', ...$lines->pluck('est_making')->filter()->all()),
                'est_value' => Money::add('0', ...$lines->pluck('est_value')->filter()->all()),
            ],
        ];
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'design_id' => ['required', TenantRules::exists('designs')],
            'karat_id' => ['required', TenantRules::exists('karats')],
            'size' => ['nullable', 'string', 'max:20'],
            'qty' => ['required', 'integer', 'min:1', 'max:10000'],
            'target_wt_min' => ['nullable', 'decimal:0,3', 'min:0'],
            'target_wt_max' => ['nullable', 'decimal:0,3', 'min:0', 'gte:target_wt_min'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
