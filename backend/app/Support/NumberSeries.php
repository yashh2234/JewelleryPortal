<?php

namespace App\Support;

use App\Models\NumberSequence;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Gap-free document numbers per tenant, series and financial year: ORD/26-27/0001.
 *
 * Must be called inside the same DB transaction that creates the document, so a
 * rolled-back document also rolls back its number.
 */
final class NumberSeries
{
    public static function next(string $series, DateTimeInterface|string|null $date = null, int $pad = 4): string
    {
        $fy = FinancialYear::label($date);

        return DB::transaction(function () use ($series, $fy, $pad) {
            $sequence = NumberSequence::query()
                ->where('series', $series)
                ->where('financial_year', $fy)
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
                $sequence = NumberSequence::create(['series' => $series, 'financial_year' => $fy, 'last_number' => 0]);
            }

            $sequence->increment('last_number');

            return sprintf('%s/%s/%s', $series, $fy, str_pad((string) $sequence->last_number, $pad, '0', STR_PAD_LEFT));
        });
    }
}
