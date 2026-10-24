<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * "Retail" now. "Wholesale", "Trade", "Member" later (wholesale module),
 * without changing how prices are stored.
 */
class PriceList extends Model
{
    use BelongsToShop;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    /** @return HasMany<VariantPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(VariantPrice::class);
    }
}
