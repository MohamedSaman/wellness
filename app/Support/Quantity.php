<?php

namespace App\Support;

/**
 * Quantities are stored as DECIMAL(14,3) so the same system can later sell
 * by length, litre or kg (e.g. loose oil, cable). Always pass quantities
 * through normalize() before maths or comparison to avoid float drift.
 */
final class Quantity
{
    public const SCALE = 3;

    public static function normalize(float|int|string $value): float
    {
        return round((float) $value, self::SCALE);
    }

    public static function isPositive(float|int|string $value): bool
    {
        return self::normalize($value) > 0;
    }

    /** No fraction at the stored scale: 30 and 30.000 are whole, 0.5 and 29.999 are not. */
    public static function isWhole(float|int|string $value): bool
    {
        return (int) round(self::normalize($value) * 10 ** self::SCALE) % 10 ** self::SCALE === 0;
    }

    /** "30", "1.5", "0.333": no trailing zeros, for messages. */
    public static function format(float|int|string $value): string
    {
        return rtrim(rtrim(number_format(self::normalize($value), self::SCALE, '.', ''), '0'), '.');
    }
}
