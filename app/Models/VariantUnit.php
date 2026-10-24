<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A unit a variant is sold or bought in: its base unit (tablet, piece,
 * litre; factor 1) or a pack of base units (strip of 10, box of 30). Stock
 * is always held in the base unit. Each sellable unit has its own price;
 * nothing is derived from another unit's price. Units are deactivated,
 * never deleted, because sale lines point at them.
 */
class VariantUnit extends Model
{
    use BelongsToShop;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'factor' => 'decimal:3',
            'is_base' => 'boolean',
            'is_sellable' => 'boolean',
            'is_purchasable' => 'boolean',
            'is_default_sale' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    /** @return HasMany<VariantPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(VariantPrice::class);
    }

    /**
     * Per-location overrides of is_sellable (selling loose at one location
     * only, docs/PHARMACY.md §4.2).
     *
     * @return HasMany<VariantUnitLocation, $this>
     */
    public function locationRules(): HasMany
    {
        return $this->hasMany(VariantUnitLocation::class);
    }

    /**
     * Whether this unit sells at a location: the location's own rule, else
     * the unit's is_sellable. Uses locationRules when loaded.
     */
    public function sellableAt(Location|int $location): bool
    {
        $locationId = $location instanceof Location ? (int) $location->getKey() : $location;

        $rule = $this->relationLoaded('locationRules')
            ? $this->locationRules->firstWhere('location_id', $locationId)
            : $this->locationRules()->where('location_id', $locationId)->first();

        return $rule === null ? $this->is_sellable : $rule->is_sellable;
    }

    /**
     * A pack with no barcode or an in-store one: it needs a printed label to
     * scan (labels module). The base unit scans with the item's barcode.
     *
     * @param  Builder<VariantUnit>  $query
     */
    public function scopeWithoutManufacturerBarcode(Builder $query): void
    {
        $column = $this->qualifyColumn('barcode');

        $query->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, 'like', '2'.str_repeat('_', 12)));
    }

    /** Selling price of one of this unit in minor units, or null when it has none. */
    public function priceOn(PriceList $priceList): ?int
    {
        $price = $this->relationLoaded('prices')
            ? $this->prices->where('price_list_id', $priceList->getKey())->firstWhere('min_quantity', 1)
            : $this->prices()->where('price_list_id', $priceList->getKey())->where('min_quantity', 1)->first();

        return $price?->price;
    }

    /**
     * "Strip of 10"; a name that already says its size ("Box of 30") is
     * left as it is.
     */
    public static function describe(string $name, int|float|string $factor): string
    {
        $size = Quantity::format((string) $factor);

        return str_ends_with($name, " {$size}") ? $name : "{$name} of {$size}";
    }

    /** A quantity of this unit in base units. */
    public function toBase(float $quantity): float
    {
        return Quantity::normalize($quantity * (float) $this->factor);
    }
}
