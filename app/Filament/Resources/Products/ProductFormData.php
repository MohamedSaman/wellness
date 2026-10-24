<?php

namespace App\Filament\Resources\Products;

use App\Enums\AttributeType;
use App\Enums\DosageForm;
use App\Enums\DrugRoute;
use App\Enums\DrugSchedule;
use App\Enums\StorageCondition;
use App\Enums\TaxCategory;
use App\Models\AttributeOption;
use App\Models\Generic;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\User;
use App\Models\VariantUnit;
use App\Models\VariantUnitLocation;
use App\Services\Data\BatchInput;
use App\Services\Data\DrugData;
use App\Support\CurrentShop;
use App\Support\Money;
use App\Support\Quantity;

/**
 * Translates between the product form (prices typed as 1250.50) and what
 * ProductCatalogService expects (integers in minor units).
 */
class ProductFormData
{
    private static function decimals(): int
    {
        return (int) app(CurrentShop::class)->getOrFail()->currency_decimals;
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromProduct(Product $product): array
    {
        $decimals = self::decimals();
        $canSeeCost = self::user()?->can('cost.view') ?? false;
        $priceListId = app(CurrentShop::class)->getOrFail()->defaultPriceList()->getKey();

        $product->load(['variants.prices', 'variants.units.prices', 'variants.units.locationRules', 'variants.attributeValues', 'attributeValues', 'fitments', 'drugDetail', 'generics']);

        $variants = [];

        foreach ($product->variants as $variant) {
            $price = $variant->prices->firstWhere('price_list_id', $priceListId);

            $row = [
                'id' => $variant->getKey(),
                'sku' => $variant->sku,
                'barcode' => $variant->barcode,
                'price' => $price === null ? null : Money::toInputString((int) $price->price, $decimals),
                'reorder_level' => $variant->reorder_level,
                'bin_location' => $variant->bin_location,
                'is_active' => (bool) $variant->is_active,
                'options' => $variant->attributeValues->pluck('attribute_option_id', 'product_attribute_id')->all(),
                'allow_unit_sale' => (bool) ($variant->units->firstWhere('is_base', true)->is_sellable ?? true),
                'loose_at' => ($variant->units->firstWhere('is_base', true)->locationRules ?? collect())
                    ->mapWithKeys(fn (VariantUnitLocation $rule): array => [(int) $rule->location_id => $rule->is_sellable ? 'yes' : 'no'])
                    ->all(),
                'packs' => array_values($variant->units->where('is_base', false)->map(function (VariantUnit $unit) use ($priceListId, $decimals): array {
                    $price = $unit->prices->firstWhere('price_list_id', $priceListId);

                    return [
                        'id' => $unit->getKey(),
                        'name' => $unit->name,
                        'factor' => Quantity::format((float) $unit->factor),
                        'barcode' => $unit->barcode,
                        'price' => $price === null ? null : Money::toInputString((int) $price->price, $decimals),
                        'is_sellable' => $unit->is_sellable,
                        'is_purchasable' => $unit->is_purchasable,
                        'is_active' => $unit->is_active,
                    ];
                })->all()),
            ];

            if ($canSeeCost) {
                $shop = app(CurrentShop::class)->getOrFail();
                $row['average_cost'] = $shop->formatMoney((int) $variant->cost_price);
                $row['last_cost'] = $variant->last_cost_price === null ? null : $shop->formatMoney((int) $variant->last_cost_price);
            }

            $variants[] = $row;
        }

        return [
            'category_id' => $product->category_id,
            'brand_id' => $product->brand_id,
            'name' => $product->name,
            'description' => $product->description,
            'part_number' => $product->part_number,
            'oem_numbers' => $product->oem_numbers ?? [],
            'unit' => $product->unit,
            'track_stock' => (bool) $product->track_stock,
            'allow_price_override' => (bool) $product->allow_price_override,
            'track_batches' => (bool) $product->track_batches,
            'whole_units_only' => (bool) $product->whole_units_only,
            'tax_category' => $product->tax_category->value,
            'is_medicine' => $product->drugDetail !== null,
            'drug' => [
                'generic_names' => array_values($product->generics->map(fn (Generic $generic): string => $generic->name)->all()),
                'brand_name' => $product->drugDetail?->brand_name,
                'strength_value' => $product->drugDetail?->strength_value === null ? null : Quantity::format((float) $product->drugDetail->strength_value),
                'strength_unit' => $product->drugDetail?->strength_unit,
                'dosage_form' => $product->drugDetail?->dosage_form->value,
                'schedule' => $product->drugDetail?->schedule->value,
                'storage_condition' => $product->drugDetail?->storage_condition?->value,
                'storage_note' => $product->drugDetail?->storage_note,
                'registration_no' => $product->drugDetail?->registration_no,
                'route' => $product->drugDetail?->route?->value,
                'min_days_to_expiry' => $product->drugDetail?->min_days_to_expiry,
            ],
            'is_active' => (bool) $product->is_active,
            'product_attributes' => $product->attributeValues
                ->mapWithKeys(fn ($value) => [$value->product_attribute_id => $value->attribute_option_id ?? $value->value])
                ->all(),
            'fitment_ids' => $product->fitments->modelKeys(),
            'variants' => $variants,
        ];
    }

    /**
     * `drug` is null when the form has no medicine section (retail: drug
     * details are left alone), false when the product is not a medicine.
     *
     * @param  array<string, mixed>  $data  Validated form state
     * @return array{product: array<string, mixed>, variants: list<array{id: mixed, sku: string|null, barcode: string|null, price?: int, cost?: int, opening_stock: float, opening_location_id: int|null, opening_batch?: BatchInput, reorder_level: float, bin_location: string|null, is_active: bool, options: list<AttributeOption>, allow_unit_sale?: bool, loose_at?: array<int, bool|null>, packs?: list<array<string, mixed>>}>, productAttributes: array<int, int|string|null>, fitmentIds: list<int>|null, drug: DrugData|false|null}
     */
    public static function toServiceInput(array $data): array
    {
        $decimals = self::decimals();

        $product = [
            'category_id' => $data['category_id'] ?? null,
            'brand_id' => $data['brand_id'] ?? null,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'unit' => trim((string) ($data['unit'] ?? '')) ?: 'pcs',
            'track_stock' => (bool) ($data['track_stock'] ?? true),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];

        // Shown for services only (a stock item's flag is never honoured): hidden, what is stored stays.
        if (array_key_exists('allow_price_override', $data)) {
            $product['allow_price_override'] = (bool) ($data['allow_price_override'] ?? false) && ! $product['track_stock'];
        }

        // Spare-part fields are hidden on a pharmacy: then they are not sent, and what is stored stays.
        if (array_key_exists('part_number', $data) || array_key_exists('oem_numbers', $data)) {
            $product['part_number'] = filled($data['part_number'] ?? null) ? $data['part_number'] : null;
            $product['oem_numbers'] = array_values($data['oem_numbers'] ?? []);
        }

        // Pharmacy-only fields: when hidden they are not sent, and what is stored stays.
        if (array_key_exists('whole_units_only', $data)) {
            $product['whole_units_only'] = (bool) $data['whole_units_only'];
        }

        if (array_key_exists('track_batches', $data)) {
            $product['track_batches'] = (bool) $data['track_batches'] && $product['track_stock'];
        }

        if (filled($data['tax_category'] ?? null)) {
            $product['tax_category'] = TaxCategory::from((string) $data['tax_category']);
        }

        $variants = [];

        foreach ($data['variants'] ?? [] as $row) {
            $variant = [
                'id' => $row['id'] ?? null,
                'sku' => filled($row['sku'] ?? null) ? trim($row['sku']) : null,
                'barcode' => filled($row['barcode'] ?? null) ? trim($row['barcode']) : null,
                'reorder_level' => (float) ($row['reorder_level'] ?? 0),
                'bin_location' => filled($row['bin_location'] ?? null) ? $row['bin_location'] : null,
                'is_active' => (bool) ($row['is_active'] ?? true),
                'opening_stock' => (float) ($row['opening_stock'] ?? 0),
                'opening_location_id' => filled($row['opening_location_id'] ?? null) ? (int) $row['opening_location_id'] : null,
                'options' => self::options($row['options'] ?? []),
            ];

            if (filled($row['opening_batch_no'] ?? null) || filled($row['opening_expiry'] ?? null)) {
                $variant['opening_batch'] = new BatchInput(
                    (string) ($row['opening_batch_no'] ?? ''),
                    (string) ($row['opening_expiry'] ?? ''),
                    (bool) ($row['opening_accept_near_expiry'] ?? false),
                );
            }

            if (filled($row['price'] ?? null)) {
                $variant['price'] = Money::toMinor($row['price'], $decimals);
            }

            if (filled($row['cost'] ?? null)) {
                $variant['cost'] = Money::toMinor($row['cost'], $decimals);
            }

            if (array_key_exists('allow_unit_sale', $row)) {
                $variant['allow_unit_sale'] = (bool) $row['allow_unit_sale'];
            }

            // Shown only for a pharmacy with several locations; blank = as "Sell loose".
            if (is_array($row['loose_at'] ?? null)) {
                $variant['loose_at'] = array_map(fn (mixed $choice): ?bool => match ($choice) {
                    'yes' => true,
                    'no' => false,
                    default => null,
                }, $row['loose_at']);
            }

            if (array_key_exists('packs', $row)) {
                $variant['packs'] = array_values(array_map(fn (array $pack): array => [
                    'id' => filled($pack['id'] ?? null) ? (int) $pack['id'] : null,
                    'name' => trim((string) ($pack['name'] ?? '')),
                    'factor' => (float) ($pack['factor'] ?? 0),
                    'barcode' => filled($pack['barcode'] ?? null) ? trim((string) $pack['barcode']) : null,
                    'is_sellable' => (bool) ($pack['is_sellable'] ?? true),
                    'is_purchasable' => (bool) ($pack['is_purchasable'] ?? true),
                    'is_active' => (bool) ($pack['is_active'] ?? true),
                    ...(filled($pack['price'] ?? null) ? ['price' => Money::toMinor($pack['price'], $decimals)] : []),
                ], $row['packs'] ?? []));
            }

            $variants[] = $variant;
        }

        return [
            'product' => $product,
            'variants' => $variants,
            'productAttributes' => self::productAttributes($data['product_attributes'] ?? []),
            // Without vehicle_fitment the field is hidden: leave existing fitments alone.
            'fitmentIds' => app(CurrentShop::class)->getOrFail()->hasModule('vehicle_fitment')
                ? array_values(array_map('intval', $data['fitment_ids'] ?? []))
                : null,
            'drug' => self::drug($data),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function drug(array $data): DrugData|false|null
    {
        if (! array_key_exists('is_medicine', $data)) {
            return null;
        }

        if (! $data['is_medicine']) {
            return false;
        }

        /** @var array<string, mixed> $drug */
        $drug = $data['drug'] ?? [];
        $strength = $drug['strength_value'] ?? null;

        return new DrugData(
            genericNames: array_values(array_map('strval', $drug['generic_names'] ?? [])),
            dosageForm: DosageForm::from((string) ($drug['dosage_form'] ?? '')),
            schedule: DrugSchedule::from((string) ($drug['schedule'] ?? '')),
            brandName: filled($drug['brand_name'] ?? null) ? (string) $drug['brand_name'] : null,
            strengthValue: filled($strength) ? (float) $strength : null,
            strengthUnit: filled($drug['strength_unit'] ?? null) ? (string) $drug['strength_unit'] : null,
            storageNote: filled($drug['storage_note'] ?? null) ? (string) $drug['storage_note'] : null,
            storageCondition: filled($drug['storage_condition'] ?? null) ? StorageCondition::from((string) $drug['storage_condition']) : null,
            registrationNo: filled($drug['registration_no'] ?? null) ? (string) $drug['registration_no'] : null,
            route: filled($drug['route'] ?? null) ? DrugRoute::from((string) $drug['route']) : null,
            minDaysToExpiry: filled($drug['min_days_to_expiry'] ?? null) ? (int) $drug['min_days_to_expiry'] : null,
        );
    }

    /**
     * @param  array<int|string, mixed>  $selected  attribute id => option id
     * @return list<AttributeOption>
     */
    private static function options(array $selected): array
    {
        $ids = array_filter(array_map('intval', $selected));

        if ($ids === []) {
            return [];
        }

        return array_values(AttributeOption::query()->whereKey($ids)->get()->all());
    }

    /**
     * @param  array<int|string, mixed>  $values  attribute id => option id or typed value
     * @return array<int, int|string|null>
     */
    private static function productAttributes(array $values): array
    {
        $types = ProductAttribute::query()->whereKey(array_keys($values))->pluck('type', 'id');
        $result = [];

        foreach ($values as $attributeId => $value) {
            if (blank($value)) {
                continue;
            }

            $type = $types->get($attributeId);

            $result[(int) $attributeId] = $type === AttributeType::Select
                ? (int) $value
                : (string) $value;
        }

        return $result;
    }
}
