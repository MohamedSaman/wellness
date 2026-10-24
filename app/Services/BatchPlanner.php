<?php

namespace App\Services;

use App\Exceptions\PosException;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\StockBatch;
use App\Models\StockLevel;
use App\Services\Data\BatchPick;
use App\Support\Quantity;
use Carbon\CarbonInterface;

/**
 * Which batches a sale would take, without taking them: the till's preview
 * of first expiry first out on the quote (docs/PHARMACY.md §6.2). The same
 * batches and order as an online StockService::issue() (StockBatch::sellableOn()
 * with the item's minimum days to expiry), a pick first, and lines of the
 * same item taking batches one after another. A pick is held to the same
 * rules as an online sale's: one that may not be sold, or that holds less
 * than picked, is refused with the sale's own message. Without negative
 * stock, what an item's lines ask beyond its stock level at the location
 * (units sold beyond the batches and not settled yet) is shortfall, as the
 * sale would refuse it. Read only: no locks, no writes; the sale itself
 * allocates again under lock.
 */
class BatchPlanner
{
    public function __construct(private readonly BatchService $batches) {}

    /**
     * @param  list<array{variant: ProductVariant, base_quantity: float, picks: list<BatchPick>|null}>  $lines  batch-tracked lines at $location, quantities in base units
     * @param  CarbonInterface  $day  the sale's day in the shop's timezone
     * @param  bool  $capAtLevel  the shop does not allow negative stock: no line may take the level below zero
     * @return list<array{batches: list<array{batch_id: int, batch_no: string, expiry: string|null, quantity: float}>, shortfall: float}> one per line, in order
     *
     * @throws PosException a pick an online sale would refuse
     */
    public function plan(Location $location, array $lines, CarbonInterface $day, bool $capAtLevel = false): array
    {
        $available = $this->sellableBatches($location, $lines, $day);
        /** @var array<int, float> $left batch id => what earlier lines have not taken */
        $left = [];

        foreach ($available as $batches) {
            foreach ($batches as $batch) {
                $left[(int) $batch->getKey()] = Quantity::normalize((float) $batch->quantity);
            }
        }

        /** @var array<int, float> $onHand variant id => the level earlier lines have not taken */
        $onHand = $capAtLevel ? $this->levels($location, $lines) : [];
        $plans = [];

        foreach ($lines as $line) {
            $variant = $line['variant'];
            $batches = $available[(int) $variant->getKey()] ?? [];
            $quantity = Quantity::normalize($line['base_quantity']);
            $remaining = $quantity;
            /** @var array<int, float> $take batch id => quantity, in the order taken */
            $take = [];

            $takeFrom = function (StockBatch $batch, float $wanted) use (&$take, &$left, &$remaining): void {
                $got = Quantity::normalize(min($wanted, $left[(int) $batch->getKey()], $remaining));

                if ($got <= 0) {
                    return;
                }

                $take[(int) $batch->getKey()] = Quantity::normalize(($take[(int) $batch->getKey()] ?? 0) + $got);
                $left[(int) $batch->getKey()] = Quantity::normalize($left[(int) $batch->getKey()] - $got);
                $remaining = Quantity::normalize($remaining - $got);
            };

            foreach ($line['picks'] ?? [] as $pick) {
                $wanted = Quantity::normalize($pick->quantity);

                if ($wanted <= 0) {
                    continue;
                }

                if ($remaining <= 0) {
                    throw new PosException('More was picked from batches than the line holds.');
                }

                $wanted = min($wanted, $remaining);
                $batch = collect($batches)->first(fn (StockBatch $batch): bool => (int) $batch->getKey() === $pick->batchId);

                if ($batch === null) {
                    throw new PosException($this->notSellable($location, $variant, $pick->batchId, $day));
                }

                if ($left[(int) $batch->getKey()] <= 0) {
                    throw new PosException($this->batches->notInStockMessage($pick->batchId, (int) $variant->getKey(), (int) $location->getKey()));
                }

                if ($left[(int) $batch->getKey()] < $wanted) {
                    throw new PosException('Only '.Quantity::format($left[(int) $batch->getKey()])." {$variant->product->unit} left in batch {$batch->label()}.");
                }

                $takeFrom($batch, $wanted);
            }

            foreach ($batches as $batch) {
                $takeFrom($batch, $remaining);
            }

            $shortfall = max(0.0, $remaining);

            if ($capAtLevel) {
                $level = $onHand[(int) $variant->getKey()] ?? 0.0;
                $shortfall = max($shortfall, Quantity::normalize($quantity - max(0.0, $level)));
                $onHand[(int) $variant->getKey()] = Quantity::normalize($level - $quantity);
            }

            $byId = collect($batches)->keyBy(fn (StockBatch $batch): int => (int) $batch->getKey());

            $plans[] = [
                'batches' => array_map(fn (int $id, float $quantity): array => [
                    'batch_id' => $id,
                    'batch_no' => $byId[$id]->batch_no,
                    'expiry' => $byId[$id]->expiry_date?->toDateString(),
                    'quantity' => $quantity,
                ], array_keys($take), $take),
                'shortfall' => $shortfall,
            ];
        }

        return $plans;
    }

    /** Why a pick that is not among the sellable batches is refused: held back, or not here at all. */
    private function notSellable(Location $location, ProductVariant $variant, int $batchId, CarbonInterface $day): string
    {
        $held = StockBatch::query()
            ->whereKey($batchId)
            ->where('location_id', $location->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->inStock()
            ->first();

        return $held === null
            ? $this->batches->notInStockMessage($batchId, (int) $variant->getKey(), (int) $location->getKey())
            : $this->batches->notSellableMessage($held, $day, $this->batches->minDaysToExpiry($variant));
    }

    /**
     * The stock level of each line's item at $location, in base units.
     *
     * @param  list<array{variant: ProductVariant, base_quantity: float, picks: list<BatchPick>|null}>  $lines
     * @return array<int, float> variant id => quantity
     */
    private function levels(Location $location, array $lines): array
    {
        return StockLevel::query()
            ->where('location_id', $location->getKey())
            ->whereIn('product_variant_id', array_values(array_unique(array_map(fn (array $line): int => (int) $line['variant']->getKey(), $lines))))
            ->pluck('quantity', 'product_variant_id')
            ->map(fn ($quantity): float => Quantity::normalize((float) $quantity))
            ->all();
    }

    /**
     * The batches FEFO may sell from at $location on $day, per variant in
     * FEFO order: one query per distinct minimum days to expiry.
     *
     * @param  list<array{variant: ProductVariant, base_quantity: float, picks: list<BatchPick>|null}>  $lines
     * @return array<int, list<StockBatch>> variant id => batches
     */
    private function sellableBatches(Location $location, array $lines, CarbonInterface $day): array
    {
        $byMinDays = [];

        foreach ($lines as $line) {
            $byMinDays[$this->batches->minDaysToExpiry($line['variant'])][] = (int) $line['variant']->getKey();
        }

        $available = [];

        foreach ($byMinDays as $minDays => $variantIds) {
            $batches = StockBatch::query()
                ->where('location_id', $location->getKey())
                ->whereIn('product_variant_id', array_values(array_unique($variantIds)))
                ->sellableOn($day, $minDays)
                ->get();

            foreach ($batches as $batch) {
                $available[(int) $batch->product_variant_id][] = $batch;
            }
        }

        return $available;
    }
}
