<?php

namespace App\Models;

use App\Enums\CashMovementCategory;
use App\Enums\CashMovementType;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $uuid
 * @property string $number CM-000001
 * @property int $shift_id
 * @property int $device_id
 * @property int $location_id
 * @property int $user_id
 * @property CashMovementType $type
 * @property CashMovementCategory|null $category
 * @property int $amount minor units, above zero
 * @property int|null $doctor_id
 * @property string|null $note
 * @property Carbon|null $voided_at
 * @property int|null $voided_by
 * @property string|null $void_reason
 * @property Carbon $created_at
 *
 * Cash paid out of or into a till's drawer in its open shift, or paid to a
 * doctor (docs/PHARMACY.md, branch 18). Made by CashMovementService; voided
 * (while the shift is open), never edited or deleted.
 */
class CashMovement extends Model
{
    use BelongsToShop, HasPublicUuid;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => CashMovementType::class,
            'category' => CashMovementCategory::class,
            'amount' => 'integer',
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Cash movements are never deleted: void them instead.'));
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /**
     * Movements that count: not voided.
     *
     * @param  Builder<CashMovement>  $query
     */
    public function scopeCounted(Builder $query): void
    {
        $query->whereNull('cash_movements.voided_at');
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

    /** @return BelongsTo<Doctor, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /** @return BelongsTo<User, $this> */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
