<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $transfer_number
 * @property string|null $note
 * @property Carbon $transferred_at
 *
 * Stock moved from one location to another (multi_location). Posted when it
 * is saved (StockTransferService) and never edited afterwards: a mistake is
 * put right with a transfer back.
 */
class StockTransfer extends Model
{
    use BelongsToShop, HasPublicUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['transferred_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Stock transfers are immutable.'));
        static::deleting(fn () => throw new \LogicException('Stock transfers cannot be deleted.'));
    }

    /** @return HasMany<StockTransferItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    /** @return BelongsTo<StockRequest, $this> the stock request this transfer fulfilled, if any */
    public function stockRequest(): BelongsTo
    {
        return $this->belongsTo(StockRequest::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
