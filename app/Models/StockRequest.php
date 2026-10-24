<?php

namespace App\Models;

use App\Enums\StockRequestStatus;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $request_number
 * @property StockRequestStatus $status
 * @property string|null $note
 * @property Carbon $requested_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $closed_at
 *
 * A location asking another for stock (multi_location, docs/PHARMACY.md §9,
 * branch 10): the OPD (to) asks General Pharmacy (from). Each fulfilment is
 * a posted transfer that names the request (transfers()). Changed only by
 * StockRequestService.
 */
class StockRequest extends Model
{
    use BelongsToShop, HasPublicUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => StockRequestStatus::class,
            'requested_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Stock requests cannot be deleted: cancel them.'));
    }

    /** @return HasMany<StockRequestItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StockRequestItem::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<StockTransfer, $this> */
    public function transfers(): HasMany
    {
        return $this->hasMany(StockTransfer::class)->orderBy('id');
    }

    /** @return BelongsTo<Location, $this> the location asked to send the stock */
    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    /** @return BelongsTo<Location, $this> the location asking */
    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }
}
