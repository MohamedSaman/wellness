<?php

namespace App\Services;

use App\Enums\AdjustmentReason;
use App\Enums\StockCountStatus;
use App\Exceptions\PosException;
use App\Models\Category;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\StockBatch;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\VariantUnit;
use App\Services\Data\BatchInput;
use App\Support\CurrentShop;
use App\Support\Quantity;
use App\Support\UnitCost;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stock counts as documents (docs/PHARMACY.md §9, branch 10).
 *
 *   start()  puts the location's items on a sheet (all, or one category, or
 *            a shelf / bin prefix): one line per item, and for a
 *            batch-tracked item one line per batch holding stock there;
 *            optionally blind (the sheet and the entry screen hide what the
 *            system holds). Needs stock.count.
 *   enter()  records what was counted on a line, in any unit of the item
 *            (base units are kept), with a SNAPSHOT of what the system held
 *            for that line (its batch, or the item) at that moment; counting
 *            a line again takes a new snapshot. addLine() adds an item or a
 *            batch found on the shelf that is not on the sheet.
 *   review() shows each counted line's snapshot, count, what the system
 *            holds now and the variance (counted - snapshot), with its value
 *            at the location's average cost.
 *   post()   (stock.adjust, the owner) writes each counted line's variance,
 *            counted - snapshot, as a count correction in one transaction,
 *            through StockService (adjustBy() / adjustBatchBy()), each
 *            movement referencing the count. Uncounted lines are left as
 *            they are.
 *
 * The rule while a count is open (decided before merge): it never blocks
 * sales, transfers or receipts, and what moves between counting a line and
 * posting is NOT a variance: posting applies counted - snapshot to what the
 * system holds then. Three tablets sold after a shelf of 20 was counted at
 * 20 leave 17 after posting, with no variance. Never below zero: a batch
 * whose variance is larger than what it holds at posting goes to zero.
 *
 * A batch-tracked item whose lines were all counted also has its
 * unallocated stock (sold beyond the recorded batches) counted to zero:
 * the count found where those units went.
 */
class StockCountService
{
    public const MAX_LINES = 5000;

    public function __construct(
        private readonly StockService $stock,
        private readonly BatchService $batches,
        private readonly CurrentShop $currentShop,
    ) {}

    public function start(Location $location, User $user, bool $blind = false, ?int $categoryId = null, ?string $binPrefix = null, ?string $note = null): StockCount
    {
        if (! $user->can('stock.count')) {
            throw new PosException('You do not have permission to count stock.');
        }

        $shop = $this->currentShop->getOrFail();

        if ((int) $location->shop_id !== (int) $shop->getKey()) {
            throw new \LogicException('The location must belong to the current shop.');
        }

        if (! $location->is_active) {
            throw new PosException("{$location->name} is not active.");
        }

        $category = $categoryId === null ? null : Category::query()->findOrFail($categoryId);
        $binPrefix = blank($binPrefix) ? null : trim((string) $binPrefix);
        $lines = $this->sheetLines($location, $category, $binPrefix);

        // A filter that matches nothing is a typing mistake; with no filter, an empty sheet still
        // takes what is found on the shelf (addLine()), e.g. an item that sold out offline.
        if ($lines === [] && ($category !== null || $binPrefix !== null)) {
            throw new PosException("Nothing to count at {$location->name} with that filter.");
        }

        if (count($lines) > self::MAX_LINES) {
            throw new PosException('A count can have at most '.self::MAX_LINES.' lines: count one category or shelf at a time.');
        }

        return DB::transaction(function () use ($shop, $location, $user, $blind, $category, $binPrefix, $note, $lines): StockCount {
            // One number at a time per shop.
            Shop::query()->whereKey($shop->getKey())->lockForUpdate()->firstOrFail();

            $count = StockCount::query()->create([
                'count_number' => 'SC-'.str_pad((string) (StockCount::query()->count() + 1), 6, '0', STR_PAD_LEFT),
                'location_id' => $location->getKey(),
                'status' => StockCountStatus::InProgress,
                'blind' => $blind,
                'scope' => ['category_id' => $category?->getKey(), 'category' => $category?->name, 'bin' => $binPrefix],
                'note' => blank($note) ? null : trim((string) $note),
                'started_by' => $user->getKey(),
                'started_at' => now(),
            ]);

            foreach ($lines as $i => $line) {
                $count->lines()->create([...$line, 'sort_order' => $i]);
            }

            return $count->load('lines');
        });
    }

