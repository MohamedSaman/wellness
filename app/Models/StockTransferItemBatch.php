<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $quantity base units
 * @property string $batch_no snapshot
 * @property Carbon|null $expiry_date snapshot
 *
 * One batch moved by a transfer line of a batch-tracked item, with the
 * pair of ledger movements it wrote.
 */
class StockTransferItemBatch extends Model
{
    use BelongsToShop;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'expiry_date' => 'date',
        ];
    }

    /** @return BelongsTo<StockTransferItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(StockTransferItem::class, 'stock_transfer_item_id');
    }

    /** "B1234 · exp 03/2027" as moved. */
    public function label(): string
    {
        return $this->batch_no.($this->expiry_date === null ? '' : ' · exp '.$this->expiry_date->format('m/Y'));
    }
}
