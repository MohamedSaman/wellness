<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string|null $batch_no snapshot
 * @property Carbon|null $expiry_date snapshot
 * @property string $unit_name
 * @property string $unit_factor
 * @property string $quantity in the unit entered
 * @property string $base_quantity
 * @property string $unit_cost per base unit: the batch's purchase cost, DECIMAL(20,4)
 * @property int $line_total
 *
 * One line of a supplier return: what left which batch, and the credit
 * expected for it. Never changed.
 */
class SupplierReturnItem extends Model
{
    use BelongsToShop;

    protected $guarded = ['id'];

    protected $hidden = ['unit_cost'];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'unit_factor' => 'decimal:3',
            'quantity' => 'decimal:3',
            'base_quantity' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'line_total' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Supplier return lines never change.'));
        static::deleting(fn () => throw new \LogicException('Supplier return lines cannot be deleted.'));
    }

    /** @return BelongsTo<SupplierReturn, $this> */
    public function supplierReturn(): BelongsTo
    {
        return $this->belongsTo(SupplierReturn::class);
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

    /** "B1234 · exp 03/2027" as returned, or null for an item without batches. */
    public function batchLabel(): ?string
    {
        if (blank($this->batch_no)) {
            return null;
        }

        return $this->batch_no.($this->expiry_date === null ? '' : ' · exp '.$this->expiry_date->format('m/Y'));
    }
}