    /**
     * Record what was counted on a line, in any active unit of the item
     * (null = base units); null quantity clears the line.
     */
    public function enter(StockCountLine $line, ?float $quantity, ?int $unitId, User $user): StockCountLine
    {
        $this->authorizeCounting($user);

        return DB::transaction(function () use ($line, $quantity, $unitId, $user): StockCountLine {
            $count = $this->lockInProgress($line->stockCount()->firstOrFail());
            $line = StockCountLine::query()->where('stock_count_id', $count->getKey())->whereKey($line->getKey())->firstOrFail();

            if ($quantity === null) {
                $line->update(['counted_quantity' => null, 'counted_unit_id' => null, 'counted_unit_quantity' => null, 'counted_system_quantity' => null, 'counted_unallocated_quantity' => null, 'counted_by' => null, 'counted_at' => null]);

                return $line;
            }

            /** @var ProductVariant $variant */
            $variant = $line->variant()->with(['product', 'units'])->firstOrFail();
            $unit = $this->unit($variant, $unitId);
            $quantity = Quantity::normalize($quantity);

            if ($quantity < 0) {
                throw new PosException('A count cannot be below zero.');
            }

            $this->stock->assertWholeUnits($variant, $quantity, $unit->name);

            $line->update([
                'counted_quantity' => $unit->toBase($quantity),
                'counted_unit_id' => $unit->is_base ? null : $unit->getKey(),
                'counted_unit_quantity' => $quantity,
                // What the system holds for the line right now: the variance is measured from it.
                'counted_system_quantity' => $this->systemQuantity($line, (int) $count->location_id),
                'counted_unallocated_quantity' => $variant->product->tracksBatches() ? $this->unallocatedQuantity((int) $variant->getKey(), (int) $count->location_id) : null,
                'counted_by' => $user->getKey(),
                'counted_at' => now(),
            ]);

            return $line;
        });
    }

    /**
     * Add a line for something not on the sheet: an item, or for a
     * batch-tracked item a batch on record at the location (an empty one
     * too) or a batch found on the shelf (its number and expiry as printed;
     * any real date, so expired stock found can be recorded).
     */
    public function addLine(StockCount $count, int $variantId, User $user, ?int $batchId = null, ?BatchInput $newBatch = null, ?float $quantity = null, ?int $unitId = null): StockCountLine
    {
        $this->authorizeCounting($user);

        /** @var ProductVariant $variant */
        $variant = ProductVariant::query()->with(['product', 'units'])->find($variantId) ?? throw new PosException("Item #{$variantId} was not found.");

        if (! $variant->product->track_stock) {
            throw new PosException("{$variant->sku} is a service; it has no stock to count.");
        }

        $line = DB::transaction(function () use ($count, $variant, $user, $batchId, $newBatch): StockCountLine {
            $count = $this->lockInProgress($count);
            $existing = StockCountLine::query()->where('stock_count_id', $count->getKey())->where('product_variant_id', $variant->getKey());
            $attributes = ['product_variant_id' => $variant->getKey()];

            if (! $variant->product->tracksBatches()) {
                if ($batchId !== null || $newBatch !== null) {
                    throw new PosException("{$variant->sku} does not track batches.");
                }

                if ($existing->exists()) {
                    throw new PosException("{$variant->sku} is already on this count.");
                }

                $attributes['expected_quantity'] = $this->levelQuantity($variant, (int) $count->location_id);
            } elseif ($batchId !== null) {
                $batch = StockBatch::query()
                    ->where('product_variant_id', $variant->getKey())
                    ->where('location_id', $count->location_id)
                    ->where('is_unallocated', false)
                    ->find($batchId) ?? throw new PosException("That batch of {$variant->sku} is not at this location.");

                if ((clone $existing)->where('stock_batch_id', $batch->getKey())->exists()) {
                    throw new PosException("Batch {$batch->label()} is already on this count.");
                }

                $attributes += ['stock_batch_id' => $batch->getKey(), 'batch_no' => $batch->batch_no, 'expiry_date' => $batch->expiry_date?->toDateString(), 'expected_quantity' => Quantity::normalize((float) $batch->quantity)];
            } elseif ($newBatch !== null) {
                $new = $this->batches->validateNew($variant, $newBatch, $user, receipt: false);
                $onRecord = StockBatch::query()
                    ->where('product_variant_id', $variant->getKey())
                    ->where('location_id', $count->location_id)
                    ->where('is_unallocated', false)
                    ->where('batch_no', $new['batch_no'])
                    ->whereDate('expiry_date', $new['expiry_date'])
                    ->first();

                if ($onRecord !== null) {
                    if ((clone $existing)->where('stock_batch_id', $onRecord->getKey())->exists()) {
                        throw new PosException("Batch {$onRecord->label()} is already on this count.");
                    }

                    $attributes += ['stock_batch_id' => $onRecord->getKey(), 'expected_quantity' => Quantity::normalize((float) $onRecord->quantity)];
                } elseif ((clone $existing)->whereNull('stock_batch_id')->where('batch_no', $new['batch_no'])->exists()) {
                    throw new PosException("Batch {$new['batch_no']} is already on this count.");
                }

                $attributes += ['batch_no' => $new['batch_no'], 'expiry_date' => $new['expiry_date'], 'expected_quantity' => 0];
            } else {
                throw new PosException("{$variant->sku} is counted batch by batch: choose the batch, or enter the one found.");
            }

            return $count->lines()->create([...$attributes, 'sort_order' => (int) $count->lines()->max('sort_order') + 1]);
        });

        return $quantity === null ? $line : $this->enter($line, $quantity, $unitId, $user);
    }

