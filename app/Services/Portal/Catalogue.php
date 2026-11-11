<?php

namespace App\Services\Portal;

use App\Http\Controllers\Staff\GoldRateController;
use App\Models\Design;
use App\Models\MakingSlab;
use App\Models\Retailer;
use App\Support\Metal\Weight;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/** What a retailer may see in the catalogue, and indicative prices (actual weights decide the bill). */
class Catalogue
{
    /** @var array<int, string>|null */
    private ?array $rates = null;

    public function visible(Retailer $retailer): Builder
    {
        $collectionIds = $retailer->collections()->pluck('collections.id');

        return Design::query()
            ->where('is_published', true)
            ->when($collectionIds->isNotEmpty(), fn ($q) => $q->whereHas('collections', fn ($c) => $c->whereIn('collections.id', $collectionIds)));
    }

    /** @return array<int, string> today's rate per karat id */
    public function rates(): array
    {
        return $this->rates ??= GoldRateController::effectiveRates(today()->toDateString())
            ->mapWithKeys(fn ($r) => [$r->karat_id => Money::round((string) $r->rate_per_g)])->all();
    }

    /**
     * Estimated price before GST for one piece at the middle of the design's weight
     * range: metal at today's rate + making (retailer's slab, else the design's).
     *
     * @return array{weight: string, metal: string, making: string, total: string, fine: string}|null
     */
    public function estimate(Design $design, int $karatId, string $purity, Retailer $retailer, ?string $weight = null): ?array
    {
        $rate = $this->rates()[$karatId] ?? null;
        $weight ??= ($design->approx_weight_min && $design->approx_weight_max)
            ? Weight::round(bcdiv(Weight::add($design->approx_weight_min, $design->approx_weight_max), '2', 6))
            : ($design->approx_weight_min ?? $design->approx_weight_max);

        if ($rate === null || $weight === null) {
            return null;
        }

        $metal = Money::mul($weight, $rate);
        $slab = $retailer->makingSlab ?? MakingSlab::where('is_default', true)->first();
        [$basis, $mrate] = $slab && ! Money::isZero($slab->rate)
            ? [$slab->basis, (string) $slab->rate]
            : [$design->making_type ?? 'per_gram', (string) ($design->making_charge ?? '0')];
        $making = match ($basis) {
            'per_piece' => Money::round($mrate),
            'percent' => Money::percent($metal, $mrate),
            default => Money::mul($weight, $mrate),
        };

        return [
            'weight' => $weight, 'metal' => $metal, 'making' => $making,
            'total' => Money::add($metal, $making), 'fine' => Weight::fine($weight, $purity),
        ];
    }
}
