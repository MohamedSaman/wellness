<?php

namespace App\Models;

use App\Enums\StockCountStatus;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $count_number
 * @property StockCountStatus $status
 * @property bool $blind the sheet and the entry screen hide what the system holds
 * @property array{category_id?: int|null, category?: string|null, bin?: string|null}|null $scope
 * @property string|null $note
 * @property Carbon $started_at
 * @property Carbon|null $posted_at
 * @property Carbon|null $cancelled_at
 * @property int|null $variance_value net value of the posted variances
 *
 * A stock take at one location as a document (docs/PHARMACY.md §9,
 * branch 10): lines per item, or per batch for batch-tracked items, counted
 * in any unit, then posted as count corrections in one transaction against
 * what the system holds at posting time. Changed only by StockCountService.
 */
class StockCount extends Model
{
    use BelongsToShop, HasPublicUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => StockCountStatus::class,
            'blind' => 'boolean',
            'scope' => 'array',
            'started_at' => 'datetime',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'variance_value' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Stock counts cannot be deleted: cancel them.'));
    }

    /** @return HasMany<StockCountLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<User, $this> */
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /** @return BelongsTo<User, $this> */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isInProgress(): bool
    {
        return $this->status === StockCountStatus::InProgress;
    }

    /** What the sheet covers, in words: "Category: Tablets · Shelf A". */
    public function coverage(): string
    {
        $parts = array_filter([
            filled($this->scope['category'] ?? null) ? 'Category: '.$this->scope['category'] : null,
            filled($this->scope['bin'] ?? null) ? 'Shelf / bin: '.$this->scope['bin'].'…' : null,
        ]);

        return $parts === [] ? 'All items' : implode(' · ', $parts);
    }
}
