<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DrugDetail;
use App\Models\Generic;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\StockBatch;
use App\Models\StockLevel;
use App\Models\VariantUnit;
use App\Support\CurrentShop;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Validation\ValidationException;

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
 *
 * Search by location (docs/PHARMACY.md, decision "Till search by
 * location"): in a shop with multi_location and two or more active, sellable
 * locations, results are scoped. `here` (the default) lists what the till's
 * own location holds (stock above 0) and every item that does not track
 * stock (services); a location id lists what that location holds; `all`
 * lists every match, as before. The exact barcode/SKU match is found
 * whatever the scope, so the till can say where a scanned item is. Outside
 * those shops (retail) there is no scope and the payload is unchanged.
 */
class ItemSearchService
{
    public const LIMIT = 20;

    /** Product columns present() reads; also used by the offline catalog. */
    public const PRODUCT_COLUMNS = [
        'id', 'name', 'part_number', 'oem_numbers', 'unit', 'track_stock', 'track_batches', 'whole_units_only',
        'allow_price_override', 'ask_price_at_till', 'tax_category', 'is_active', 'needs_review',
    ];

    /** The relation loadBatches() sets: the till location's sellable batches. */
    private const BATCHES = 'tillBatches';

    /** Scopes of a location search: the till's own location, or every location. A location id is the third. */
    public const SCOPE_HERE = 'here';

    public const SCOPE_ALL = 'all';

    /** Attribute loadBatches() sets when stock here is all held back: 'expired' or 'blocked'. */
    private const HELD_BACK = 'till_held_back';

    /** Attribute loadStockByLocation() sets: stock at each sellable location. */
    private const BY_LOCATION = 'till_stock_by_location';

    /** @var Collection<int, Location>|null */
    private ?Collection $sellableLocations = null;

    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly BatchService $batchService,
    ) {}

    /**
     * @param  string|null  $scope  'here' (default), 'all' or a sellable location's id; ignored without location search
     * @return array{exact: array<string, mixed>|null, results: list<array<string, mixed>>}
     */
    public function search(Device $device, ?string $term, ?int $makeId = null, ?int $modelId = null, ?int $year = null, ?string $scope = null): array
    {
        $term = trim((string) $term);

        // Without vehicle_fitment there is no vehicle filter; a stale one from the till is ignored.
        if (! $this->currentShop->getOrFail()->hasModule('vehicle_fitment')) {
            $makeId = $modelId = $year = null;
        }

        $hasVehicle = $makeId !== null || $modelId !== null;
        $byLocation = $this->locationSearch();
        $scope = $byLocation ? $this->scope($scope) : self::SCOPE_ALL;

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
            ->when($byLocation, fn (Builder $query) => $this->applyScope($query, $device, $scope))
            ->orderBy('sku')
            ->limit(self::LIMIT);

        $variants = $query->get();
        $this->loadBatches($exact === null ? $variants : $variants->concat([$exact]), $device);

        if ($byLocation) {
            // The exact match always says where it is held; results do outside "this location".
            $this->loadStockByLocation($scope === self::SCOPE_HERE ? array_filter([$exact]) : ($exact === null ? $variants : $variants->concat([$exact])));
        }

        return [
            'exact' => $exact === null ? null : $this->present($exact, $device, $this->scannedUnitId($exact, $term)),
            'results' => array_values($variants->map(fn (ProductVariant $variant): array => $this->present($variant, $device))->all()),
        ];
    }

    /**
     * Whether the till's search is scoped by location: multi_location and two
     * or more active, sellable locations (General Pharmacy and the doctor's;
     * Quarantine does not sell). A retail shop with one location: no.
     */
    public function locationSearch(): bool
    {
        return $this->currentShop->getOrFail()->hasModule('multi_location') && $this->sellableLocations()->count() >= 2;
    }

    /**
     * The active, sellable locations, in order: what the till's location
     * picker and stock_by_location list. Never Quarantine / Expired.
     *
     * @return Collection<int, Location>
     */
    public function sellableLocations(): Collection
    {
        return $this->sellableLocations ??= Location::query()
            ->where('is_active', true)
            ->where('is_sellable', true)
            ->ordered()
            ->get(['id', 'code', 'name', 'sort_order']);
    }

    /** The scope asked for: 'here' when none, 'all', or the id of a sellable location (as a string). */
    private function scope(?string $scope): string
    {
        $scope = trim((string) $scope);

        if ($scope === '' || $scope === self::SCOPE_HERE) {
            return self::SCOPE_HERE;
        }

        if ($scope === self::SCOPE_ALL) {
            return self::SCOPE_ALL;
        }

        if (ctype_digit($scope) && $this->sellableLocations()->contains(fn (Location $location): bool => (int) $location->getKey() === (int) $scope)) {
            return (string) (int) $scope;
        }

        throw ValidationException::withMessages(['scope' => 'Choose a location that sells stock.']);
    }

    /**
     * Narrows the results to a scope: 'here' = stock above 0 at the till's
     * location, or no stock tracking (services); a location = stock above 0
     * there; 'all' = no change.
     *
     * @param  Builder<ProductVariant>  $query
     */
    private function applyScope(Builder $query, Device $device, string $scope): void
    {
        if ($scope === self::SCOPE_ALL) {
            return;
        }

        $locationId = $scope === self::SCOPE_HERE ? (int) $device->location_id : (int) $scope;
        $holds = fn (Builder $stock) => $stock->where('location_id', $locationId)->where('quantity', '>', 0);

        if ($scope === self::SCOPE_HERE) {
            $query->where(fn (Builder $here) => $here
                ->whereHas('product', fn (Builder $product) => $product->where('track_stock', false))
                ->orWhereHas('stockLevels', $holds));

            return;
        }

        $query->whereHas('product', fn (Builder $product) => $product->where('track_stock', true))
            ->whereHas('stockLevels', $holds);
    }

    /**
     * Sets each variant's stock at every sellable location (present() lists
     * it as stock_by_location): one query, none for an empty list.
     *
     * @param  iterable<ProductVariant>  $variants
     */
    private function loadStockByLocation(iterable $variants): void
    {
        $variants = collect($variants);

        if ($variants->isEmpty()) {
            return;
        }

        $locations = $this->sellableLocations();
        $levels = StockLevel::query()
            ->whereIn('product_variant_id', $variants->map(fn (ProductVariant $variant): int => (int) $variant->getKey())->unique()->values()->all())
            ->whereIn('location_id', $locations->modelKeys())
            ->get(['product_variant_id', 'location_id', 'quantity'])
            ->groupBy('product_variant_id');

        foreach ($variants as $variant) {
            $held = $levels->get($variant->getKey()) ?? collect();
            $variant->setAttribute(self::BY_LOCATION, array_values($locations->map(fn (Location $location): array => [
                'id' => (int) $location->getKey(),
                'code' => $location->code,
                'stock' => Quantity::normalize((float) ($held->firstWhere('location_id', $location->getKey())->quantity ?? 0)),
            ])->all()));
        }
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

        $this->loadHeldBack($tracked->filter(fn (ProductVariant $variant): bool => ! $batches->has($variant->getKey())), $device);
    }

    /**
     * For batch-tracked items with stock here but no batch the till may sell
     * from today, why: 'expired' when any of it is past its expiry, else
     * 'blocked' (quarantined or recalled). One query, none when every item
     * has a sellable batch or no stock (the usual case).
     *
     * @param  BaseCollection<int, ProductVariant>  $variants  with stockLevels at the till's location loaded
     */
    private function loadHeldBack(BaseCollection $variants, Device $device): void
    {
        $holding = $variants->filter(fn (ProductVariant $variant): bool => (float) $variant->stockLevels->sum('quantity') > 0);

        if ($holding->isEmpty()) {
            return;
        }

        $today = $this->batchService->today()->toDateString();
        $heldBack = StockBatch::query()
            ->whereIn('product_variant_id', $holding->map(fn (ProductVariant $variant): int => (int) $variant->getKey())->unique()->values()->all())
            ->where('location_id', $device->location_id)
            ->inStock()
            ->get(['id', 'product_variant_id', 'expiry_date', 'status'])
            ->groupBy('product_variant_id');

        foreach ($holding as $variant) {
            /** @var Collection<int, StockBatch> $held */
            $held = $heldBack->get($variant->getKey()) ?? new Collection;

            if ($held->isNotEmpty()) {
                $expired = $held->contains(fn (StockBatch $batch): bool => $batch->expiry_date !== null && $batch->expiry_date->toDateString() < $today);
                $variant->setAttribute(self::HELD_BACK, $expired ? 'expired' : 'blocked');
            }
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
            'allow_price_override' => $allowsTypedPrice = $product->allowsPriceOverride(),
            // Branch 14: the limits of a typed price (base unit, minor units; null = none) and
            // whether the till opens the price box as soon as the item is added.
            'price_min' => $allowsTypedPrice ? $variant->till_price_min : null,
            'price_max' => $allowsTypedPrice ? $variant->till_price_max : null,
            'ask_price' => $product->asksPriceAtTill(),
            'min_days_to_expiry' => $this->ownMinDays($product),
            'batches' => $batches,
            'nearest_expiry' => $nearestExpiry,
            'held_back' => $this->heldBack($variant, $product, $stock, $batches, $nearestExpiry),
            'scanned_unit_id' => $scannedUnitId,
            ...($variant->hasAttribute(self::BY_LOCATION) ? ['stock_by_location' => $variant->getAttribute(self::BY_LOCATION)] : []),
        ];
    }

    /**
     * Why a batch-tracked item with stock here can't be sold from it today,
     * by the batch rules (no change to what sells): 'expired' (only expired
     * stock), 'blocked' (only quarantined or recalled stock), 'near_expiry'
     * (only stock past its stop-selling date, the minimum days to expiry),
     * or null. The till shows it on the row and when the item is picked;
     * resources/js/lib/offline/searchCatalog.ts heldBack() mirrors it.
     *
     * @param  list<array<string, mixed>>  $batches
     */
    private function heldBack(ProductVariant $variant, Product $product, float $stock, array $batches, ?string $nearestExpiry): ?string
    {
        if (! $product->track_stock || ! $product->tracksBatches() || $stock <= 0) {
            return null;
        }

        if ($batches === []) {
            $reason = $variant->getAttribute(self::HELD_BACK);

            return is_string($reason) ? $reason : null;
        }

        return $nearestExpiry === null ? 'near_expiry' : null;
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
