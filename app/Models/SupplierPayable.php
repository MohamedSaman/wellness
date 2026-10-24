<?php

namespace App\Models;

use App\Enums\PayableStatus;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasPublicUuid;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $supplier_id
 * @property int|null $goods_receipt_id
 * @property int|null $supplier_opening_balance_id
 * @property string|null $invoice_no
 * @property Carbon|null $invoice_date
 * @property Carbon $document_date GRN received on, or the opening entry's as-of date
 * @property Carbon $due_on
 * @property int $amount minor units
 * @property int $allocated minor units paid or credited against it
 * @property PayableStatus $status
 *
 * An invoice owed to a supplier (purchasing): a posted GRN, or an opening
 * balance entry owed at go-live. Created and settled only through
 * SupplierPayablesService: payments and credits are allocated to it
 * (supplier_allocations), which keeps `allocated` and `status`. Only the
 * due date is ever changed by hand (purchasing.manage, audited). Never
 * deleted; reversing its GRN or opening entry marks it reversed.
 */
class SupplierPayable extends Model
{
    use BelongsToShop, HasPublicUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'document_date' => 'date',
            'due_on' => 'date',
            'amount' => 'integer',
            'allocated' => 'integer',
            'status' => PayableStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (SupplierPayable $payable): void {
            if (array_diff(array_keys($payable->getDirty()), ['allocated', 'status', 'due_on', 'updated_at']) !== []) {
                throw new \LogicException('An invoice owed to a supplier changes only by allocations and its due date.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Invoices owed to suppliers are never deleted.'));
    }

    /** What is still owed on it. */
    public function outstanding(): int
    {
        return $this->status === PayableStatus::Reversed ? 0 : $this->amount - $this->allocated;
    }

    /** Days past its due date on $today (0 when not yet due). */
    public function daysOverdue(CarbonInterface $today): int
    {
        return max(0, (int) $this->due_on->copy()->startOfDay()->diffInDays($today->copy()->startOfDay(), false));
    }

    /** "GRN-000012", or "Opening balance" / "Opening invoice". */
    public function documentLabel(): string
    {
        if ($this->goods_receipt_id !== null) {
            return (string) ($this->goodsReceipt->grn_number ?? 'GRN');
        }

        return $this->invoice_no === null ? 'Opening balance' : 'Opening invoice';
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<GoodsReceipt, $this> */
    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    /** @return BelongsTo<SupplierOpeningBalance, $this> */
    public function openingBalance(): BelongsTo
    {
        return $this->belongsTo(SupplierOpeningBalance::class, 'supplier_opening_balance_id');
    }

    /** @return HasMany<SupplierAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierAllocation::class);
    }
}
