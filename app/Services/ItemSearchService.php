<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DrugDetail;
use App\Models\Generic;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\StockBatch;
use App\Models\VariantUnit;
use App\Support\CurrentShop;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The checkout screen's item lookup (GET /pos/search). Returns plain arrays
 * that are safe to send to a cashier: selling price and stock only, never
 * cost. In a pharmacy shop it also finds medicines by generic or brand
 * name, and each medicine carries its drug details.
 *
 * Each item carries its units as sold at the till's location (a pack's
 * barcode scans to that pack) and, for batch-tracked items, the batches
 * at that location in first expiry first out order (docs/PHARMACY.md §9,
 * branch 8). Everything is eager loaded: a search runs the same number of
 * queries however many items it finds.
 */
class ItemSearchService
{
    public const LIMIT = 20;

    /** Product columns present() reads; also used by the offline catalog. */
    public const PRODUCT_COLUMNS = [
        'id', 'name', 'part_number', 'oem_numbers', 'unit', 'track_stock', 'track_batches', 'whole_units_only',
        'allow_price_override', 'tax_category', 'is_active', 'needs_review',
    ];

    /** The relation loadBatches() sets: the till location's sellable batches. */
    private const BATCHES = 'tillBatches';

    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly BatchService $batchService,
    ) {}

    /**
     * @return array{exact: array<string, mixed>|null, results: list<array<string, mixed>>}
     */
    public function search(Device $device, ?string $term, ?int $makeId = null, ?int $modelId = null, ?int $year = null): array
    {
        $term = trim((string) $term);

        // Without vehicle_fitment there is no vehicle filter; a stale one from the till is ignored.
        if (! $this->currentShop->getOrFail()->hasModule('vehicle_fitment')) {
            $makeId = $modelId = $year = null;
        }

        $hasVehicle = $makeId !== null || $modelId !== null;

        if ($term === '' && ! $hasVehicle) {
            return ['exact' => null, 'results' => []];
        }

        $exact = $term === '' ? null : $this->findExact($device, $term);
        $drugNames = $this->currentShop->getOrFail()->hasModule('pharmacy');

        $query = $this->baseQuery($device)
            ->whereHas('product', function (Builder $product) use ($term, $makeId, $modelId, $year, $drugNames): void {
                // An item created while receiving is not for sale until it is reviewed.
                $product->where('is_active', true)->where('needs_review', false)->fitsVehicle($modelId, $year, $makeId);

                if ($term !== '') {
                    $product->search($term, $drugNames);
                }
            })
            ->orderBy('sku')
            ->limit(self::LIMIT);

        $variants = $query->get();
        $this->loadBatches($exact === null ? $variants : $variants->concat([$exact]), $device);

        return [
            'exact' => $exact === null ? null : $this->present($exact, $device, $this->scannedUnitId($exact, $term)),
            'results' => array_values($variants->map(fn (ProductVariant $variant): array => $this->present($variant, $device))->all()),
        ];
    }

    /**
     * One item as the till at $device sees it (the OPD consultation line),
     * or null when it is not for sale there: missing, inactive, waiting for
     * review, or with no unit both sellable and priced at that location.
     *
     * @return array<string, mixed>|null
     */
    public function item(Device $device, int $variantId): ?array
    {
        $variant = $this->baseQuery($device)
            ->whereKey($variantId)
            ->whereHas('product', fn (Builder $product) => $product->where('is_active', true)->where('needs_review', false))
            ->first();

        if ($variant === null) {
            return null;
        }

        $this->loadBatches([$variant], $device);
        $item = $this->present($variant, $device);

        return $item['sellable'] ? $item : null;
    }

    /**
     * A scanned barcode or typed SKU that matches exactly one sellable item:
     * the variant's own barcode or SKU, or the barcode of one of its active
     * units (a box or strip). Barcodes are unique across variants and units.
     */
    private function findExact(Device $device, string $term): ?ProductVariant
    {
        return $this->baseQuery($device)
            ->where(fn (Builder $q) => $q->where('product_variants.barcode', $term)
                ->orWhere('product_variants.sku', $term)
                ->orWhereExists(fn (QueryBuilder $units) => $units->selectRaw('1')
                    ->from('variant_units')
                    ->whereColumn('variant_units.product_variant_id', 'product_variants.id')
                    ->where('variant_units.barcode', $term)
                    ->where('variant_units.is_active', true)))
            ->whereHas('product', fn (Builder $product) => $product->where('is_active', true)->where('needs_review', false))
            ->first();
    }

    /** The pack whose barcode was scanned, or null when the term is the variant's own barcode or SKU. */
    private function scannedUnitId(ProductVariant $variant, string $term): ?int
    {
        if ($variant->barcode === $term || $variant->sku === $term) {
            return null;
        }

        $unit = $variant->units->first(fn (VariantUnit $unit): bool => $unit->is_active && $unit->barcode === $term);

        return $unit === null ? null : (int) $unit->getKey();
    }

    /** @return Builder<ProductVariant> */
    private function baseQuery(Device $device): Builder
    {
        return ProductVariant::query()
            ->where('product_variants.is_active', true)
            ->with(self::relations($this->currentShop->getOrFail(), $device));
    }

    /**
     * What present() needs loaded on a variant (loadBatches() aside): the
     * product (with drug details in a pharmacy shop), every unit with its
     * price on the default list and its selling rule at the till's location,
     * and the stock there. Also used by the offline catalog.
     *
     * @return array<string, \Closure>
     */
    public static function relations(Shop $shop, Device $device): array
    {
        $priceListId = $shop->defaultPriceList()->getKey();
        $locationId = $device->location_id;

        return [
            'product' => fn ($q) => $q->select(self::PRODUCT_COLUMNS)->with(self::drugRelations($shop->hasModule('pharmacy'))),
            'units' => fn ($q) => $q->with([
                'prices' => fn ($q) => $q->where('price_list_id', $priceListId)->where('min_quantity', 1),
                'locationRules' => fn ($q) => $q->where('location_id', $locationId),
            ]),
            'stockLevels' => fn ($q) => $q->where('location_id', $locationId),
        ];
    }

    /**
     * The product relations present() needs for drug details: none without the
     * pharmacy module, so retail searches run the same queries as before.
     *
     * @return array<string, \Closure>
     */
    public static function drugRelations(bool $pharmacy): array
    {
        return $pharmacy ? [
            'drugDetail' => fn ($q) => $q->select(['product_id', 'shop_id', 'brand_name', 'strength_value', 'strength_unit', 'dosage_form', 'schedule', 'min_days_to_expiry']),
            'generics' => fn ($q) => $q->select(['generics.id', 'generics.name']),
        ] : [];
    }

    /**
     * Loads the batches present() lists for batch-tracked items: at the
     * till's location, holding stock, active and not expired today, first
     * expiry first out. One query for all of them, none when no item is
     * batch-tracked (retail).
     *
     * @param  iterable<ProductVariant>  $variants  with product loaded
     */
    public function loadBatches(iterable $variants, Device $device): void
    {
        $variants = collect($variants);
        $tracked = $variants->filter(fn (ProductVariant $variant): bool => $variant->product->tracksBatches());

        $batches = $tracked->isEmpty() ? collect() : StockBatch::query()
            ->whereIn('product_variant_id', $tracked->map(fn (ProductVariant $variant): int => (int) $variant->getKey())->unique()->values()->all())
            ->where('location_id', $device->location_id)
            ->sellableOn($this->batchService->today())
            ->get(['id', 'product_variant_id', 'batch_no', 'expiry_date', 'quantity', 'status', 'received_at'])
            ->groupBy('product_variant_id');

        foreach ($variants as $variant) {
            $variant->setRelation(self::BATCHES, $batches->get($variant->getKey()) ?? new Collection);
        }
    }

    /**
     * The item as the till at $device sees it. Needs relations() and
     * loadBatches() loaded. Also used for the offline catalog. `price` is
     * the base unit's price and `stock` is in base units, as before units;
     * each unit has its own price and says whether it sells at the till's
     * location. $scannedUnitId: the pack whose barcode found it (exact match).
     *
     * @return array<string, mixed>
     */
    public function present(ProductVariant $variant, Device $device, ?int $scannedUnitId = null): array
    {
        /** @var Product $product */
        $product = $variant->product;
        $tracked = (bool) $product->track_stock;
        $locationId = (int) $device->location_id;

        $stock = (float) $variant->stockLevels->sum('quantity');
        $base = $variant->units->firstWhere('is_base', true);

        $units = array_values($variant->units
            ->filter(fn (VariantUnit $unit): bool => $unit->is_active)
            ->map(fn (VariantUnit $unit): array => [
                'id' => (int) $unit->getKey(),
                'name' => $unit->name,
                'factor' => (float) $unit->factor,
                'barcode' => $unit->barcode,
                'price' => $unit->prices->first()?->price,
                'is_base' => $unit->is_base,
                'is_default_sale' => $unit->is_default_sale,
                'sellable' => $variant->canSellUnitAt($unit, $locationId),
            ])
            ->all());

        [$batches, $nearestExpiry] = $this->batches($variant, $product);

        return [
            'variant_id' => $variant->getKey(),
            'name' => $product->name,
            'variant_name' => $variant->name,
            'sku' => $variant->sku,
            'barcode' => $variant->barcode,
            'part_number' => $product->part_number,
            'oem_numbers' => $product->oem_numbers ?? [],
            'unit' => $product->unit,
            'price' => $base?->prices->first()?->price,
            'track_stock' => $tracked,
            'stock' => $tracked ? $stock : null,
            'tax_category' => $product->tax_category->value,
            'drug' => $this->drug($product),
            'units' => $units,
            'sellable' => collect($units)->contains(fn (array $unit): bool => $unit['sellable'] && $unit['price'] !== null),
            'whole_units_only' => (bool) $product->whole_units_only,
            'track_batches' => $product->tracksBatches(),
            'allow_price_override' => $product->allowsPriceOverride(),
            'min_days_to_expiry' => $this->ownMinDays($product),
            'batches' => $batches,
            'nearest_expiry' => $nearestExpiry,
            'scanned_unit_id' => $scannedUnitId,
        ];
    }

    /**
     * The batches the till may sell from (not expired today), and the expiry
     * of the first one still sellable with the item's minimum days to expiry.
     *
     * @return array{0: list<array{id: int, batch_no: string, expiry: string|null, quantity: float}>, 1: string|null}
     */
    private function batches(ProductVariant $variant, Product $product): array
    {
        if (! $product->tracksBatches() || ! $variant->relationLoaded(self::BATCHES)) {
            return [[], null];
        }

        /** @var Collection<int, StockBatch> $batches */
        $batches = $variant->getRelation(self::BATCHES);
        $cutoff = StockBatch::sellableCutoff($this->batchService->today(), $this->minDays($product));

        $first = $batches->first(fn (StockBatch $batch): bool => $batch->isSellableFrom($cutoff));

        return [
            array_values($batches->map(fn (StockBatch $batch): array => [
                'id' => (int) $batch->getKey(),
                'batch_no' => $batch->batch_no,
                'expiry' => $batch->expiry_date?->toDateString(),
                'quantity' => Quantity::normalize((float) $batch->quantity),
            ])->all()),
            $first?->expiry_date?->toDateString(),
        ];
    }

    /** The medicine's own minimum days to expiry; null = the shop setting (and for anything without drug details). */
    private function ownMinDays(Product $product): ?int
    {
        if (! $product->relationLoaded('drugDetail') || ! $product->drugDetail instanceof DrugDetail) {
            return null;
        }

        return $product->drugDetail->min_days_to_expiry;
    }

    /** BatchService::minDaysToExpiry(), without loading drug details the search did not load. */
    private function minDays(Product $product): int
    {
        if ($product->relationLoaded('drugDetail')) {
            return $this->batchService->minDaysToExpiry($product);
        }

        return max(0, (int) $this->currentShop->getOrFail()->setting('min_days_to_expiry_for_sale', 0));
    }

    /**
     * Drug details for the till, or null for anything that is not a medicine
     * (and for every item when the drug relations were not loaded: retail).
     *
     * @return array{generic_names: list<string>, brand_name: string|null, strength: string|null, dosage_form: string, schedule: string}|null
     */
    private function drug(Product $product): ?array
    {
        if (! $product->relationLoaded('drugDetail') || ! $product->drugDetail instanceof DrugDetail) {
            return null;
        }

        $detail = $product->drugDetail;

        return [
            'generic_names' => array_values($product->generics->map(fn (Generic $generic): string => $generic->name)->all()),
            'brand_name' => $detail->brand_name,
            'strength' => $detail->strength(),
            'dosage_form' => $detail->dosage_form->value,
            'schedule' => $detail->schedule->value,
        ];
    }
}
