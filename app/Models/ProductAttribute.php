<?php

namespace App\Models;

use App\Enums\AttributeType;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A configurable attribute (Size, Colour, Helmet Type, Material ...).
 * Shops define their own, so a clothing or hardware shop needs no code change.
 */
/**
 * @property AttributeType $type
 * @property Pivot|null $pivot Set when loaded through a category
 */
class ProductAttribute extends Model
{
    use BelongsToShop;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['type' => AttributeType::class];
    }

    /**
     * The categories that use this attribute (inverse of Category::productAttributes;
     * Filament's Attach action needs it to list only attributes not yet attached).
     *
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_attribute')
            ->withPivot(['is_variant_axis', 'is_required', 'sort_order']);
    }

    /** @return HasMany<AttributeOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(AttributeOption::class)->orderBy('sort_order');
    }
}
