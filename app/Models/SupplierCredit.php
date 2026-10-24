<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $supplier_id
 * @property int|null $supplier_payment_id
 * @property int|null $supplier_return_id
 * @property int|null $supplier_opening_balance_id
 * @property Carbon $credit_on
 * @property int $amount minor units
 * @property int $allocated minor units allocated to invoices
 * @property bool $is_reversed
 *
 * Money the supplier has to set against invoices (purchasing): a payment,
 * a supplier return's credit, or a credit owed at go-live (a negative
 * opening balance). What is not yet allocated to an invoice is the
 * supplier's credit. Kept by SupplierPayablesService only; never deleted.
 */
class SupplierCredit extends Model
{
    use BelongsToShop, HasPublicUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'credit_on' => 'date',
            'amount' => 'integer',
            'allocated' => 'integer',
            'is_reversed' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (SupplierCredit $credit): void {
            if (array_diff(array_keys($credit->getDirty()), ['allocated', 'is_reversed', 'updated_at']) !== []) {
                throw new \LogicException('A supplier credit changes only by allocations.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Supplier credits are never deleted.'));
    }

    /** What is left to allocate. */
    public function unallocated(): int
    {
        return $this->is_reversed ? 0 : $this->amount - $this->allocated;
    }

    /** "Payment 17/10/2026 CHQ 4512", "Return SR-000002", "Opening credit". */
    public function label(): string
    {
        return match (true) {
            $this->supplier_payment_id !== null => trim('Payment '.$this->credit_on->format('d/m/Y').' '.($this->payment->reference ?? '')),
            $this->supplier_return_id !== null => 'Return '.($this->supplierReturn->return_number ?? ''),
            default => 'Opening credit '.$this->credit_on->format('d/m/Y'),
        };
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<SupplierPayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class, 'supplier_payment_id');
    }

    /** @return BelongsTo<SupplierReturn, $this> */
    public function supplierReturn(): BelongsTo
    {
        return $this->belongsTo(SupplierReturn::class);
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
