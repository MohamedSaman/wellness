<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\DosageForm;
use App\Enums\DrugSchedule;
use App\Enums\TaxCategory;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Str;

/**
 * The one-line summaries a collapsed part of the product form shows. They
 * only read the form's state; nothing here is saved.
 */
class ProductFormSummary
{
    private const SEPARATOR = ' · ';

    public static function medicine(Get $get): string
    {
        if (! (bool) $get('is_medicine')) {
            return 'Not a medicine';
        }

        $strength = trim($get('drug.strength_value').' '.$get('drug.strength_unit'));

        return self::line([
            implode(' + ', array_values((array) ($get('drug.generic_names') ?? []))),
            $get('drug.brand_name'),
            $strength,
            DosageForm::options()[$get('drug.dosage_form')] ?? null,
            DrugSchedule::options()[$get('drug.schedule')] ?? null,
        ]);
    }

    public static function selling(Get $get, bool $pharmacy): string
    {
        $tracksStock = (bool) $get('track_stock');

        return self::line([
            $get('unit'),
            $tracksStock ? 'Stock tracked' : 'Stock not tracked',
            $pharmacy && $tracksStock && (bool) $get('track_batches') ? 'Batches and expiry' : null,
            $pharmacy && (bool) $get('whole_units_only') ? 'Whole units only' : null,
            $pharmacy ? (TaxCategory::options()[$get('tax_category')] ?? null) : null,
        ]);
    }

    public static function moreDetails(Get $get): string
    {
        $attributes = count(array_filter((array) ($get('product_attributes') ?? []), filled(...)));

        return self::line([
            Str::limit((string) $get('description'), 80),
            $attributes > 0 ? $attributes.' '.Str::plural('attribute', $attributes) : null,
        ]) ?: 'No description';
    }

    /** @param  array<int, mixed>  $parts */
    private static function line(array $parts): string
    {
        return implode(self::SEPARATOR, array_filter(array_map(fn (mixed $part): string => trim((string) $part), $parts), filled(...)));
    }
}
