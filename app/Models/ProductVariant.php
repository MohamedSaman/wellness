<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The sellable, stockable item: one SKU + one barcode + its own stock.
 * e.g. "Vega Off-Road Helmet / M / Matte Black".
 */
class ProductVariant extends Model
{
    use BelongsToShop, HasPublicUuid, SoftDeletes;

    protected $guarded = ['id'];

    /** Costs are sensitive: hidden from JSON unless explicitly shown. */
    protected $hidden = ['cost_price', 'last_cost_price'];

    protected function casts(): array
    {
        return [
            'cost_price' => 'integer',      // weighted average cost (see StockService)
            'last_cost_price' => 'integer', // unit cost of the latest delivery
            'reorder_level' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * Prices of the base unit: "the variant's price" everywhere that predates
     * units (search, catalog sync, reports). Pack prices are on units().
     *
     * @return HasMany<VariantPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(VariantPrice::class)
            ->whereHas('unit', fn (Builder $unit) => $unit->where('is_base', true));
    }

    /**
     * The base unit first, then packs in their order.
     *
     * @return HasMany<VariantUnit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(VariantUnit::class)
            ->orderByDesc('is_base')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /** @return HasOne<VariantUnit, $this> */
    public function baseUnit(): HasOne
    {
        return $this->hasOne(VariantUnit::class)->where('is_base', true);
    }

    /**
     * Whether a unit can be sold (docs/PHARMACY.md Q16). A pack sells when it
     * is active and sellable. The base unit sells when allow_unit_sale (its
     * is_sellable) is on, and always when no active pack is sellable: a
     * bottle with no carton, a tube. Needs units loaded.
     */
    public function canSellUnit(VariantUnit $unit): bool
    {
        if (! $unit->is_active || (int) $unit->product_variant_id !== (int) $this->getKey()) {
            return false;
        }

        if (! $unit->is_base || $unit->is_sellable) {
            return $unit->is_sellable;
        }

        return $this->units->doesntContain(fn (VariantUnit $other): bool => ! $other->is_base && $other->is_active && $other->is_sellable);
    }

    /**
     * canSellUnit() at one location: the same rule (Q16), with each unit's
     * loose sale as set for that location (VariantUnit::sellableAt()). Syrup
     * with loose ml on at Doctor's Medicines and off at General Pharmacy
     * sells ml at the one and bottles only at the other. Checkout reads it
     * from branch 8. Needs units loaded.
     */
    public function canSellUnitAt(VariantUnit $unit, Location|int $location): bool
    {
        if (! $unit->is_active || (int) $unit->product_variant_id !== (int) $this->getKey()) {
            return false;
        }

        $sellable = $unit->sellableAt($location);

        if (! $unit->is_base || $sellable) {
            return $sellable;
        }

        return $this->units->doesntContain(fn (VariantUnit $other): bool => ! $other->is_base && $other->is_active && $other->sellableAt($location));
    }

    /** @return HasMany<VariantAttributeValue, $this> */
    public function attributeValues(): HasMany
    {
        return $this->hasMany(VariantAttributeValue::class);
    }

    /** @return HasMany<StockLevel, $this> */
    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    /** @return HasMany<StockMovement, $this> */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Stock-tracked variants at or below their reorder level at some location
     * (or at the one given). A location's own reorder level overrides the
     * variant's; 0 means not monitored there. See whereReorderNeeded().
     *
     * @param  Builder<ProductVariant>  $query
     */
    public function scopeLowStock(Builder $query, ?int $locationId = null): void
    {
        $query->whereHas('product', fn (Builder $product) => $product->where('track_stock', true))
            ->whereExists(fn (QueryBuilder $levels) => $levels->selectRaw('1')
                ->from('locations')
                ->leftJoin('stock_levels', fn ($join) => $join
                    ->on('stock_levels.location_id', '=', 'locations.id')
                    ->on('stock_levels.product_variant_id', '=', 'product_variants.id'))
                ->whereColumn('locations.shop_id', 'product_variants.shop_id')
                ->where('locations.is_active', true)
                ->tap(fn (QueryBuilder $levels) => self::whereReorderNeeded($levels))
                ->when($locationId !== null, fn (QueryBuilder $levels) => $levels->where('locations.id', $locationId)));
    }

    /**
     * The low-stock rule on a query joining locations to stock_levels (left
     * join) and product_variants. An item is watched at the shop's default
     * location, and at any other location where it has a stock level (it was
     * ever stocked there, or given a reorder level there), so a pool that never
     * carries an item is not flagged for it. A one-location shop watches
     * every item exactly as before.
     */
    public static function whereReorderNeeded(QueryBuilder $query): void
    {
        $query->where(fn (QueryBuilder $watched) => $watched->whereNotNull('stock_levels.id')->orWhere('locations.is_default', true))
            ->whereRaw('COALESCE(stock_levels.reorder_level, product_variants.reorder_level) > 0')
            ->whereRaw('COALESCE(stock_levels.quantity, 0) <= COALESCE(stock_levels.reorder_level, product_variants.reorder_level)');
    }

    /**
     * Stock-tracked variants with less than zero on hand at some location (or
     * at the one given): sold offline or before the stock was booked in. They
     * need a stock count.
     *
     * @param  Builder<ProductVariant>  $query
     */
    public function scopeNegativeStock(Builder $query, ?int $locationId = null): void
    {
        $query->whereHas('product', fn (Builder $product) => $product->where('track_stock', true))
            ->whereExists(fn (QueryBuilder $levels) => $levels->selectRaw('1')
                ->from('stock_levels')
                ->whereColumn('stock_levels.product_variant_id', 'product_variants.id')
                ->where('stock_levels.quantity', '<', 0)
                ->when($locationId !== null, fn (QueryBuilder $levels) => $levels->where('stock_levels.location_id', $locationId)));
    }

    /**
     * No barcode, or an in-store one (EAN-13 in 200-299, App\Support\Barcode):
     * the item needs a printed label to scan (labels module).
     *
     * @param  Builder<ProductVariant>  $query
     */
    public function scopeWithoutManufacturerBarcode(Builder $query): void
    {
        $column = $this->qualifyColumn('barcode');

        $query->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, 'like', '2'.str_repeat('_', 12)));
    }

    /** Selling price in minor units on a price list (default list if null). */
    public function priceOn(?PriceList $priceList = null): ?int
    {
        $priceList ??= $this->shop()->firstOrFail()->defaultPriceList();

        $price = $this->relationLoaded('prices')
            ? $this->prices->firstWhere('price_list_id', $priceList->getKey())
            : $this->prices()->where('price_list_id', $priceList->getKey())->first();

        return $price?->price;
    }

    public function stockAt(Location $location): float
    {
        return (float) ($this->stockLevels()
            ->where('location_id', $location->getKey())
            ->value('quantity') ?? 0);
    }
}
