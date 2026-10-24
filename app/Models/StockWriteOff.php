<?php

namespace App\Models;

use App\Enums\AdjustmentReason;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $write_off_number
 * @property AdjustmentReason $reason expired, damaged or other
 * @property string|null $note
 * @property int $total value at what the batches cost, minor units
 * @property Carbon $written_off_at
 *
 * Batches written off at one location (expiry write-off, docs/PHARMACY.md
 * §9, branch 10): each line left its batch as an adjustment_out with this
 * reason, at the batch's purchase cost. Posted when saved
 * (StockWriteOffService) and never changed.
 */
class StockWriteOff extends Model
{
    use BelongsToShop, HasPublicUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'reason' => AdjustmentReason::class,
            'total' => 'integer',
            'written_off_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Write-offs never change.'));
        static::deleting(fn () => throw new \LogicException('Write-offs cannot be deleted.'));
    }

    /** @return HasMany<StockWriteOffItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StockWriteOffItem::class);
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
