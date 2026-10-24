<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $unit_name
 * @property string $unit_factor
 * @property string $quantity in the unit ordered
 * @property string $base_quantity
 * @property int $unit_cost expected, minor per unit ordered
 * @property int $line_total
 * @property string $received_base_quantity paid quantity received so far, base units
 *
 * One line of a purchase order, in any unit of the item (a box, a strip).
 */
class PurchaseOrderItem extends Model
{
    use BelongsToShop;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'unit_factor' => 'decimal:3',
            'quantity' => 'decimal:3',
            'base_quantity' => 'decimal:3',
            'unit_cost' => 'integer',
            'line_total' => 'integer',
            'received_base_quantity' => 'decimal:3',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
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

    /** Still to come, in base units (never below zero: more may arrive than was ordered). */
    public function outstandingBase(): float
    {
        return max(0.0, Quantity::normalize((float) $this->base_quantity - (float) $this->received_base_quantity));
    }

    /** Still to come, in the unit ordered (whole or part units, as it works out). */
    public function outstanding(): float
    {
        return Quantity::normalize($this->outstandingBase() / max((float) $this->unit_factor, 0.001));
    }
}
