<?php

namespace App\Models;

use App\Enums\BatchStatus;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\HasPublicUuid;
use App\Support\Quantity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property string $batch_no as printed, upper case; '' for the unallocated batch
 * @property Carbon|null $expiry_date the last day it may be used; null only for the unallocated batch
 * @property string $unit_cost purchase cost per base unit, DECIMAL(20,4) minor units (Q9)
 * @property BatchStatus $status
 * @property bool $is_unallocated
 * @property string $quantity base units at this location: a projection of the ledger
 * @property Carbon $received_at
 *
 * One manufacturer batch of an item at one location (docs/PHARMACY.md §4.5).
 * The same batch at two locations is two rows with the same number and
 * expiry; a transfer moves quantity between them. The quantity is the sum
 * of the stock movements that name the batch, kept by StockService in the
 * same transaction (and checked by pos:stock-rebuild); nothing else writes it.
 *
 * Each item has at most one unallocated batch per location: it takes what
 * an offline till (or a shop that allows negative stock) sold beyond the
 * recorded batches, may go below zero, is never offered by FEFO and never
 * appears in expiry lists. The next receipt settles it (§6.4).
 */
class StockBatch extends Model
{
    use BelongsToShop, HasPublicUuid;

    protected $guarded = ['id'];

    /** Costs are sensitive: hidden from JSON unless explicitly shown. */
    protected $hidden = ['unit_cost'];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'unit_cost' => 'decimal:4',
            'status' => BatchStatus::class,
            'is_unallocated' => 'boolean',
            'quantity' => 'decimal:3',
            'received_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return HasMany<StockMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * What first brought the batch in: its first movement, a transfer, later a goods receipt.
     *
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** Past its expiry date on $today (a batch is usable up to and including its expiry day). */
    public function isExpiredOn(CarbonInterface $today): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->toDateString() < $today->toDateString();
    }

    /** "B1234 · exp 03/2027", or "Unallocated" for the oversold-stock batch. */
    public function label(): string
    {
        if ($this->is_unallocated) {
            return 'Unallocated';
        }

        return $this->batch_no.($this->expiry_date === null ? '' : ' · exp '.$this->expiry_date->format('m/Y'));
    }

    public function onHand(): float
    {
        return Quantity::normalize((float) $this->quantity);
    }

    /**
     * Real batches only: never the unallocated one. Every expiry list starts here.
     *
     * @param  Builder<StockBatch>  $query
     */
    public function scopeAllocated(Builder $query): void
    {
        $query->where($query->qualifyColumn('is_unallocated'), false);
    }

    /**
     * Real batches holding stock: what first expiry first out looks at
     * (StockService's allocation, before it checks each batch is sellable).
     *
     * @param  Builder<StockBatch>  $query
     */
    public function scopeInStock(Builder $query): void
    {
        $query->allocated()->where($query->qualifyColumn('quantity'), '>', 0);
    }

    /**
     * The first day a batch may expire on and still be sold on $day: $day plus
     * the minimum days to expiry (BatchService::minDaysToExpiry()), as Y-m-d.
     */
    public static function sellableCutoff(CarbonInterface $day, int $minDays = 0): string
    {
        return Carbon::parse($day->format('Y-m-d'))->addDays(max(0, $minDays))->format('Y-m-d');
    }

    /**
     * The batches FEFO may sell from on $day (the shop's calendar day of the
     * sale), in the order it takes them: real batches holding stock, active,
     * and expiring on or after $day plus $minDays (a batch without an expiry
     * always qualifies). The same rule as isSellableFrom(); StockService
     * allocates with it, and the till's preview and search read it.
     *
     * @param  Builder<StockBatch>  $query
     */
    public function scopeSellableOn(Builder $query, CarbonInterface $day, int $minDays = 0): void
    {
        $cutoff = self::sellableCutoff($day, $minDays);

        $query->inStock()
            ->where($query->qualifyColumn('status'), BatchStatus::Active->value)
            ->where(fn (Builder $query) => $query
                ->whereNull($query->qualifyColumn('expiry_date'))
                ->orWhereDate($query->qualifyColumn('expiry_date'), '>=', $cutoff))
            ->fefo();
    }

    /**
     * Whether FEFO may sell this batch when the earliest sellable expiry is
     * $cutoff (sellableCutoff()): active, and no expiry or one on or after it.
     * The in-memory twin of scopeSellableOn() (quantity aside).
     */
    public function isSellableFrom(string $cutoff): bool
    {
        return $this->status === BatchStatus::Active
            && ($this->expiry_date === null || $this->expiry_date->toDateString() >= $cutoff);
    }

    /**
     * Batches with stock expiring on or before $date (already expired ones
     * included), the unallocated batch excluded.
     *
     * @param  Builder<StockBatch>  $query
     */
    public function scopeExpiringBy(Builder $query, CarbonInterface $date): void
    {
        $query->allocated()
            ->where($query->qualifyColumn('quantity'), '>', 0)
            ->whereNotNull($query->qualifyColumn('expiry_date'))
            ->whereDate($query->qualifyColumn('expiry_date'), '<=', $date->toDateString());
    }

    /**
     * First expiry, first out: soonest expiry first, then the batch received
     * first, then the oldest row. Batches that do not expire come last.
     *
     * @param  Builder<StockBatch>  $query
     */
    public function scopeFefo(Builder $query): void
    {
        $query->orderByRaw('stock_batches.expiry_date IS NULL')
            ->orderBy($query->qualifyColumn('expiry_date'))
            ->orderBy($query->qualifyColumn('received_at'))
            ->orderBy($query->qualifyColumn('id'));
    }
}
