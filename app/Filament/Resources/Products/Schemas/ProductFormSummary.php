<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\DosageForm;
use App\Enums\DrugSchedule;
use App\Enums\TaxCategory;
use App\Models\Location;
use App\Support\Quantity;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Collection;
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
            $pharmacy && ! (bool) $get('is_medicine') && (bool) $get('allow_price_override') ? 'Price can be changed at the till' : null,
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

    public static function variant(Get $get, bool $pharmacy): string
    {
        $packs = count((array) ($get('packs') ?? []));

        return self::line([
            'More',
            'Reorder '.Quantity::format((float) $get('reorder_level')),
            'Shelf '.($get('bin_location') ?: '-'),
            $pharmacy ? 'Sell loose '.(self::sellsLoose($get, $get('allow_unit_sale')) ? 'Yes' : 'No') : null,
            $pharmacy ? $packs.' '.Str::plural('pack', $packs) : null,
        ]);
    }

    /**
     * Whether a single base unit sells at each location, the "Customise per
     * location" fields read together with "Sell loose".
     *
     * @param  Collection<int, Location>  $locations
     */
    public static function looseSale(Get $get, Collection $locations): string
    {
        /** @var array<int|string, string|null> $overrides */
        $overrides = (array) ($get('loose_at') ?? []);

        $answers = $locations->mapWithKeys(fn (Location $location): array => [
            $location->code => self::sellsLoose($get, match ($overrides[$location->getKey()] ?? null) {
                'yes' => true,
                'no' => false,
                default => $get('allow_unit_sale'),
            }) ? 'Yes' : 'No',
        ]);

        if ($answers->unique()->count() === 1) {
            return "Sell loose: {$answers->first()} at all locations";
        }

        return 'Sell loose: '.$answers->map(fn (string $answer, string $code): string => "{$answer} at {$code}")->implode(', ');
    }

    /** A variant with no pack on sale can only sell loose (Q16), whatever its setting says. */
    private static function sellsLoose(Get $get, mixed $setting): bool
    {
        return (bool) $setting || collect((array) ($get('packs') ?? []))
            ->doesntContain(fn (array $pack): bool => (bool) ($pack['is_sellable'] ?? true) && (bool) ($pack['is_active'] ?? true));
    }

    /** @param  array<int, mixed>  $parts */
    private static function line(array $parts): string
    {
        return implode(self::SEPARATOR, array_filter(array_map(fn (mixed $part): string => trim((string) $part), $parts), filled(...)));
    }
}
