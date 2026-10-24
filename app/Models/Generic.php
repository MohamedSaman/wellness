<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A generic (active ingredient) name, such as Paracetamol. Products are
 * linked to one, or several for a combination drug, through product_generics.
 */
class Generic extends Model
{
    use BelongsToShop;

    protected $guarded = ['id'];

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_generics');
    }
}
