<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $product_variant_id
 * @property int|null $variant_unit_id
 * @property string $description
 * @property string $sku
 * @property string|null $unit_name
 * @property string $unit_factor
 * @property string $quantity in the unit returned
 * @property string $base_quantity
 * @property int $list_price the unit's price at the time
 * @property int $unit_value refunded per unit
 * @property int $refund_amount
 * @property int $location_id where the stock went (Quarantine or the till's location)
 * @property int|null $stock_batch_id
 * @property string|null $batch_no null = batch unknown (Quarantine only)
 * @property Carbon|null $expiry_date
 * @property int $unit_cost the location's average per base unit then
 *
 * One item of a return without a receipt. Reached only through its return.
 */
class NoReceiptReturnItem extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'unit_factor' => 'decimal:3',
            'quantity' => 'decimal:3',
            'base_quantity' => 'decimal:3',
            'list_price' => 'integer',
            'unit_value' => 'integer',
            'refund_amount' => 'integer',
            'unit_cost' => 'integer',
            'expiry_date' => 'date',
        ];
    }

    /** @return BelongsTo<NoReceiptReturn, $this> */
    public function noReceiptReturn(): BelongsTo
    {
        return $this->belongsTo(NoReceiptReturn::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
