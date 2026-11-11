<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\GoldRate;
use App\Support\Tenancy\TenantRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GoldRateController extends Controller
{
    /** Rates for a date (default today), falling back to the latest earlier rate per karat. */
    public function index(Request $request): JsonResponse
    {
        $date = $request->date('date')?->toDateString() ?? today()->toDateString();

        return response()->json(['date' => $date, 'data' => self::effectiveRates($date)]);
    }

    /** Set the day's rates: [{karat_id, rate_per_g}]. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'rates' => ['required', 'array', 'min:1'],
            'rates.*.karat_id' => ['required', 'distinct', TenantRules::exists('karats')],
            'rates.*.rate_per_g' => ['required', 'decimal:0,2', 'gt:0'],
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['rates'] as $rate) {
                GoldRate::updateOrCreate(
                    ['date' => $data['date'], 'karat_id' => $rate['karat_id']],
                    ['rate_per_g' => $rate['rate_per_g']],
                );
            }
        });

        return response()->json(['date' => $data['date'], 'data' => self::effectiveRates($data['date'])]);
    }

    public static function effectiveRates(string $date): \Illuminate\Support\Collection
    {
        return GoldRate::with('karat:id,label,purity_pct')
            ->where('date', '<=', $date)
            ->orderByDesc('date')
            ->get()
            ->unique('karat_id')
            ->values();
    }
}
