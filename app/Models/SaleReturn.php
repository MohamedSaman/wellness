<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $sale_id
 * @property string $return_no
 * @property int $refund_total
 * @property PaymentMethod $refund_method
 * @property ReturnReason $reason_code
 * @property Carbon $returned_at
 * @property string|null $note
 * @property string|null $refund_reference the bank transfer or card reference of the refund
 * @property int $exchange_credit the part of refund_total that paid a new bill in an exchange (branch 19); the rest left the drawer
 * @property int|null $shift_id
 * @property int|null $device_id
 *
 * A return of lines of a sale, and its refund.
 */
class SaleReturn extends Model
{
    use BelongsToShop, HasPublicUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'refund_total' => 'integer',
            'exchange_credit' => 'integer',
            'refund_method' => PaymentMethod::class,
            'reason_code' => ReturnReason::class,
            'returned_at' => 'datetime',
        ];
    }

    /**
     * The till whose drawer paid it (branch 18); null on returns before it.
     *
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * The exchange at the till it belongs to (branch 19), if any.
     *
     * @return HasOne<Exchange, $this>
     */
    public function exchange(): HasOne
    {
        return $this->hasOne(Exchange::class);
    }

    /** Refunded in cash from a drawer: refund_total less the credit that paid a new bill. */
    public function cashRefund(): int
    {
        return $this->refund_method === PaymentMethod::Cash ? $this->refund_total - $this->exchange_credit : 0;
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<SaleReturnItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }
}
