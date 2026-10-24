<?php

namespace App\Support;

use App\Exceptions\PosException;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

/**
 * A calendar day typed on a document (received on, invoice date, paid on):
 * "2026-10-18" or "18/10/2026", at midnight. Anything else is a message for
 * the user, not an error page.
 */
final class CalendarDay
{
    /** @throws PosException */
    public static function parse(string $value): CarbonImmutable
    {
        $typed = trim($value);
        $iso = preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $typed, $m) === 1 ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : $typed;

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $iso, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new PosException("\"{$typed}\" is not a date.");
        }

        try {
            return CarbonImmutable::parse($iso)->startOfDay();
        } catch (InvalidFormatException) {
            throw new PosException("\"{$typed}\" is not a date.");
        }
    }
}
