<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $name
 * @property string $code
 * @property bool $is_default
 * @property bool $is_active
 * @property bool $is_sellable
 * @property int $sort_order
 *
 * A place stock is held: the shop floor, or with the multi_location module
 * several pools, stores or branches (General Pharmacy, Doctor's Medicines).
 * Each shop has exactly one default location (LocationService). Locations
 * are deactivated, never deleted: sales and the ledger point at them.
 */
class Location extends Model
{
    use BelongsToShop, HasPublicUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'is_sellable' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Report, picker and receipt order.
     *
     * @param  Builder<Location>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name')->orderBy('id');
    }

    /**
     * Active locations as id => name, in order, for pickers and filters.
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        return static::query()->where('is_active', true)->ordered()->pluck('name', 'id')->all();
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** @return HasMany<StockLevel, $this> */
    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }
}
