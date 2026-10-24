<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $user_id
 * @property int $product_variant_id
 * @property int|null $variant_unit_id null = the base unit (the variant's own barcode)
 * @property int|null $stock_batch_id
 * @property string|null $batch_no
 * @property Carbon|null $expiry_date
 * @property int $quantity copies
 * @property string $source product | delivery | batches | products
 * @property string|null $source_label
 *
 * A label waiting in a user's print queue (docs/PHARMACY.md §9, branch 7b):
 * collected from a product, a delivery, batches or the product list, and
 * printed with the rest in one go (LabelService::printQueue()).
 */
class LabelQueueItem extends Model
{
    use BelongsToShop;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'quantity' => 'integer',
        ];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    /** @return BelongsTo<VariantUnit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(VariantUnit::class, 'variant_unit_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
