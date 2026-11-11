<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Indian financial year: 1 April – 31 March. Label "26-27" for FY 2026-27. */
final class FinancialYear
{
    public static function label(DateTimeInterface|string|null $date = null): string
    {
        $start = self::startYear($date);

        return sprintf('%02d-%02d', $start % 100, ($start + 1) % 100);
    }

    public static function start(DateTimeInterface|string|null $date = null): CarbonImmutable
    {
        return CarbonImmutable::create(self::startYear($date), 4, 1)->startOfDay();
    }

    public static function end(DateTimeInterface|string|null $date = null): CarbonImmutable
    {
        return CarbonImmutable::create(self::startYear($date) + 1, 3, 31)->endOfDay();
    }

    private static function startYear(DateTimeInterface|string|null $date): int
    {
        $d = CarbonImmutable::parse($date ?? 'now', config('app.timezone'));

        return $d->month >= 4 ? $d->year : $d->year - 1;
    }
}