    /** Stop the count without touching stock. */
    public function cancel(StockCount $count, User $user): StockCount
    {
        if (! $user->can('stock.count') && ! $user->can('stock.adjust')) {
            throw new PosException('You do not have permission to cancel stock counts.');
        }

        return DB::transaction(function () use ($count, $user): StockCount {
            $count = $this->lockInProgress($count);
            $count->update(['status' => StockCountStatus::Cancelled, 'cancelled_by' => $user->getKey(), 'cancelled_at' => now()]);

            return $count;
        });
    }

    /**
     * Each counted line: the snapshot taken when it was counted, the count,
     * what the system holds now (for information: sales and transfers since
     * counting are not variances), the variance (counted - snapshot) and its
     * value at the location's average cost; plus the unallocated stock a
     * full count of an item settles.
     *
     * @return array{lines: list<array{line: StockCountLine|null, variant: ProductVariant, batch: string|null, snapshot: float|null, counted: float|null, current: float, variance: float|null, value: int|null}>, counted: int, uncounted: int, gain: int, loss: int, net: int}
     */
    public function review(StockCount $count): array
    {
        $count->loadMissing('location');
        $lines = $count->lines()->with('variant.product')->get();
        $rows = [];
        $gain = 0;
        $loss = 0;

        foreach ($lines as $line) {
            $current = $this->systemQuantity($line, (int) $count->location_id);
            $counted = $line->counted_quantity === null ? null : Quantity::normalize((float) $line->counted_quantity);
            $snapshot = $counted === null ? null : $this->snapshotOf($line, $current);
            $variance = $counted === null ? null : Quantity::normalize($counted - (float) $snapshot);
            $value = $variance === null ? null : $this->value($line->variant, $count->location, $variance);
            $rows[] = ['line' => $line, 'variant' => $line->variant, 'batch' => $line->batchLabel(), 'snapshot' => $snapshot, 'counted' => $counted, 'current' => $current, 'variance' => $variance, 'value' => $value];

            if ($value !== null && $value > 0) {
                $gain += $value;
            } elseif ($value !== null) {
                $loss += $value;
            }
        }

        foreach ($this->unallocatedToSettle($count) as ['batch' => $batch, 'snapshot' => $snapshot]) {
            $variance = Quantity::normalize(-$snapshot);
            $value = $this->value($batch->variant, $count->location, $variance);
            $rows[] = ['line' => null, 'variant' => $batch->variant, 'batch' => 'Unallocated (sold beyond its batches)', 'snapshot' => $snapshot, 'counted' => 0.0, 'current' => Quantity::normalize((float) $batch->quantity), 'variance' => $variance, 'value' => $value];

            if ($value > 0) {
                $gain += $value;
            } else {
                $loss += $value;
            }
        }

        return [
            'lines' => $rows,
            'counted' => $lines->filter->isCounted()->count(),
            'uncounted' => $lines->reject->isCounted()->count(),
            'gain' => $gain,
            'loss' => $loss,
            'net' => $gain + $loss,
        ];
    }

