<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VariantPrice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['price' => 'integer'];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** @return BelongsTo<VariantUnit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(VariantUnit::class, 'variant_unit_id');
    }

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }
}
