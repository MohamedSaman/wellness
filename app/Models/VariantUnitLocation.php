<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $variant_unit_id
 * @property int $location_id
 * @property bool $is_sellable
 *
 * Whether a unit sells at one location, overriding the unit's own
 * is_sellable there (docs/PHARMACY.md §4.2, client answers 2026-10-20):
 * syrup's loose ml sell at Doctor's Medicines but not at General Pharmacy.
 * Only the base unit's loose sale uses it so far. Written by the import and
 * the product form (ProductCatalogService, a variant's loose_at); checkout
 * reads it from branch 8 (ProductVariant::canSellUnitAt()).
 */
class VariantUnitLocation extends Model
{
    use BelongsToShop;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_sellable' => 'boolean'];
    }

    /** @return BelongsTo<VariantUnit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(VariantUnit::class, 'variant_unit_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
