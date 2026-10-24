<?php

namespace App\Support;

use App\Exceptions\PosException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Expiry dates as printed on packs. Most say a month ("EXP 03/2027"): that
 * means the batch may be used up to the last day of the month, so it is
 * stored as 2027-03-31 (docs/PHARMACY.md §4.5). A full date is kept as is.
 */
final class BatchExpiry
{
    /**
     * Accepts 2027-03-31, 31/03/2027, 2027-03, 03/2027 and 03/27.
     *
     * @throws PosException
     */
    public static function parse(CarbonInterface|string $value): CarbonImmutable
    {
        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::parse($value->format('Y-m-d'))->startOfDay();
        }

        $value = trim($value);

        $date = match (true) {
            (bool) preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m) => self::date((int) $m[1], (int) $m[2], (int) $m[3]),
            (bool) preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $m) => self::date((int) $m[3], (int) $m[2], (int) $m[1]),
            (bool) preg_match('/^(\d{4})-(\d{1,2})$/', $value, $m) => self::endOfMonth((int) $m[1], (int) $m[2]),
            (bool) preg_match('/^(\d{1,2})\/(\d{4})$/', $value, $m) => self::endOfMonth((int) $m[2], (int) $m[1]),
            (bool) preg_match('/^(\d{1,2})\/(\d{2})$/', $value, $m) => self::endOfMonth(2000 + (int) $m[2], (int) $m[1]),
            default => null,
        };

        return $date ?? throw new PosException("\"{$value}\" is not an expiry date. Use the date or month printed on the pack, such as 03/2027.");
    }

    private static function date(int $year, int $month, int $day): ?CarbonImmutable
    {
        return checkdate($month, $day, $year) ? CarbonImmutable::create($year, $month, $day)->startOfDay() : null;
    }

    private static function endOfMonth(int $year, int $month): ?CarbonImmutable
    {
        return $month >= 1 && $month <= 12 ? CarbonImmutable::create($year, $month, 1)->endOfMonth()->startOfDay() : null;
    }
}
