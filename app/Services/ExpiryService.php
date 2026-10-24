<?php

namespace App\Services;

use App\Models\Location;
use App\Models\StockBatch;
use App\Support\CurrentShop;
use App\Support\UnitCost;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Expiry alerts (docs/PHARMACY.md §9, branch 10). Two lists of batches
 * holding stock, per location:
 *
 *   - expired: the expiry date is before today (a batch is usable up to and
 *     including its expiry day, as FEFO sells it);
 *   - expiring soon: from today up to today + near_expiry_warning_days
 *     (the shop setting; 90 days for the clinic), both days included.
 *
 * Days are the shop's calendar days (BatchService::today()). Each batch is
 * valued at its own purchase cost (stock_batches.unit_cost x on hand,
 * rounded to minor units), what a write-off of it would cost. The
 * unallocated batch (stock sold beyond the recorded batches) is never in
 * either list (decided, §6.4). Read only.
 */
class ExpiryService
{
    public const EXPIRED = 'expired';

    public const SOON = 'soon';

    public function __construct(
        private readonly BatchService $batches,
        private readonly CurrentShop $currentShop,
    ) {}

    public function today(): CarbonImmutable
    {
        return $this->batches->today();
    }

    /** The shop's short-expiry window in days (near_expiry_warning_days). */
    public function warningDays(): int
    {
        return $this->batches->nearExpiryWarningDays();
    }

    /** The last expiry date that counts as "expiring soon" today: within the shop's window, or $days (the expiry report). */
    public function soonUntil(?int $days = null): CarbonImmutable
    {
        return $this->today()->addDays($days ?? $this->warningDays());
    }

    /**
     * Batches with stock on one list, soonest expiry first.
     *
     * @return Builder<StockBatch>
     */
    public function query(string $list, ?int $locationId = null, ?int $days = null): Builder
    {
        return $this->onList(StockBatch::query(), $list, $days)
            ->when($locationId !== null, fn (Builder $query) => $query->where('stock_batches.location_id', $locationId))
            ->fefo();
    }

    /**
     * Narrow a batch query to one list: real batches holding stock, expired
     * or expiring soon, within the shop's window or $days (the Expiry
     * screen's tabs use it too).
     *
     * @param  Builder<StockBatch>  $query
     * @return Builder<StockBatch>
     */
    public function onList(Builder $query, string $list, ?int $days = null): Builder
    {
        $today = $this->today()->toDateString();

        $query->allocated()
            ->where('stock_batches.quantity', '>', 0)
            ->whereNotNull('stock_batches.expiry_date');

        return match ($list) {
            self::EXPIRED => $query->whereDate('stock_batches.expiry_date', '<', $today),
            self::SOON => $query->whereDate('stock_batches.expiry_date', '>=', $today)
                ->whereDate('stock_batches.expiry_date', '<=', $this->soonUntil($days)->toDateString()),
            default => throw new \InvalidArgumentException("Unknown expiry list {$list}."),
        };
    }

    /**
     * Days from today to the expiry date: 0 on the expiry day (still
     * sellable), -1 the day after (expired yesterday).
     */
    public function daysLeft(StockBatch $batch): ?int
    {
        if ($batch->expiry_date === null) {
            return null;
        }

        return (int) $this->today()->diffInDays(CarbonImmutable::parse($batch->expiry_date->toDateString()), false);
    }

    /** What the batch's stock cost: on hand x its purchase cost per base unit, in minor units. */
    public function value(StockBatch $batch): int
    {
        return UnitCost::times($batch->unit_cost, max(0.0, (float) $batch->quantity));
    }

    /**
     * Each list's batches, value and the split per location (in the shop's
     * location order), for the dashboard and the daily digest.
     *
     * @return array{today: CarbonImmutable, days: int, expired: array{batches: int, value: int, locations: list<array{location: string, batches: int, value: int}>}, soon: array{batches: int, value: int, locations: list<array{location: string, batches: int, value: int}>}}
     */
    public function summary(?int $locationId = null): array
    {
        return [
            'today' => $this->today(),
            'days' => $this->warningDays(),
            'expired' => $this->totals($this->query(self::EXPIRED, $locationId)),
            'soon' => $this->totals($this->query(self::SOON, $locationId)),
        ];
    }

    /**
     * @param  Builder<StockBatch>  $query
     * @return array{batches: int, value: int, locations: list<array{location: string, batches: int, value: int}>}
     */
    private function totals(Builder $query): array
    {
        /** @var Collection<int, StockBatch> $batches */
        $batches = $query->get(['stock_batches.id', 'stock_batches.location_id', 'stock_batches.quantity', 'stock_batches.unit_cost']);
        $names = Location::query()->ordered()->pluck('name', 'id');

        $locations = $batches->groupBy('location_id')
            ->map(fn (Collection $group, int|string $locationId): array => [
                'location_id' => (int) $locationId,
                'location' => (string) ($names[$locationId] ?? ''),
                'batches' => $group->count(),
                'value' => (int) $group->sum(fn (StockBatch $batch): int => $this->value($batch)),
            ])
            ->sortBy(fn (array $row): int => (int) array_search($row['location_id'], $names->keys()->all(), true))
            ->map(fn (array $row): array => ['location' => $row['location'], 'batches' => $row['batches'], 'value' => $row['value']])
            ->all();

        return [
            'batches' => $batches->count(),
            'value' => (int) $batches->sum(fn (StockBatch $batch): int => $this->value($batch)),
            'locations' => array_values($locations),
        ];
    }

    /** Whether the shop tracks expiry at all (the pharmacy module: batches). */
    public function enabled(): bool
    {
        return $this->currentShop->get()?->hasModule('pharmacy') ?? false;
    }
}
