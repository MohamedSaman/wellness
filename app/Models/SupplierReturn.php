<?php

namespace App\Models;

use App\Enums\SupplierReturnReason;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $return_number
 * @property SupplierReturnReason $reason
 * @property string|null $credit_note_no
 * @property int|null $goods_receipt_id the GRN the goods came from: the credit goes against it
 * @property int $total credit expected, at batch cost
 * @property Carbon $returned_on
 * @property string|null $notes
 *
 * Stock sent back to a supplier from named batches at one location
 * (purchasing module). Posted when saved (SupplierReturnService); afterwards
 * only the supplier's credit note number can be added.
 */
class SupplierReturn extends Model
{
    use BelongsToShop, HasPublicUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reason' => SupplierReturnReason::class,
            'total' => 'integer',
            'returned_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (SupplierReturn $return): void {
            if (array_diff(array_keys($return->getDirty()), ['credit_note_no', 'updated_at']) !== []) {
                throw new \LogicException('A posted supplier return never changes, except for the supplier\'s credit note number.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Supplier returns cannot be deleted.'));
    }

    /** @return HasMany<SupplierReturnItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SupplierReturnItem::class);
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

    /** @return HasOne<SupplierCredit, $this> */
    public function credit(): HasOne
    {
        return $this->hasOne(SupplierCredit::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
