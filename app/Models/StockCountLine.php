<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string|null $batch_no as on record, or as found on the shelf
 * @property Carbon|null $expiry_date
 * @property string $expected_quantity on hand when the line was put on the sheet, base units
 * @property string|null $counted_quantity base units; null = not counted
 * @property string|null $counted_unit_quantity as typed, in counted_unit_id's unit
 * @property string|null $counted_system_quantity what the system held for the line when it was counted (the snapshot the variance is measured from)
 * @property string|null $counted_unallocated_quantity batch-tracked items: their unallocated stock when the line was counted
 * @property Carbon|null $counted_at
 * @property string|null $system_quantity what the system held for the line at posting, before the variance
 * @property string|null $variance the variance written: counted - snapshot
 * @property string|null $unit_cost the location's average per base unit at posting, DECIMAL(20,4)
 * @property int|null $variance_value
 *
 * One line of a stock count: an item (no batches), or one batch of a
 * batch-tracked item, or a batch found on the shelf that is not on record.
 */
class StockCountLine extends Model
{
    use BelongsToShop;

    protected $guarded = ['id'];

    protected $hidden = ['unit_cost'];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'expected_quantity' => 'decimal:3',
            'counted_quantity' => 'decimal:3',
            'counted_unit_quantity' => 'decimal:3',
            'counted_system_quantity' => 'decimal:3',
            'counted_unallocated_quantity' => 'decimal:3',
            'counted_at' => 'datetime',
            'system_quantity' => 'decimal:3',
            'variance' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'variance_value' => 'integer',
        ];
    }

    /** @return BelongsTo<StockCount, $this> */
    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class, 'stock_count_id');
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

    /** @return BelongsTo<VariantUnit, $this> */
    public function countedUnit(): BelongsTo
    {
        return $this->belongsTo(VariantUnit::class, 'counted_unit_id');
    }

    /** @return BelongsTo<StockMovement, $this> */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    public function isCounted(): bool
    {
        return $this->counted_quantity !== null;
    }

    /** "B1234 · exp 03/2027", or null for an item without batches. */
    public function batchLabel(): ?string
    {
        if ($this->batch_no === null) {
            return null;
        }

        return $this->batch_no.($this->expiry_date === null ? '' : ' · exp '.$this->expiry_date->format('m/Y'));
    }
}
