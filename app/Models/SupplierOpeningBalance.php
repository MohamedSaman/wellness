<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $as_of
 * @property int $amount minor units; positive = owed to the supplier; negative on a reversal
 * @property string|null $invoice_no null = a lump sum
 * @property Carbon|null $invoice_date
 * @property Carbon|null $due_on
 * @property string|null $note
 * @property int|null $reversal_of_id
 *
 * What a supplier was owed when the shop started using the system (go-live):
 * a lump sum (at most one standing per supplier), or unpaid invoices one by
 * one with their number, date and due date. Each is an open item to pay
 * like a GRN (a negative lump sum is a credit). Part of the balance. Never
 * edited or deleted: a mistake is reversed (an entry with the amount
 * negated) and the right one entered. Entered through SupplierService
 * (purchasing.manage) and audited.
 */
class SupplierOpeningBalance extends Model
{
    use BelongsToShop, HasPublicUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'as_of' => 'date',
            'invoice_date' => 'date',
            'due_on' => 'date',
            'amount' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('An opening balance is never edited: reverse it and enter it again.'));
        static::deleting(fn () => throw new \LogicException('An opening balance is never deleted: reverse it instead.'));
    }

    public function isReversal(): bool
    {
        return $this->reversal_of_id !== null;
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * The entry this one reverses.
     *
     * @return BelongsTo<SupplierOpeningBalance, $this>
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /**
     * The entry that reversed this one, if any.
     *
     * @return HasOne<SupplierOpeningBalance, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    /** @return HasOne<SupplierPayable, $this> */
    public function payable(): HasOne
    {
        return $this->hasOne(SupplierPayable::class);
    }

    /** @return HasOne<SupplierCredit, $this> */
    public function credit(): HasOne
    {
        return $this->hasOne(SupplierCredit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
