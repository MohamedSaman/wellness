<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $batch_no snapshot
 * @property Carbon|null $expiry_date snapshot
 * @property string $quantity base units
 * @property string $unit_cost the batch's purchase cost per base unit, DECIMAL(20,4)
 * @property int $line_total
 *
 * One batch written off: how much, at what cost, and its movement. Never changed.
 */
class StockWriteOffItem extends Model
{
    use BelongsToShop;

    protected $guarded = ['id'];

    protected $hidden = ['unit_cost'];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'line_total' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Write-off lines never change.'));
        static::deleting(fn () => throw new \LogicException('Write-off lines cannot be deleted.'));
    }

    /** @return BelongsTo<StockWriteOff, $this> */
    public function writeOff(): BelongsTo
    {
        return $this->belongsTo(StockWriteOff::class, 'stock_write_off_id');
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    /** @return BelongsTo<StockBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }

    /** @return BelongsTo<StockMovement, $this> */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }
}
