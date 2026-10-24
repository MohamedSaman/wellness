<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One generic of a product, in label order (sort_order). Written through
 * Product::productGenerics(); read through Product::generics().
 */
class ProductGeneric extends Model
{
    use BelongsToShop;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Generic, $this> */
    public function generic(): BelongsTo
    {
        return $this->belongsTo(Generic::class);
    }
}
