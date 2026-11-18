<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $uuid
 * @property string $number NR-000001
 * @property int $shift_id
 * @property int $device_id
 * @property int $location_id the till's
 * @property int $user_id
 * @property string $reason
 * @property string|null $note
 * @property int $refund_total
 * @property PaymentMethod $refund_method always cash
 * @property int $exchange_credit the part of refund_total that paid a new bill in an exchange (branch 19)
 * @property Carbon $returned_at
 *
 * A refund for items brought back without their bill: bought before the
 * system went live, or the bill is lost (docs/PHARMACY.md, branch 18). The
 * cash comes out of the till's drawer in its open shift; the stock goes to
 * Quarantine or back to sellable stock. Never edited or deleted.
 */
class NoReceiptReturn extends Model
{
    use BelongsToShop, HasPublicUuid;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'refund_total' => 'integer',
            'exchange_credit' => 'integer',
            'refund_method' => PaymentMethod::class,
            'returned_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Returns are never deleted.'));
    }

    /** @return HasOne<Exchange, $this> */
    public function exchange(): HasOne
    {
        return $this->hasOne(Exchange::class);
    }

    /** @return HasMany<NoReceiptReturnItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(NoReceiptReturnItem::class);
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
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
