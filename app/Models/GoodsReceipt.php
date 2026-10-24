<?php

namespace App\Models;

use App\Enums\GoodsReceiptStatus;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string|null $grn_number given when posted
 * @property string|null $supplier_invoice_no
 * @property Carbon|null $supplier_invoice_date
 * @property Carbon $received_on
 * @property GoodsReceiptStatus $status
 * @property int $subtotal
 * @property int $line_discount_total
 * @property int $invoice_discount
 * @property int $line_tax_total tax entered on the lines
 * @property int $invoice_tax tax entered for the whole invoice, spread over the lines
 * @property int $total what is owed for it; negative on a reversal
 * @property int|null $reversal_of_id
 * @property string|null $notes
 * @property Carbon|null $posted_at
 *
 * Goods received from a supplier (purchasing module), with or without a
 * purchase order. A draft is edited freely; posting it (GoodsReceiptService)
 * brings the stock in and numbers it. A posted GRN never changes: a mistake
 * is put right by a reversing GRN (reversal()), whose lines take the same
 * quantities back out of the same batches.
 */
class GoodsReceipt extends Model
{
    use BelongsToShop, HasPublicUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'supplier_invoice_date' => 'date',
            'received_on' => 'date',
            'status' => GoodsReceiptStatus::class,
            'subtotal' => 'integer',
            'line_discount_total' => 'integer',
            'invoice_discount' => 'integer',
            'line_tax_total' => 'integer',
            'invoice_tax' => 'integer',
            'total' => 'integer',
            'posted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        $posted = fn (GoodsReceipt $receipt): bool => $receipt->getOriginal('status') === GoodsReceiptStatus::Posted;

        static::updating(fn (GoodsReceipt $receipt) => $posted($receipt)
            ? throw new \LogicException('A posted goods received note never changes: reverse it instead.')
            : null);
        static::deleting(fn (GoodsReceipt $receipt) => $posted($receipt)
            ? throw new \LogicException('A posted goods received note cannot be deleted: reverse it instead.')
            : null);
    }

    public function isPosted(): bool
    {
        return $this->status === GoodsReceiptStatus::Posted;
    }

    public function isReversal(): bool
    {
        return $this->reversal_of_id !== null;
    }

    /** "GRN-000012", or "Draft" before it is posted. */
    public function label(): string
    {
        return $this->grn_number ?? 'Draft';
    }

    /** @return HasMany<GoodsReceiptItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * The GRN this one reverses.
     *
     * @return BelongsTo<GoodsReceipt, $this>
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /**
     * The GRN that reversed this one, if any.
     *
     * @return HasOne<GoodsReceipt, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    /**
     * What is owed for it (posted, not a reversal), with its due date and payment status.
     *
     * @return HasOne<SupplierPayable, $this>
     */
    public function payable(): HasOne
    {
        return $this->hasOne(SupplierPayable::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
