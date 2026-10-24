<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $sale_item_id
 * @property string $quantity in the line's sold unit
 * @property string $base_quantity
 * @property int $refund_amount
 * @property bool $restocked
 * @property list<array{sale_item_batch_id: int, stock_batch_id: int, batch_no: string, expiry_date: string|null, quantity: float}>|null $batches
 *
 * One line of a return: what came back of a sale line and what it refunded.
 */
class SaleReturnItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',      // in the line's sold unit
            'base_quantity' => 'decimal:3', // what went back to stock (when restocked)
            'refund_amount' => 'integer',
            'restocked' => 'boolean',
            // Batch-tracked lines: [{sale_item_batch_id, stock_batch_id, batch_no, expiry_date, quantity}], as returned
            'batches' => 'array',
        ];
    }

    /** @return BelongsTo<SaleItem, $this> */
    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }
}
