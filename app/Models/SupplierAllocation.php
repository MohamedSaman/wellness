<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $supplier_id
 * @property int $supplier_credit_id
 * @property int $supplier_payable_id
 * @property int $amount minor units; negative on a reversal
 * @property Carbon $allocated_on
 * @property int|null $reversal_of_id
 * @property string|null $note
 * @property int|null $user_id
 *
 * Part of a credit (a payment, a return, an opening credit) set against an
 * invoice owed to the same supplier. Insert-only and audited: a wrong one,
 * or one whose payment or GRN was reversed, is undone by a row with the
 * amount negated (reversal_of_id). Written by SupplierPayablesService only.
 */
class SupplierAllocation extends Model
{
    use BelongsToShop;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'allocated_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Allocations are never edited: undo a wrong one and allocate again.'));
        static::deleting(fn () => throw new \LogicException('Allocations are never deleted: undo a wrong one instead.'));
    }

    public function isReversal(): bool
    {
        return $this->reversal_of_id !== null;
    }

    /** @return BelongsTo<SupplierCredit, $this> */
    public function credit(): BelongsTo
    {
        return $this->belongsTo(SupplierCredit::class, 'supplier_credit_id');
    }

    /** @return BelongsTo<SupplierPayable, $this> */
    public function payable(): BelongsTo
    {
        return $this->belongsTo(SupplierPayable::class, 'supplier_payable_id');
    }

    /** @return BelongsTo<SupplierAllocation, $this> */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /** @return HasOne<SupplierAllocation, $this> */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
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
