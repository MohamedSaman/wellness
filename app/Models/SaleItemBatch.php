<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $quantity base units taken from the batch
 * @property string $returned_quantity base units since returned (restocked or not)
 * @property string $batch_no snapshot
 * @property Carbon|null $expiry_date snapshot
 * @property bool $is_unallocated snapshot: sold beyond the recorded stock (§6.4)
 *
 * Which batch a sale line took, and how much (docs/PHARMACY.md §4.5). One
 * row per batch, each with its own sale movement; the authoritative record
 * that returns and voids reverse. The batch number and expiry are copied as
 * sold, so a receipt or reprint never depends on later corrections.
 */
class SaleItemBatch extends Model
{
    use BelongsToShop;

    protected $guarded = ['id'];

    protected $hidden = ['batch_unit_cost'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'returned_quantity' => 'decimal:3',
            'expiry_date' => 'date',
            'is_unallocated' => 'boolean',
            'batch_unit_cost' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<SaleItem, $this> */
    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    /** @return BelongsTo<StockBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'stock_batch_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** What can still come back from this batch, in base units. */
    public function remaining(): float
    {
        return max(0.0, round((float) $this->quantity - (float) $this->returned_quantity, 3));
    }

    /** "B1234 · exp 03/2027" as sold. */
    public function label(): string
    {
        if ($this->is_unallocated) {
            return 'Batch to be confirmed';
        }

        return $this->batch_no.($this->expiry_date === null ? '' : ' · exp '.$this->expiry_date->format('m/Y'));
    }
}
