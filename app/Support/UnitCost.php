<?php

namespace App\Support;

/**
 * Cost per base unit, the one approved exception to integer money
 * (docs/PHARMACY.md Q9): stored as DECIMAL(20,4) minor units, so a box of 30
 * at 100,000 minor is 3,333.3333 per tablet instead of a rounded 3,333.
 * Held as a decimal string ("3333.3333") and calculated with bcmath, never
 * with floats. Anything shown or added up as money is rounded back to whole
 * minor units (toMinor(), times()).
 */
final class UnitCost
{
    public const SCALE = 4;

    /**
     * Any cost (minor units, a decimal string) as a 4-decimal string: 1025 -> "1025.0000".
     *
     * @return numeric-string
     */
    public static function normalize(int|float|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '0.0000';
        }

        $string = match (true) {
            is_int($value) => (string) $value,
            is_float($value) => number_format($value, 8, '.', ''),
            default => trim($value),
        };

        if (! is_numeric($string)) {
            throw new \InvalidArgumentException("Not a cost: {$string}");
        }

        return self::round($string, self::SCALE);
    }

    /** Whole minor units, half up: "3333.5000" -> 3334. */
    public static function toMinor(int|float|string|null $cost): int
    {
        return (int) self::round(self::normalize($cost), 0);
    }

    /** Cost per base unit x a quantity, in whole minor units (a line, a stock value). */
    public static function times(int|float|string|null $cost, float|int|string $quantity): int
    {
        return (int) self::round(bcmul(self::normalize($cost), self::quantity($quantity), 8), 0);
    }

    public static function equals(int|float|string|null $a, int|float|string|null $b): bool
    {
        return bccomp(self::normalize($a), self::normalize($b), self::SCALE) === 0;
    }

    /**
     * Weighted average after a receipt: (on hand x current + quantity x unit cost)
     * / (on hand + quantity), exact and rounded half up to 4 decimals. With
     * nothing (or less than nothing) on hand, the new average is the unit cost.
     *
     * @return numeric-string
     */
    public static function weightedAverage(float|int|string $onHand, int|string $current, float|int|string $quantity, int|string $unitCost): string
    {
        if (Quantity::normalize($onHand) <= 0 || Quantity::normalize($quantity) <= 0) {
            return self::normalize($unitCost);
        }

        $value = bcadd(
            bcmul(self::quantity($onHand), self::normalize($current), 8),
            bcmul(self::quantity($quantity), self::normalize($unitCost), 8),
            8,
        );

        return self::round(bcdiv($value, bcadd(self::quantity($onHand), self::quantity($quantity), 3), 8), self::SCALE);
    }

    /**
     * The average once a receipt is taken back out (a reversing goods received
     * note): the exact inverse of weightedAverage(),
     *   (on hand x current - quantity x unit cost) / (on hand - quantity),
     * so reversing a receipt nothing has touched since restores the average it
     * replaced. With nothing left afterwards the current average stands, and it
     * never goes below zero (stock that left in between took value with it).
     *
     * @return numeric-string
     */
    public static function withoutReceipt(float|int|string $onHand, int|string $current, float|int|string $quantity, int|string $unitCost): string
    {
        $left = Quantity::normalize(Quantity::normalize($onHand) - Quantity::normalize($quantity));

        if ($left <= 0 || Quantity::normalize($quantity) <= 0) {
            return self::normalize($current);
        }

        $value = bcsub(
            bcmul(self::quantity($onHand), self::normalize($current), 8),
            bcmul(self::quantity($quantity), self::normalize($unitCost), 8),
            8,
        );

        if (bccomp($value, '0', 8) <= 0) {
            return self::normalize(0);
        }

        return self::round(bcdiv($value, self::quantity($left), 8), self::SCALE);
    }

    /**
     * Cost per base unit of a line: its total (minor units) over its base
     * quantity, exact to 4 places. 950,000 over 1,100 tablets is 863.6364.
     *
     * @return numeric-string
     */
    public static function perUnit(int $total, float|int|string $quantity): string
    {
        if (Quantity::normalize($quantity) <= 0) {
            return self::normalize(0);
        }

        return self::round(bcdiv((string) $total, self::quantity($quantity), 8), self::SCALE);
    }

    /**
     * The average across several locations, weighted by what each has on hand:
     * sum(on hand x average) / sum(on hand) over the locations holding stock.
     * Null when none holds any.
     *
     * @param  iterable<array{0: float|int|string, 1: int|string}>  $levels  [on hand, average]
     * @return numeric-string|null
     */
    public static function blend(iterable $levels): ?string
    {
        $value = '0';
        $units = '0';

        foreach ($levels as [$onHand, $average]) {
            if (Quantity::normalize($onHand) <= 0) {
                continue;
            }

            $value = bcadd($value, bcmul(self::quantity($onHand), self::normalize($average), 8), 8);
            $units = bcadd($units, self::quantity($onHand), 3);
        }

        return bccomp($units, '0', 3) === 0 ? null : self::round(bcdiv($value, $units, 8), self::SCALE);
    }

    /** @return numeric-string */
    private static function quantity(float|int|string $quantity): string
    {
        return number_format(Quantity::normalize($quantity), Quantity::SCALE, '.', '');
    }

    /**
     * Half away from zero: bcmath truncates, so add (or take) half of the last place first.
     *
     * @param  numeric-string  $value
     * @return numeric-string
     */
    private static function round(string $value, int $scale): string
    {
        $half = number_format(0.5 / 10 ** $scale, $scale + 1, '.', ''); // "0.00005" at 4 places

        return str_starts_with($value, '-') ? bcsub($value, $half, $scale) : bcadd($value, $half, $scale);
    }
}
