<?php

namespace App\Support;

/**
 * Money is stored as integers in the smallest unit (cents / toea / ...).
 * LKR 1,250.50 is stored as 125050. Never store money as float.
 */
final class Money
{
    /** Convert a user-typed amount ("1,250.50") to minor units (125050). */
    public static function toMinor(float|int|string $amount, int $decimals = 2): int
    {
        $clean = is_string($amount) ? str_replace([',', ' '], '', $amount) : $amount;

        return (int) round(((float) $clean) * (10 ** $decimals));
    }

    /** Convert minor units back to a display string: 125050 -> "1,250.50". */
    public static function format(int $minor, int $decimals = 2, ?string $currency = null): string
    {
        $formatted = number_format($minor / (10 ** $decimals), $decimals);

        return $currency ? "{$currency} {$formatted}" : $formatted;
    }

    /** Minor units as a plain decimal string for form inputs: 125050 -> "1250.50". */
    public static function toInputString(int $minor, int $decimals = 2): string
    {
        return number_format($minor / (10 ** $decimals), $decimals, '.', '');
    }

    /** unit price (minor) x quantity, rounded to the nearest minor unit. */
    public static function multiply(int $unitMinor, float|int|string $quantity): int
    {
        return (int) round($unitMinor * Quantity::normalize($quantity));
    }

    /**
     * Split $amount across $weights proportionally; the rounding remainder goes
     * to the largest weight so the parts always add up exactly to $amount.
     *
     * @param  array<array-key, int>  $weights
     * @return array<array-key, int>
     */
    public static function allocate(int $amount, array $weights): array
    {
        $total = array_sum($weights);
        $parts = array_map(fn () => 0, $weights);

        if ($amount === 0 || $total <= 0 || $weights === []) {
            return $parts;
        }

        foreach ($weights as $key => $weight) {
            $parts[$key] = intdiv($amount * $weight, $total);
        }

        $remainder = $amount - array_sum($parts);
        $largestKey = array_search(max($weights), $weights, true);
        $parts[$largestKey] += $remainder;

        return $parts;
    }
}