    /**
     * Post the count: every counted line's variance (counted - snapshot) as
     * a count correction on what the system holds now, in one transaction
     * (items in id order, the order stock levels are locked in, so posting
     * never deadlocks with tills). Uncounted lines are left alone.
     */
    public function post(StockCount $count, User $user): StockCount
    {
        if (! $user->can('stock.adjust')) {
            throw new PosException('You do not have permission to post stock counts.');
        }

        return DB::transaction(function () use ($count, $user): StockCount {
            $count = $this->lockInProgress($count);
            $count->load(['lines.variant.product', 'location']);
            $location = $count->location;
            $note = "Stock count {$count->count_number}";

            if (! $count->lines->contains->isCounted()) {
                throw new PosException('Nothing has been counted on this sheet yet.');
            }

            $lines = $count->lines->filter->isCounted()
                ->sortBy(fn (StockCountLine $line): array => [(int) $line->product_variant_id, $line->stock_batch_id ?? PHP_INT_MAX])
                ->values();
            $settle = $this->unallocatedToSettle($count);
            $total = 0;

            foreach ($lines as $line) {
                $total += $this->postLine($line, $line->variant, $location, $user, $note, $count);
            }

            // Units sold beyond the batches of an item counted in full were found on the shelf.
            foreach ($settle as ['batch' => $batch, 'snapshot' => $snapshot]) {
                $line = $count->lines()->create([
                    'product_variant_id' => $batch->product_variant_id,
                    'stock_batch_id' => $batch->getKey(),
                    'expected_quantity' => $snapshot,
                    'counted_quantity' => 0,
                    'counted_system_quantity' => $snapshot,
                    'counted_by' => $user->getKey(),
                    'counted_at' => now(),
                    'sort_order' => (int) $count->lines()->max('sort_order') + 1,
                ]);
                $total += $this->postLine($line, $batch->variant, $location, $user, $note, $count);
            }

            $count->update([
                'status' => StockCountStatus::Posted,
                'posted_by' => $user->getKey(),
                'posted_at' => now(),
                'variance_value' => $total,
            ]);

            return $count;
        }, attempts: 3);
    }

    /**
     * The sheet, in shelf order: each line with what the system held when it
     * was put on (hidden by the view when the count is blind).
     *
     * @return Collection<int, StockCountLine>
     */
    public function sheet(StockCount $count): Collection
    {
        return $count->lines()->with(['variant.product', 'variant.units'])->get()->values();
    }

