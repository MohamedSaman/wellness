<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A patient's age (docs/PHARMACY.md, branch 13), held in whole months so a
 * baby's "8m" and an adult's "34" fit one column. Typed at the till as years
 * ("34", "34y") or months ("8m"); shown "8 m" under 2 years, else "34 y".
 * Mirrored by the till in resources/js/lib/patientAge.ts (parse and format):
 * change both together.
 */
final class PatientAge
{
    /** 150 years: anything older is a typing mistake. */
    public const MAX_MONTHS = 1800;

    /** Below this many months, an age is shown in months. */
    public const MONTHS_UNTIL = 24;

    /** "34", "34y", "34 y", "8m", "8 m" (any case) → months; null when it is not an age. */
    public static function parse(?string $typed): ?int
    {
        $text = strtolower(trim((string) $typed));

        if (preg_match('/^(\d{1,3})\s*(y|m)?$/', $text, $match) !== 1) {
            return null;
        }

        $months = (int) $match[1] * (($match[2] ?? 'y') === 'm' ? 1 : 12);

        return $months <= self::MAX_MONTHS ? $months : null;
    }

    /** 8 → "8 m", 408 → "34 y". */
    public static function format(?int $months): ?string
    {
        if ($months === null || $months < 0) {
            return null;
        }

        return $months < self::MONTHS_UNTIL ? "{$months} m" : intdiv($months, 12).' y';
    }

    /** Whole months from the date of birth to the day of the visit; null without a date of birth. */
    public static function at(?CarbonInterface $birthDate, CarbonInterface $day): ?int
    {
        if ($birthDate === null) {
            return null;
        }

        $months = (int) CarbonImmutable::parse($birthDate->toDateString())->diffInMonths(CarbonImmutable::parse($day->toDateString()), false);

        return max(0, min(self::MAX_MONTHS, $months));
    }

    /** The date of birth an age on a given day points to (the day, that many months earlier). */
    public static function birthDate(int $months, CarbonInterface $day): CarbonImmutable
    {
        return CarbonImmutable::parse($day->toDateString())->subMonthsNoOverflow($months);
    }
}
