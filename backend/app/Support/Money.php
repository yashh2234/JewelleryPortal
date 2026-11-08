<?php

namespace App\Support;

use App\Support\Metal\Weight;

/** Rupee arithmetic on decimal strings (bcmath, 2 places, half away from zero). Never floats. */
final class Money
{
    public static function round(string|int|float $v): string
    {
        return Weight::round(self::str($v), 2);
    }

    public static function add(string|int|float ...$values): string
    {
        return self::round(array_reduce($values, fn ($s, $v) => bcadd($s, self::str($v), 10), '0'));
    }

    public static function sub(string|int|float $a, string|int|float $b): string
    {
        return self::round(bcsub(self::str($a), self::str($b), 10));
    }

    /** a × b, rounded to paise. */
    public static function mul(string|int|float $a, string|int|float $b): string
    {
        return self::round(bcmul(self::str($a), self::str($b), 10));
    }

    /** value × pct ÷ 100, rounded to paise. */
    public static function percent(string|int|float $value, string|int|float $pct): string
    {
        return self::round(bcdiv(bcmul(self::str($value), self::str($pct), 10), '100', 10));
    }

    public static function compare(string|int|float $a, string|int|float $b): int
    {
        return bccomp(self::str($a), self::str($b), 2);
    }

    public static function isZero(string|int|float $v): bool
    {
        return bccomp(self::str($v), '0', 2) === 0;
    }

    public static function abs(string $v): string
    {
        return ltrim(self::round($v), '-');
    }

    public static function fromDb(mixed $v): string
    {
        return Weight::round(Weight::fromDb($v === null ? '0' : $v), 2);
    }

    private static function str(string|int|float $v): string
    {
        if (is_float($v)) {
            return number_format($v, 6, '.', '');
        }
        $v = trim((string) $v);

        return $v === '' ? '0' : $v;
    }
}
