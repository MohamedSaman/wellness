<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $unit_name snapshot
 * @property string $unit_factor snapshot
 * @property string $quantity in the unit asked for
 * @property string $base_quantity what is asked for, base units
 * @property string $sent_base_quantity sent so far, base units
 *
 * One item of a stock request. Only the quantity sent changes, as transfers
 * fulfil it (StockRequestService).
 */
class StockRequestItem extends Model
{
    use BelongsToShop;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'unit_factor' => 'decimal:3',
            'quantity' => 'decimal:3',
            'base_quantity' => 'decimal:3',
            'sent_base_quantity' => 'decimal:3',
        ];
    }

    /** @return BelongsTo<StockRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(StockRequest::class, 'stock_request_id');
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

    /** Still to send, base units. */
    public function outstandingBase(): float
    {
        return max(0.0, Quantity::normalize((float) $this->base_quantity - (float) $this->sent_base_quantity));
    }
}
