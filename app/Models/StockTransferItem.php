<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $quantity in the unit entered
 * @property string $base_quantity what stock moved
 * @property string $unit_cost the source location's average per base unit, DECIMAL(20,4)
 *
 * One line of a stock transfer, with the pair of ledger movements it wrote;
 * for a batch-tracked item, one pair per batch moved (batches()).
 */
class StockTransferItem extends Model
{
    use BelongsToShop;

    protected $guarded = ['id'];

    protected $hidden = ['unit_cost'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_factor' => 'decimal:3',
            'base_quantity' => 'decimal:3',
            'unit_cost' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<StockTransfer, $this> */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    /** @return HasMany<StockTransferItemBatch, $this> */
    public function batches(): HasMany
    {
        return $this->hasMany(StockTransferItemBatch::class);
    }

    /** @return BelongsTo<VariantUnit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(VariantUnit::class, 'variant_unit_id');
    }
}
