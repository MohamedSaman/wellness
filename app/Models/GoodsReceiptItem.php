<?php

namespace App\Models;

use App\Enums\GoodsReceiptStatus;
use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $unit_name
 * @property string $unit_factor
 * @property string $quantity paid, in the unit received
 * @property string $free_quantity bonus goods, same unit
 * @property int $unit_cost minor per unit received, before discount
 * @property int $gross quantity x unit cost
 * @property int $discount line discount
 * @property int $invoice_discount_share
 * @property int $tax tax (VAT) entered on the line; part of the cost
 * @property int $invoice_tax_share the line's share of the invoice tax
 * @property int $line_total gross - discount - share + tax + tax share
 * @property string $base_quantity (quantity + free) x factor
 * @property string|null $base_unit_cost line_total / base_quantity, DECIMAL(20,4) (Q9)
 * @property string|null $batch_no
 * @property Carbon|null $expiry_date
 * @property bool $accept_short_expiry
 * @property int|null $stock_batch_id
 * @property int|null $stock_movement_id
 *
 * One line of a goods received note, in any unit of the item. A reversing
 * GRN's lines are the original's with every quantity and amount negated.
 * Changed only while its GRN is a draft.
 */
class GoodsReceiptItem extends Model
{
    use BelongsToShop;

    protected $guarded = ['id'];

    protected $hidden = ['base_unit_cost'];

    protected function casts(): array
    {
        return [
            'unit_factor' => 'decimal:3',
            'quantity' => 'decimal:3',
            'free_quantity' => 'decimal:3',
            'unit_cost' => 'integer',
            'gross' => 'integer',
            'discount' => 'integer',
            'invoice_discount_share' => 'integer',
            'tax' => 'integer',
            'invoice_tax_share' => 'integer',
            'line_total' => 'integer',
            'base_quantity' => 'decimal:3',
            'base_unit_cost' => 'decimal:4',
            'expiry_date' => 'date',
            'accept_short_expiry' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        $posted = fn (GoodsReceiptItem $item): bool => GoodsReceipt::query()->whereKey($item->goods_receipt_id)->toBase()->value('status') === GoodsReceiptStatus::Posted->value;

        static::updating(fn (GoodsReceiptItem $item) => $posted($item)
            ? throw new \LogicException('The lines of a posted goods received note never change.')
            : null);
        static::deleting(fn (GoodsReceiptItem $item) => $posted($item)
            ? throw new \LogicException('The lines of a posted goods received note cannot be deleted.')
            : null);
    }

    /** @return BelongsTo<GoodsReceipt, $this> */
    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
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

    /** @return BelongsTo<PurchaseOrderItem, $this> */
    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
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

    /** "B1234 · exp 03/2027" as received, or null for an item without batches. */
    public function batchLabel(): ?string
    {
        if (blank($this->batch_no)) {
            return null;
        }

        return $this->batch_no.($this->expiry_date === null ? '' : ' · exp '.$this->expiry_date->format('m/Y'));
    }
}
