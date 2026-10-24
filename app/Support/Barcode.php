<?php

namespace App\Support;

/**
 * In-store barcodes for items that don't come with a manufacturer barcode.
 * Uses the EAN-13 "in-store" prefix range (200-299), which never clashes
 * with real retail products, and scans on every barcode scanner.
 *
 * Format (docs/PHARMACY.md §9, branch 7b): an item (its base unit) is
 * 200 + its variant id in 9 digits + the check digit; a pack (strip, box) is
 * 201 + its unit id. Should that code already be taken (a manufacturer or
 * typed barcode in the 2xx range), the next prefix up to 299 is tried
 * (BarcodeService). Every code is unique across items and packs.
 */
final class Barcode
{
    public const ITEM_PREFIX = 200;

    public const PACK_PREFIX = 201;

    /** Barcode symbologies a label can print. */
    public const EAN13 = 'ean13';

    public const EAN8 = 'ean8';

    public const UPCA = 'upca';

    public const CODE128 = 'code128';

    /** Build an in-store EAN-13 from a shop number and a running number. */
    public static function inStoreEan13(int $runningNumber, int $prefix = self::ITEM_PREFIX): string
    {
        $body = sprintf('%03d%09d', $prefix, $runningNumber);

        return $body.self::ean13CheckDigit($body);
    }

    public static function ean13CheckDigit(string $twelveDigits): int
    {
        return self::gtinCheckDigit($twelveDigits);
    }

    /**
     * The GS1 check digit of EAN-13, EAN-8 and UPC-A data digits: weights 3
     * and 1 alternating from the rightmost data digit.
     */
    public static function gtinCheckDigit(string $digits): int
    {
        $sum = 0;

        foreach (array_reverse(str_split($digits)) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - ($sum % 10)) % 10;
    }

    public static function isValidEan13(string $code): bool
    {
        return self::isValidGtin($code, 13);
    }

    public static function isValidEan8(string $code): bool
    {
        return self::isValidGtin($code, 8);
    }

    public static function isValidUpcA(string $code): bool
    {
        return self::isValidGtin($code, 12);
    }

    /**
     * An in-store code (GS1 restricted circulation, 200-299): ours, or one a
     * previous system printed. Never a manufacturer's barcode.
     */
    public static function isInStore(?string $code): bool
    {
        return $code !== null && self::isValidEan13($code) && $code[0] === '2';
    }

    /**
     * How a label prints a code: EAN-13, EAN-8 or UPC-A when it is a valid
     * one (check digit included), else Code 128 for any other printable
     * text up to 48 characters; null when it can't be printed as bars.
     */
    public static function symbology(string $code): ?string
    {
        return match (true) {
            self::isValidEan13($code) => self::EAN13,
            self::isValidUpcA($code) => self::UPCA,
            self::isValidEan8($code) => self::EAN8,
            (bool) preg_match('/^[\x20-\x7E]{1,48}$/', $code) => self::CODE128,
            default => null,
        };
    }

    /** The number printed under the bars: "2 000000 000017" for EAN-13, as on packs. */
    public static function humanReadable(string $code, ?string $symbology = null): string
    {
        return match ($symbology ?? self::symbology($code)) {
            self::EAN13 => $code[0].' '.substr($code, 1, 6).' '.substr($code, 7, 6),
            self::EAN8 => substr($code, 0, 4).' '.substr($code, 4, 4),
            self::UPCA => $code[0].' '.substr($code, 1, 5).' '.substr($code, 6, 5).' '.$code[11],
            default => $code,
        };
    }

    private static function isValidGtin(string $code, int $length): bool
    {
        return (bool) preg_match('/^\d{'.$length.'}$/', $code)
            && self::gtinCheckDigit(substr($code, 0, $length - 1)) === (int) $code[$length - 1];
    }
}
