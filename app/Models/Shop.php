<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Laravel\Pennant\Feature;

/**
 * @property array<string, mixed>|null $settings
 *
 * A client shop (tenant). Each shop has its own settings and enabled modules.
 */
class Shop extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'tax_enabled' => 'boolean',
            'prices_include_tax' => 'boolean',
            'tax_rate' => 'decimal:2',
            'currency_decimals' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** Format minor units with this shop's currency: 125050 -> "LKR 1,250.50". */
    public function formatMoney(int $minor): string
    {
        return Money::format($minor, (int) $this->currency_decimals, (string) $this->currency);
    }

    /** Read a setting, falling back to config('pos.shop_defaults'). */
    public function setting(string $key, mixed $default = null): mixed
    {
        $settings = array_replace(config('pos.shop_defaults', []), $this->settings ?? []);

        return Arr::get($settings, $key, $default);
    }

    public function hasModule(string $module): bool
    {
        return Feature::for($this)->active("module:{$module}");
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Location, $this> */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function defaultLocation(): Location
    {
        return Location::withoutGlobalScope('shop')
            ->where('shop_id', $this->getKey())
            ->where('is_default', true)
            ->firstOrFail();
    }

    public function defaultPriceList(): PriceList
    {
        return PriceList::withoutGlobalScope('shop')
            ->where('shop_id', $this->getKey())
            ->where('is_default', true)
            ->firstOrFail();
    }
}