    /**
     * Write one line's variance (counted - snapshot) and record what was
     * written: what the system held at posting, the variance applied (less
     * than counted - snapshot only when it would take a batch below zero),
     * its cost and value. Returns the value.
     */
    private function postLine(StockCountLine $line, ProductVariant $variant, Location $location, User $user, string $note, StockCount $count): int
    {
        $counted = Quantity::normalize((float) $line->counted_quantity);
        $snapshot = $line->counted_system_quantity === null ? null : Quantity::normalize((float) $line->counted_system_quantity);

        if (! $variant->product->tracksBatches()) {
            $movement = $snapshot === null
                ? $this->stock->adjustTo($variant, $location, $counted, AdjustmentReason::CountCorrection, $user, $note, $count)
                : $this->stock->adjustBy($variant, $location, Quantity::normalize($counted - $snapshot), AdjustmentReason::CountCorrection, $user, $note, $count);
        } else {
            $batch = $line->stock_batch_id !== null
                ? StockBatch::query()->findOrFail($line->stock_batch_id)
                : new BatchInput((string) $line->batch_no, (string) $line->expiry_date?->toDateString());
            $movement = $snapshot === null
                ? $this->stock->adjustBatchTo($variant, $location, $batch, $counted, AdjustmentReason::CountCorrection, $user, $note, $count)
                : $this->stock->adjustBatchBy($variant, $location, $batch, Quantity::normalize($counted - $snapshot), AdjustmentReason::CountCorrection, $user, $note, $count);
        }

        $variance = $movement === null ? 0.0 : Quantity::normalize((float) $movement->quantity);
        $held = match (true) {
            $movement === null => $this->systemQuantity($line, (int) $location->getKey()),
            $movement->stock_batch_id !== null => Quantity::normalize((float) $movement->batch_balance_after - $variance),
            default => Quantity::normalize((float) $movement->balance_after - $variance),
        };
        $cost = $this->stock->averageCost($variant, $location);
        $value = $this->value($variant, $location, $variance, $cost);

        $line->update([
            'stock_batch_id' => $line->stock_batch_id ?? $movement?->stock_batch_id,
            'system_quantity' => $held,
            'variance' => $variance,
            'unit_cost' => $cost,
            'variance_value' => $value,
            'stock_movement_id' => $movement?->getKey(),
        ]);

        return $value;
    }

    /** The snapshot a counted line is measured from; a line counted before snapshots existed: what the system holds now. */
    private function snapshotOf(StockCountLine $line, float $current): float
    {
        return $line->counted_system_quantity === null ? $current : Quantity::normalize((float) $line->counted_system_quantity);
    }

    /** The item's unallocated stock at a location (0 when it has none). */
    private function unallocatedQuantity(int $variantId, int $locationId): float
    {
        return Quantity::normalize((float) (StockBatch::query()
            ->where('product_variant_id', $variantId)
            ->where('location_id', $locationId)
            ->where('is_unallocated', true)
            ->value('quantity') ?? 0));
    }

    /** Signed value of a variance at the location's average cost per base unit, in minor units. */
    private function value(ProductVariant $variant, Location $location, float $variance, ?string $cost = null): int
    {
        $amount = UnitCost::times($cost ?? $this->stock->averageCost($variant, $location), abs($variance));

        return $variance < 0 ? -$amount : $amount;
    }

    /** What the system holds now for a line: its batch, a batch found (if it is on record by now), or the item. */
    private function systemQuantity(StockCountLine $line, int $locationId): float
    {
        if ($line->stock_batch_id !== null) {
            return Quantity::normalize((float) StockBatch::query()->whereKey($line->stock_batch_id)->value('quantity'));
        }

        if ($line->batch_no !== null) {
            return Quantity::normalize((float) (StockBatch::query()
                ->where('product_variant_id', $line->product_variant_id)
                ->where('location_id', $locationId)
                ->where('is_unallocated', false)
                ->where('batch_no', $line->batch_no)
                ->whereDate('expiry_date', (string) $line->expiry_date?->toDateString())
                ->value('quantity') ?? 0));
        }

        return $this->levelQuantity($line->variant, $locationId);
    }

    /**
     * The unallocated stock a count settles: for each batch-tracked item
     * whose lines on this count are all counted, its unallocated batch at
     * the location and what that held when the item's last line was counted
     * (the snapshot), when that was not zero. Sold beyond the batches after
     * the count stays unallocated.
     *
     * @return list<array{batch: StockBatch, snapshot: float}>
     */
    private function unallocatedToSettle(StockCount $count): array
    {
        $snapshots = $count->lines()->get()->groupBy('product_variant_id')
            ->filter(fn (Collection $lines): bool => $lines->every->isCounted())
            ->map(fn (Collection $lines): float => Quantity::normalize((float) $lines->sortBy([['counted_at', 'asc'], ['id', 'asc']])->last()?->counted_unallocated_quantity))
            ->filter(fn (float $snapshot): bool => $snapshot != 0.0);

        if ($snapshots->isEmpty()) {
            return [];
        }

        return array_values(StockBatch::query()
            ->with('variant.product')
            ->where('location_id', $count->location_id)
            ->where('is_unallocated', true)
            ->whereIn('product_variant_id', $snapshots->keys()->all())
            ->orderBy('product_variant_id')
            ->get()
            ->filter(fn (StockBatch $batch): bool => $batch->variant->product->tracksBatches())
            ->map(fn (StockBatch $batch): array => ['batch' => $batch, 'snapshot' => (float) $snapshots->get($batch->product_variant_id)])
            ->all());
    }

