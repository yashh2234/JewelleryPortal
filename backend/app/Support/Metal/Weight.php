<?php

namespace App\Support\Metal;

use InvalidArgumentException;

/**
 * Gram and purity arithmetic on decimal strings (bcmath), never floats.
 *
 * Weights are grams to 3 decimals (milligram). Purity is touch % to 3 decimals.
 * Rounding is half away from zero, applied once at the end of a calculation.
 */
final class Weight
{
    public const SCALE = 3;

    /** Internal precision for intermediate steps. */
    private const WORK_SCALE = 10;

    /** Fine weight = gross × purity % ÷ 100. */
    public static function fine(string $gross, string $purityPct): string
    {
        self::assertNumeric($gross, $purityPct);

        $raw = bcdiv(bcmul($gross, $purityPct, self::WORK_SCALE), '100', self::WORK_SCALE);

        return self::round($raw);
    }

    /** Net gold = gross − stone weight − non-gold findings. */
    public static function net(string $gross, string $stone = '0', string $findings = '0'): string
    {
        self::assertNumeric($gross, $stone, $findings);

        return self::round(bcsub(bcsub($gross, $stone, self::WORK_SCALE), $findings, self::WORK_SCALE));
    }

    public static function add(string ...$weights): string
    {
        self::assertNumeric(...$weights);

        return self::round(array_reduce($weights, fn ($sum, $w) => bcadd($sum, $w, self::WORK_SCALE), '0'));
    }

    public static function sub(string $a, string $b): string
    {
        self::assertNumeric($a, $b);

        return self::round(bcsub($a, $b, self::WORK_SCALE));
    }

    /** Loss as a % of the issued weight (2 places is plenty for display/limits; 3 kept). */
    public static function percentOf(string $part, string $whole): string
    {
        self::assertNumeric($part, $whole);

        if (bccomp($whole, '0', self::WORK_SCALE) === 0) {
            return '0.000';
        }

        return self::round(bcdiv(bcmul($part, '100', self::WORK_SCALE), $whole, self::WORK_SCALE));
    }

    /**
     * Normalise a value read from the database (SUM() can come back as a float on
     * SQLite, or as null) to a 3-decimal string.
     */
    public static function fromDb(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.000';
        }

        $value = (string) $value;
        if (! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            $value = number_format((float) $value, 6, '.', ''); // float / exponent notation from SQLite
        }

        return self::round($value);
    }

    public static function isPositive(string $value): bool
    {
        return bccomp($value, '0', self::SCALE) > 0;
    }

    public static function negate(string $value): string
    {
        return self::sub('0', $value);
    }

    public static function compare(string $a, string $b): int
    {
        self::assertNumeric($a, $b);

        return bccomp($a, $b, self::SCALE);
    }

    /** Round half away from zero to $scale places. */
    public static function round(string $value, int $scale = self::SCALE): string
    {
        self::assertNumeric($value);

        $half = '0.'.str_repeat('0', $scale).'5';
        $adjusted = str_starts_with($value, '-')
            ? bcsub($value, $half, self::WORK_SCALE)
            : bcadd($value, $half, self::WORK_SCALE);

        $result = bcadd($adjusted, '0', $scale); // bcmath truncates toward zero

        // Normalise "-0.000" to "0.000".
        return bccomp($result, '0', $scale) === 0 ? bcadd('0', '0', $scale) : $result;
    }

    private static function assertNumeric(string ...$values): void
    {
        foreach ($values as $value) {
            if (! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
                throw new InvalidArgumentException("Not a decimal number: [{$value}]");
            }
        }
    }
}
