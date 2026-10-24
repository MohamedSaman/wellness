<?php

namespace App\Services;

use App\Enums\TaxCategory;
use App\Exceptions\PosException;
use App\Models\AttributeOption;
use App\Models\Generic;
use App\Models\Location;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockBatch;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\VariantUnit;
use App\Observers\AuditObserver;
use App\Services\Data\BatchInput;
use App\Services\Data\DrugData;
use App\Support\CurrentShop;
use App\Support\Quantity;
use App\Support\UnitCost;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates and edits products with their variants, units (base unit and
 * packs), prices and opening stock, and for medicines their drug details
 * and generic names. Used by the seeders and the admin product form.
 */
class ProductCatalogService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly CurrentShop $currentShop,
        private readonly BarcodeService $barcodes,
    ) {}

    /**
     * Every variant gets a base unit named after the product's `unit` (stock
     * is counted in it) and, optionally, packs of that unit, each with its own
     * price. `price` is the base unit's price.
     *
     * @param  array<string, mixed>  $product  Product columns (name, category_id, brand_id, part_number, unit, whole_units_only ...)
     * @param  list<array{
     *     options?: list<AttributeOption>,
     *     sku?: string|null,
     *     barcode?: string|null,
     *     price?: int|null,
     *     cost?: int,
     *     opening_stock?: float,
     *     opening_location_id?: int|null,
     *     opening_batch?: BatchInput|null,
     *     reorder_level?: float,
     *     bin_location?: string|null,
     *     allow_unit_sale?: bool,
     *     loose_at?: array<int, bool|null>,
     *     packs?: list<array<string, mixed>>,
     * }>  $variants  price null: the base unit gets no price, so it can't be sold yet (an item created while
     *                receiving); omitted: 0, as before;
     *                packs: name, factor, barcode, price, is_sellable, is_purchasable, is_active;
     *                opening stock goes to opening_location_id, else the user's (or the shop's) default location;
     *                for a batch-tracked product it needs opening_batch (batch number and expiry)
     * @param  array<int, int|string|null>  $productAttributes  product_attribute_id => option id (select) or value (text)
     * @param  list<int>|null  $fitmentIds  vehicle_model ids the part fits (spare parts); null = none
     * @param  DrugData|null  $drug  makes the product a medicine: whole units only, tax exempt and batch-tracked
     *                               unless $product says otherwise
     */
    public function create(array $product, array $variants, array $productAttributes = [], ?User $user = null, ?array $fitmentIds = [], ?DrugData $drug = null): Product
    {
        $this->authorize($user);

        if ($variants === []) {
            throw new PosException('A product needs at least one variant.');
        }

        if ($drug !== null) {
            $product += ['whole_units_only' => true, 'tax_category' => TaxCategory::Exempt, 'track_batches' => true];
        }

        // A service has no stock, so no batches either.
        if (($product['track_stock'] ?? true) === false) {
            $product['track_batches'] = false;
        }

        $this->guardUniqueCodes($variants);

        if ($user !== null) {
            $canPrice = $user->can('prices.manage');
            $canCost = $user->can('cost.view');

            $variants = array_map(fn (array $data): array => [
                ...$data,
                'price' => $this->noPrice($data) ? null : ($canPrice ? ($data['price'] ?? 0) : 0),
                'cost' => $canCost ? ($data['cost'] ?? 0) : 0,
                'packs' => $canPrice ? ($data['packs'] ?? []) : $this->withoutPrices($data['packs'] ?? []),
            ], $variants);
        }

        $shop = $this->currentShop->getOrFail();
        $priceList = $shop->defaultPriceList();
        $location = $user?->workLocation($shop) ?? $shop->defaultLocation();

        return DB::transaction(function () use ($product, $variants, $productAttributes, $user, $priceList, $location, $fitmentIds, $drug): Product {
            $model = Product::query()->create($product);

            if ($drug !== null) {
                $this->saveDrugDetails($model, $drug);
            }

            $this->syncProductAttributes($model, $productAttributes);
            $model->fitments()->sync($fitmentIds ?? []);

            foreach ($variants as $data) {
                $this->createVariant($model, $data, $priceList, $location, $user);
            }

            $this->guardPackFactors($model);

            return $model->load('variants');
        });
    }

    /**
     * Edit a product, its existing variants and (optionally) add new variants.
     * Stock is never touched here, apart from opening stock on a brand-new
     * variant. Prices need prices.manage and cost needs cost.view; without them
     * the submitted values are ignored. Variants missing from $variants are left
     * as they are (deactivate them instead of deleting); so are packs missing
     * from a variant's `packs`, and all packs when `packs` is not given.
     *
     * The base unit (`unit`) cannot change once any variant of the product
     * has a stock movement: every quantity in the ledger is in that unit.
     *
     * @param  array<string, mixed>  $attributes  Product columns
     * @param  list<array<string, mixed>>  $variants  Each with an `id` (existing) or `options` (new), plus
     *                                                sku, barcode, price, cost, reorder_level, bin_location, is_active,
     *                                                allow_unit_sale, loose_at (location id => sell loose there, null = as allow_unit_sale), packs
     * @param  array<int, int|string|null>  $productAttributes
     * @param  list<int>|null  $fitmentIds  null leaves the product's fitments as they are
     * @param  DrugData|false|null  $drug  null leaves the drug details as they are; false means not a medicine (removes them)
     */
    public function update(Product $product, array $attributes, array $variants, array $productAttributes, ?array $fitmentIds, User $user, DrugData|false|null $drug = null): Product
    {
        $this->authorize($user);

        $this->guardUniqueCodes($variants);

        $canPrice = $user->can('prices.manage');
        $canCost = $user->can('cost.view');
        $shop = $this->currentShop->getOrFail();
        $priceList = $shop->defaultPriceList();
        $location = $user->workLocation($shop);

        return DB::transaction(function () use ($product, $attributes, $variants, $productAttributes, $fitmentIds, $user, $canPrice, $canCost, $priceList, $location, $drug): Product {
            $renamedBase = $this->guardBaseUnitChange($product, $attributes);
            $attributes = $this->guardBatchTrackingChange($product, $attributes);

            $product->update($attributes);

            if ($renamedBase !== null) {
                $this->renameBaseUnits($product, $renamedBase);
            }

            if ($drug instanceof DrugData) {
                $this->saveDrugDetails($product, $drug);
            } elseif ($drug === false) {
                $this->removeDrugDetails($product);
            }

            $this->syncProductAttributes($product, $productAttributes);

            if ($fitmentIds !== null) {
                $product->fitments()->sync($fitmentIds);
            }

            foreach ($variants as $data) {
                if (blank($data['id'] ?? null)) {
                    $data['price'] = $canPrice ? ($data['price'] ?? 0) : 0;
                    $data['cost'] = $canCost ? ($data['cost'] ?? 0) : 0;
                    $data['packs'] = $canPrice ? ($data['packs'] ?? []) : $this->withoutPrices($data['packs'] ?? []);
                    $this->createVariant($product, $data, $priceList, $location, $user);

                    continue;
                }

                /** @var ProductVariant $variant */
                $variant = $product->variants()->findOrFail($data['id']);

                $changes = [
                    'sku' => $data['sku'] ?? $variant->sku,
                    'barcode' => filled($data['barcode'] ?? null) ? $data['barcode'] : $variant->barcode,
                    'reorder_level' => $data['reorder_level'] ?? $variant->reorder_level,
                    'bin_location' => $data['bin_location'] ?? null,
                    'is_active' => (bool) ($data['is_active'] ?? true),
                ];

                // Existing variants' cost is the weighted average: it changes only
                // through Stock In or revalueCost(), never from the product form.

                $variant->update($changes);

                $base = $this->baseUnitOf($variant);

                if (array_key_exists('allow_unit_sale', $data)) {
                    $base->update(['is_sellable' => (bool) $data['allow_unit_sale']]);
                }

                if (array_key_exists('loose_at', $data)) {
                    $this->saveLooseSale($base, $this->looseLocations($data['loose_at']));
                }

                if ($canPrice && isset($data['price'])) {
                    $this->setPrice($base, $priceList, (int) $data['price']);
                }

                if (array_key_exists('packs', $data)) {
                    $this->syncPacks($variant, $data['packs'] ?? [], $priceList, $canPrice);
                }
            }

            $this->guardPackFactors($product);

            return $product->load('variants');
        });
    }

    /**
     * Selling the base unit loose per location (docs/PHARMACY.md §4.2, client
     * answers 2026-10-20): syrup in ml at Doctor's Medicines, bottles only at
     * General Pharmacy. true or false sets the location's own rule; null
     * removes it, so the location follows the item's "Sell loose" again (and
     * touches the base unit, for the catalog delta). Locations not listed are
     * left as they are.
     *
     * @param  array<int, bool|null>  $byLocation  location id => sell loose there
     */
    private function saveLooseSale(VariantUnit $base, array $byLocation): void
    {
        foreach ($byLocation as $locationId => $sellable) {
            if ($sellable === null) {
                $removed = $base->locationRules()->where('location_id', $locationId)->get()->each->delete();

                // A deleted rule leaves no updated_at behind: touch the unit so the
                // tills' next catalog delta sends the item again.
                if ($removed->isNotEmpty()) {
                    $base->touch();
                }

                continue;
            }

            $base->locationRules()->updateOrCreate(['location_id' => $locationId], ['is_sellable' => $sellable]);
        }
    }

    /**
     * Creates or replaces a product's drug details and generic names. Generics
     * are shared by name across the shop ("paracetamol" reuses Paracetamol).
     * The product is touched so tills pick the change up in their next sync.
     */
    private function saveDrugDetails(Product $product, DrugData $drug): void
    {
        $names = [];

        foreach ($drug->genericNames as $name) {
            $name = Str::squish($name);

            if ($name !== '' && ! isset($names[Str::lower($name)])) {
                $names[Str::lower($name)] = Str::limit($name, 150, '');
            }
        }

        if ($names === []) {
            throw new PosException("{$product->name} needs a generic name (Paracetamol, Amoxicillin ...).");
        }

        $strengthUnit = filled($drug->strengthUnit) ? Str::squish((string) $drug->strengthUnit) : null;

        if ($drug->strengthValue !== null && $drug->strengthValue <= 0) {
            throw new PosException('A strength must be more than zero.');
        }

        if ($drug->strengthValue !== null && $strengthUnit === null) {
            throw new PosException('Say what the strength is measured in (mg, ml, mg/5 ml ...).');
        }

        if ($drug->minDaysToExpiry !== null && ($drug->minDaysToExpiry < 0 || $drug->minDaysToExpiry > 3650)) {
            throw new PosException('Days before expiry must be between 0 and 3650.');
        }

        $product->drugDetail()->updateOrCreate([], [
            'brand_name' => filled($drug->brandName) ? Str::limit(Str::squish((string) $drug->brandName), 150, '') : null,
            'strength_value' => $drug->strengthValue === null ? null : Quantity::normalize($drug->strengthValue),
            'strength_unit' => $drug->strengthValue === null ? null : Str::limit((string) $strengthUnit, 20, ''),
            'dosage_form' => $drug->dosageForm,
            'schedule' => $drug->schedule,
            'storage_condition' => $drug->storageCondition,
            'storage_note' => filled($drug->storageNote) ? Str::limit(trim((string) $drug->storageNote), 255, '') : null,
            'registration_no' => filled($drug->registrationNo) ? Str::limit(Str::squish((string) $drug->registrationNo), 50, '') : null,
            'route' => $drug->route,
            'min_days_to_expiry' => $drug->minDaysToExpiry,
        ]);

        $product->productGenerics()->delete();

        foreach (array_values($names) as $order => $name) {
            $slug = Str::slug($name) ?: Str::lower($name);
            $generic = Generic::query()->firstOrCreate(['slug' => $slug], ['name' => $name]);

            $product->productGenerics()->create(['generic_id' => $generic->getKey(), 'sort_order' => $order]);
        }

        $product->unsetRelation('drugDetail')->unsetRelation('generics');
        $product->touch();
    }

    /** No longer a medicine: drop its drug details and generic names (sales keep their own snapshot). */
    private function removeDrugDetails(Product $product): void
    {
        $detail = $product->drugDetail()->first();

        if ($detail === null) {
            return;
        }

        $detail->delete();
        $product->productGenerics()->delete();
        $product->unsetRelation('drugDetail')->unsetRelation('generics');
        $product->touch();
    }

    /**
     * Batch tracking can only be switched on or off while the item holds no
     * stock anywhere, in no batch: stock on hand must be in batches exactly
     * when the item tracks them (docs/PHARMACY.md §4.5). A service never
     * tracks batches.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function guardBatchTrackingChange(Product $product, array $attributes): array
    {
        if (($attributes['track_stock'] ?? $product->track_stock) === false) {
            $attributes['track_batches'] = false;
        }

        if (! array_key_exists('track_batches', $attributes) || (bool) $attributes['track_batches'] === (bool) $product->track_batches) {
            return $attributes;
        }

        $variantIds = $product->variants()->withTrashed()->select('id');
        $holdsStock = StockLevel::query()->whereIn('product_variant_id', $variantIds)->where('quantity', '!=', 0)->exists()
            || StockBatch::query()->whereIn('product_variant_id', $variantIds)->where('quantity', '!=', 0)->exists();

        if ($holdsStock) {
            throw new PosException($attributes['track_batches']
                ? "{$product->name} has stock that is not in batches. Count it down to zero (or sell it) before tracking batches, then receive it again with its batch numbers."
                : "{$product->name} has stock in batches. Batch tracking can only be turned off once its stock is zero.");
        }

        return $attributes;
    }

    /**
     * The new base unit name when `unit` changes, or null when it does not.
     * Refused once the product has stock movements (docs/PHARMACY.md §4.2).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function guardBaseUnitChange(Product $product, array $attributes): ?string
    {
        if (! array_key_exists('unit', $attributes)) {
            return null;
        }

        $name = trim((string) $attributes['unit']);

        if ($name === $product->unit) {
            return null;
        }

        if ($name === '') {
            throw new PosException('The base unit needs a name.');
        }

        $hasMovements = StockMovement::query()
            ->whereIn('product_variant_id', $product->variants()->withTrashed()->select('id'))
            ->exists();

        if ($hasMovements) {
            throw new PosException("The base unit ({$product->unit}) can't be changed: this product already has stock movements, and they are counted in {$product->unit}. Create a new product instead.");
        }

        return $name;
    }

    private function renameBaseUnits(Product $product, string $name): void
    {
        $variantIds = $product->variants()->withTrashed()->pluck('id');

        $clash = VariantUnit::query()
            ->whereIn('product_variant_id', $variantIds)
            ->where('is_base', false)
            ->get()
            ->first(fn (VariantUnit $pack): bool => Str::lower($pack->name) === Str::lower($name));

        if ($clash !== null) {
            throw new PosException("A pack is already called {$clash->name}; the base unit needs another name.");
        }

        VariantUnit::query()->whereIn('product_variant_id', $variantIds)->where('is_base', true)->update(['name' => $name]);
    }

    private function baseUnitOf(ProductVariant $variant): VariantUnit
    {
        return $variant->baseUnit()->first()
            ?? throw new \LogicException("Variant {$variant->sku} has no base unit.");
    }

    /**
     * Add or change a variant's packs. Packs not listed are left as they are
     * (deactivate them instead of deleting: sale lines point at them). A pack's
     * factor may change: sale lines snapshot the factor they were sold at.
     *
     * @param  list<array<string, mixed>>  $packs  id (existing), name, factor, barcode, price, is_sellable, is_purchasable, is_active
     */
    private function syncPacks(ProductVariant $variant, array $packs, PriceList $priceList, bool $canPrice): void
    {
        $base = $this->baseUnitOf($variant);
        $names = [Str::lower($base->name) => true];
        $sortOrder = (int) $variant->units()->max('sort_order');

        foreach ($packs as $data) {
            $name = trim((string) ($data['name'] ?? ''));
            $factor = Quantity::normalize($data['factor'] ?? 0);

            if ($name === '') {
                throw new PosException("Every pack of {$variant->sku} needs a name.");
            }

            if (isset($names[Str::lower($name)])) {
                throw new PosException("{$variant->sku} has two units called {$name}.");
            }

            $names[Str::lower($name)] = true;

            if ($factor <= 0) {
                throw new PosException("Say how many {$base->name} are in one {$name}.");
            }

            $values = [
                'name' => $name,
                'factor' => $factor,
                'barcode' => filled($data['barcode'] ?? null) ? trim((string) $data['barcode']) : null,
                'is_sellable' => (bool) ($data['is_sellable'] ?? true),
                'is_purchasable' => (bool) ($data['is_purchasable'] ?? true),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ];

            if (filled($data['id'] ?? null)) {
                /** @var VariantUnit $pack */
                $pack = $variant->units()->where('is_base', false)->findOrFail($data['id']);
                $pack->update($values);
            } else {
                $pack = $variant->units()->create([...$values, 'sort_order' => ++$sortOrder]);
            }

            // A pack without a price simply can't be sold yet; nothing is derived.
            if ($canPrice && isset($data['price'])) {
                $this->setPrice($pack, $priceList, (int) $data['price']);
            }
        }
    }

    private function setPrice(VariantUnit $unit, PriceList $priceList, int $price): void
    {
        if ($price < 0) {
            throw new PosException('A price cannot be negative.');
        }

        $unit->prices()->updateOrCreate(
            ['price_list_id' => $priceList->getKey(), 'min_quantity' => 1],
            ['product_variant_id' => $unit->product_variant_id, 'price' => $price],
        );
    }

    /** No half tablets: every active pack of a whole-units product holds a whole number of base units. */
    private function guardPackFactors(Product $product): void
    {
        if (! $product->whole_units_only) {
            return;
        }

        $fractional = VariantUnit::query()
            ->whereIn('product_variant_id', $product->variants()->select('id'))
            ->where('is_active', true)
            ->get()
            ->first(fn (VariantUnit $unit): bool => ! Quantity::isWhole((float) $unit->factor));

        if ($fractional !== null) {
            throw new PosException("{$product->name} is sold in whole {$product->unit} only, so a {$fractional->name} must hold a whole number of them (not ".Quantity::format((float) $fractional->factor).').');
        }
    }

    /**
     * Packs as a user without prices.manage may submit them: no prices.
     *
     * @param  list<array<string, mixed>>  $packs
     * @return list<array<string, mixed>>
     */
    private function withoutPrices(array $packs): array
    {
        return array_map(fn (array $pack): array => Arr::except($pack, ['price']), $packs);
    }

    private function authorize(?User $user): void
    {
        if ($user !== null && ! $user->can('products.manage')) {
            throw new PosException('You do not have permission to manage products.');
        }
    }

    /**
     * @param  array<int, int|string|null>  $productAttributes
     */
    private function syncProductAttributes(Product $product, array $productAttributes): void
    {
        $product->attributeValues()->delete();

        foreach ($productAttributes as $attributeId => $value) {
            if (blank($value)) {
                continue;
            }

            $isOption = is_int($value);

            $product->attributeValues()->create([
                'product_attribute_id' => $attributeId,
                'attribute_option_id' => $isOption ? $value : null,
                'value' => $isOption ? null : $value,
            ]);
        }
    }

    /**
     * Friendly errors instead of database unique-key failures. A barcode is
     * unique per shop across variants and packs together; no index can span
     * both tables, so this is the check.
     *
     * @param  list<array<string, mixed>>  $variants
     */
    private function guardUniqueCodes(array $variants): void
    {
        foreach (['sku' => 'SKU', 'barcode' => 'Barcode'] as $column => $label) {
            $codes = collect($variants)->pluck($column)->filter(fn ($code) => filled($code));

            if ($codes->count() !== $codes->unique()->count()) {
                throw new PosException("Two variants use the same {$label}.");
            }

            foreach ($variants as $data) {
                $code = $data[$column] ?? null;

                if (blank($code)) {
                    continue;
                }

                $taken = ProductVariant::withTrashed()
                    ->where($column, $code)
                    ->when(filled($data['id'] ?? null), fn ($query) => $query->whereKeyNot($data['id']))
                    ->exists();

                if ($taken || ($column === 'barcode' && VariantUnit::query()->where('barcode', $code)->exists())) {
                    throw new PosException("The {$label} {$code} is already used by another variant.");
                }
            }
        }

        $packs = collect($variants)->flatMap(fn (array $data): array => $data['packs'] ?? []);
        $packCodes = $packs->pluck('barcode')->filter(fn ($code) => filled($code))->map(fn ($code) => trim((string) $code));

        if ($packCodes->count() !== $packCodes->unique()->count() || $packCodes->intersect(collect($variants)->pluck('barcode'))->isNotEmpty()) {
            throw new PosException('Two units use the same Barcode.');
        }

        foreach ($packs as $pack) {
            $code = filled($pack['barcode'] ?? null) ? trim((string) $pack['barcode']) : null;

            if ($code === null) {
                continue;
            }

            $taken = ProductVariant::withTrashed()->where('barcode', $code)->exists()
                || VariantUnit::query()
                    ->where('barcode', $code)
                    ->when(filled($pack['id'] ?? null), fn ($query) => $query->whereKeyNot($pack['id']))
                    ->exists();

            if ($taken) {
                throw new PosException("The Barcode {$code} is already used by another item or pack.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createVariant(Product $product, array $data, PriceList $priceList, Location $location, ?User $user): ProductVariant
    {
        /** @var list<AttributeOption> $options */
        $options = $data['options'] ?? [];

        $variant = $product->variants()->create([
            'name' => $options === [] ? null : collect($options)->pluck('value')->implode(' / '),
            'sku' => $data['sku'] ?? $this->generateSku($product, $options),
            'barcode' => $data['barcode'] ?? null,
            'cost_price' => $data['cost'] ?? 0,
            'last_cost_price' => ($data['cost'] ?? 0) > 0 ? (int) $data['cost'] : null,
            'reorder_level' => $data['reorder_level'] ?? 0,
            'bin_location' => $data['bin_location'] ?? null,
        ]);

        // In-store barcode when the item has no manufacturer barcode (200 + its id, free across items and packs).
        if ($variant->barcode === null) {
            $variant->update(['barcode' => $this->barcodes->inStoreCode($variant)]);
        }

        foreach ($options as $option) {
            $variant->attributeValues()->create([
                'product_attribute_id' => $option->product_attribute_id,
                'attribute_option_id' => $option->getKey(),
            ]);
        }

        // Stock is counted in the base unit. With no packs it is the only unit
        // and always sellable, whatever allow_unit_sale says (Q16).
        $base = $variant->units()->create([
            'name' => trim((string) ($product->unit ?: 'pcs')),
            'factor' => 1,
            'is_base' => true,
            'is_sellable' => (bool) ($data['allow_unit_sale'] ?? true),
            'is_default_sale' => true,
        ]);

        if (! $this->noPrice($data)) {
            $this->setPrice($base, $priceList, (int) ($data['price'] ?? 0));
        }

        if (array_key_exists('loose_at', $data)) {
            $this->saveLooseSale($base, $this->looseLocations($data['loose_at']));
        }

        $this->syncPacks($variant, $data['packs'] ?? [], $priceList, true);

        if (($data['opening_stock'] ?? 0) > 0 && $product->track_stock !== false) {
            $this->stock->opening($variant, $this->openingLocation($data, $location), (float) $data['opening_stock'], $user, $variant->cost_price, $data['opening_batch'] ?? null);
        }

        return $variant;
    }

    /**
     * Per-location loose sale from a form or an import, for locations of this
     * shop only.
     *
     * @return array<int, bool|null>
     */
    private function looseLocations(mixed $byLocation): array
    {
        $byLocation = is_array($byLocation) ? $byLocation : [];
        $known = Location::query()->whereKey(array_keys($byLocation))->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if (count($known) !== count($byLocation)) {
            throw new PosException('Choose locations of this shop.');
        }

        $rules = [];

        foreach ($byLocation as $locationId => $sellable) {
            $rules[(int) $locationId] = $sellable === null ? null : (bool) $sellable;
        }

        return $rules;
    }

    /**
     * A variant given an explicit null price has no price at all (not for sale yet), not a price of 0.
     *
     * @param  array<string, mixed>  $data
     */
    private function noPrice(array $data): bool
    {
        return array_key_exists('price', $data) && $data['price'] === null;
    }

    /**
     * Where a new variant's opening stock goes: the location asked for (an
     * active location of this shop), or the default passed in.
     *
     * @param  array<string, mixed>  $data
     */
    private function openingLocation(array $data, Location $default): Location
    {
        $id = $data['opening_location_id'] ?? null;

        if ($id === null || (int) $id === (int) $default->getKey()) {
            return $default;
        }

        return Location::query()->whereKey((int) $id)->where('is_active', true)->first()
            ?? throw new PosException('Choose an active location for the opening stock.');
    }

    /** Longest product part of a generated SKU ("STUDDS-NINJA-ELITE-HELMET" fits whole). */
    public const SKU_BASE_LENGTH = 60;

    /** Generated SKUs stay under the 100-character column, with room for a "-12" duplicate suffix. */
    public const SKU_MAX_LENGTH = 90;

    /**
     * Set the average cost at a location by hand (a supplier credit, a costing
     * mistake). Owner only (cost.manage). The old and new cost and the reason go
     * to the audit log as one "cost_revalued" entry; with multi_location it also
     * names the location and its old and new average. Stock and past sales are
     * untouched: sale lines keep the cost they were sold at.
     *
     * @param  Location|null  $location  null: the shop's default location (the only one without multi_location)
     */
    public function revalueCost(ProductVariant $variant, int|string $newCost, string $reason, User $user, ?Location $location = null): ProductVariant
    {
        if (! $user->can('cost.manage')) {
            throw new PosException('You do not have permission to revalue costs.');
        }

        if (blank($reason)) {
            throw new PosException('A reason is required to revalue a cost.');
        }

        $shop = $this->currentShop->getOrFail();
        $location ??= $shop->defaultLocation();

        return DB::transaction(function () use ($shop, $variant, $newCost, $reason, $user, $location): ProductVariant {
            // Quietly: the audit entry below says why, instead of a bare "updated".
            [$oldAverage, $oldCost, $afterMovementId] = $this->stock->setAverageCost($variant, $location, $newCost);

            $old = ['cost_price' => $oldCost];
            $new = ['cost_price' => (int) $variant->cost_price];

            if ($shop->hasModule('multi_location')) {
                $old += ['location' => $location->code, 'average_cost' => $oldAverage];
                $new += ['location' => $location->code, 'average_cost' => UnitCost::normalize($newCost)];
            }

            // Where and when in the ledger, so pos:stock-rebuild --costs can replay it in order.
            $new += ['location_id' => (int) $location->getKey(), 'after_movement_id' => $afterMovementId];

            app(AuditObserver::class)->record('cost_revalued', $variant, $old, [...$new, 'reason' => trim($reason)], (int) $user->getKey());

            return $variant;
        });
    }

    /**
     * SKU like "VEGA-OFFROAD-M-BLK": brand + product words + option codes.
     * Long names are cut at a word boundary, never mid-word. Only used for new variants.
     *
     * @param  list<AttributeOption>  $options
     */
    private function generateSku(Product $product, array $options): string
    {
        $base = $this->limitAtWord(Str::upper(Str::slug($product->part_number ?: $product->name)), self::SKU_BASE_LENGTH);
        $suffix = collect($options)->map(fn (AttributeOption $o) => Str::upper($o->code ?: Str::slug($o->value)))->implode('-');
        $sku = rtrim(Str::substr(trim($base.($suffix !== '' ? '-'.$suffix : ''), '-'), 0, self::SKU_MAX_LENGTH), '-');

        $candidate = $sku;
        $n = 2;

        while (ProductVariant::withTrashed()->where('sku', $candidate)->exists()) {
            $candidate = "{$sku}-{$n}";
            $n++;
        }

        return $candidate;
    }

    /** "STUDDS-NINJA-ELITE" rather than "STUDDS-NINJA-ELI" when a slug is too long. */
    private function limitAtWord(string $slug, int $length): string
    {
        if (Str::length($slug) <= $length) {
            return $slug;
        }

        $cut = Str::substr($slug, 0, $length + 1);
        $lastDash = strrpos($cut, '-');

        return $lastDash === false || $lastDash === 0 ? Str::substr($slug, 0, $length) : Str::substr($cut, 0, $lastDash);
    }
}