    /**
     * The sheet's lines for a location: items in scope, in shelf order. At
     * the default location every stocked item is on it (an empty one may be
     * found on the shelf); elsewhere the items that have a stock level there.
     * Batch-tracked items get a line per batch holding stock.
     *
     * @return list<array<string, mixed>>
     */
    private function sheetLines(Location $location, ?Category $category, ?string $binPrefix): array
    {
        $isDefault = (bool) $location->is_default;

        $variants = ProductVariant::query()
            ->with('product')
            ->whereHas('product', fn (Builder $product) => $product->where('track_stock', true)
                ->when($category !== null, fn (Builder $query) => $query->where('category_id', $category?->getKey())))
            ->when($binPrefix !== null, fn (Builder $query) => $query->where('bin_location', 'like', addcslashes((string) $binPrefix, '%_\\').'%'))
            ->when(! $isDefault, fn (Builder $query) => $query->whereHas('stockLevels', fn (Builder $levels) => $levels->where('location_id', $location->getKey())))
            ->get()
            ->sortBy(fn (ProductVariant $variant): string => sprintf('%s|%s|%s', $variant->bin_location ?? "\u{FFFF}", mb_strtolower((string) $variant->product->name), $variant->sku))
            ->values();

        $levels = StockLevel::query()->where('location_id', $location->getKey())->whereIn('product_variant_id', $variants->modelKeys())->pluck('quantity', 'product_variant_id');
        $batches = StockBatch::query()
            ->where('location_id', $location->getKey())
            ->whereIn('product_variant_id', $variants->modelKeys())
            ->inStock()
            ->fefo()
            ->get()
            ->groupBy('product_variant_id');
        $lines = [];

        foreach ($variants as $variant) {
            if (! $variant->product->tracksBatches()) {
                $onHand = Quantity::normalize((float) ($levels[$variant->getKey()] ?? 0));

                // An item no longer sold, with nothing on hand, is not worth a line.
                if (! $variant->product->is_active && $onHand == 0.0) {
                    continue;
                }

                $lines[] = ['product_variant_id' => $variant->getKey(), 'expected_quantity' => $onHand];

                continue;
            }

            foreach ($batches->get($variant->getKey(), []) as $batch) {
                $lines[] = [
                    'product_variant_id' => $variant->getKey(),
                    'stock_batch_id' => $batch->getKey(),
                    'batch_no' => $batch->batch_no,
                    'expiry_date' => $batch->expiry_date?->toDateString(),
                    'expected_quantity' => Quantity::normalize((float) $batch->quantity),
                ];
            }
        }

        return $lines;
    }

    private function levelQuantity(ProductVariant $variant, int $locationId): float
    {
        return Quantity::normalize((float) (StockLevel::query()->where('location_id', $locationId)->where('product_variant_id', $variant->getKey())->value('quantity') ?? 0));
    }

    private function unit(ProductVariant $variant, ?int $unitId): VariantUnit
    {
        /** @var VariantUnit|null $unit */
        $unit = $unitId === null ? $variant->units->firstWhere('is_base', true) : $variant->units->firstWhere('id', $unitId);

        if ($unit === null || ! $unit->is_active) {
            throw new PosException("That unit is not one of {$variant->sku}'s units.");
        }

        return $unit;
    }

    private function lockInProgress(StockCount $count): StockCount
    {
        $locked = StockCount::query()->whereKey($count->getKey())->lockForUpdate()->firstOrFail();

        if (! $locked->isInProgress()) {
            throw new PosException("{$locked->count_number} is {$locked->status->label()}: it can't be changed.");
        }

        return $locked;
    }

    private function authorizeCounting(User $user): void
    {
        if (! $user->can('stock.count')) {
            throw new PosException('You do not have permission to count stock.');
        }
    }
}
