<?php

namespace App\Models;

use App\Enums\SupplierPaymentMethod;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $paid_on
 * @property int $amount minor units; negative on a reversal
 * @property SupplierPaymentMethod $method
 * @property string|null $reference
 * @property string|null $notes
 * @property int|null $reversal_of_id
 *
 * A payment made to a supplier: it lowers the supplier's balance and is
 * allocated to the invoices it pays (credit(); no accounting integration).
 * Never edited or deleted; a wrong one is reversed through SupplierService
 * (a payment with the amount negated, its allocations undone).
 */
class SupplierPayment extends Model
{
    use BelongsToShop, HasPublicUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'paid_on' => 'date',
            'amount' => 'integer',
            'method' => SupplierPaymentMethod::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Supplier payments are never edited: reverse a wrong one and record it again.'));
        static::deleting(fn () => throw new \LogicException('Supplier payments are never deleted: reverse a wrong one instead.'));
    }

    public function isReversal(): bool
    {
        return $this->reversal_of_id !== null;
    }

    /**
     * The payment this one reverses.
     *
     * @return BelongsTo<SupplierPayment, $this>
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /**
     * The entry that reversed this payment, if any.
     *
     * @return HasOne<SupplierPayment, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    /**
     * The payment as a credit, with what of it is allocated to invoices.
     *
     * @return HasOne<SupplierCredit, $this>
     */
    public function credit(): HasOne
    {
        return $this->hasOne(SupplierCredit::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
