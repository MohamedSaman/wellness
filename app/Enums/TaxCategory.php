<?php

namespace App\Enums;

/**
 * Whether the shop's tax rate applies to a product (docs/PHARMACY.md Q11).
 * Existing products are standard, so retail tax is unchanged; new medicines
 * default to exempt.
 */
enum TaxCategory: string
{
    case Standard = 'standard';
    case Exempt = 'exempt';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard (taxed)',
            self::Exempt => 'Exempt (no tax)',
        };
    }

    /** @return array<string, string> value => label, for a select */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $category): array => [$category->value => $category->label()])->all();
    }
}
