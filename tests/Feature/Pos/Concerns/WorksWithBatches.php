<?php

namespace Tests\Feature\Pos\Concerns;

use App\Enums\AdjustmentReason;
use App\Enums\DosageForm;
use App\Enums\DrugSchedule;
use App\Enums\PaymentMethod;
use App\Enums\StorageCondition;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\StockBatch;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\BatchService;
use App\Services\CheckoutService;
use App\Services\Data\BatchInput;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\DrugData;
use App\Services\Data\PaymentInput;
use App\Services\ProductCatalogService;
use App\Services\StockService;
use App\Support\Quantity;
use Carbon\CarbonInterface;

/**
 * Helpers for batch and expiry tests on the demo clinic (docs/PHARMACY.md
 * §6), shared by the SQLite tests (BatchTestCase) and the MySQL group.
 * assertLedgerMatches() checks the invariants after a scenario: every level
 * and every batch equals the sum of its movements, and a batch-tracked
 * item's batches add up to its level.
 *
 * Needs $owner, $cashier, $till, $general and $doctors from the test case.
 */
trait WorksWithBatches
{
    /**
     * A tablet medicine (batch-tracked, whole units) with a box of 10, no stock yet.
     *
     * @param  list<array<string, mixed>>  $packs
     */
    protected function medicine(
        string $name = 'Panadol 500 mg Tablets',
        array $packs = [['name' => 'Box', 'factor' => 10, 'price' => 18_000]],
        ?StorageCondition $storage = null,
        DrugSchedule $schedule = DrugSchedule::Otc,
        ?int $minDaysToExpiry = null,
    ): ProductVariant {
        return app(ProductCatalogService::class)->create(
            ['name' => $name, 'unit' => 'Tablet'],
            [['price' => 2_000, 'cost' => 1_000, 'packs' => $packs]],
            user: $this->owner,
            drug: new DrugData(['Paracetamol'], DosageForm::Tablet, $schedule, storageCondition: $storage, minDaysToExpiry: $minDaysToExpiry),
        )->variants()->firstOrFail();
    }

    /** Stock In of a batch (the owner accepts short expiry), returning the batch at that location. */
    protected function receive(ProductVariant $variant, string $batchNo, string $expiry, float $quantity, ?Location $at = null, int|string|null $cost = 1_000): StockBatch
    {
        $at ??= $this->general;

        app(StockService::class)->stockIn($variant, $at, $quantity, $this->owner, $cost, batch: new BatchInput($batchNo, $expiry, acceptNearExpiry: true));

        return $this->batch($variant, $batchNo, $at);
    }

    /** Expired stock can't be received; it can be found in a count. */
    protected function foundExpired(ProductVariant $variant, string $batchNo, string $expiry, float $quantity, ?Location $at = null): StockBatch
    {
        $at ??= $this->general;

        app(StockService::class)->adjustBatchTo($variant, $at, new BatchInput($batchNo, $expiry), $quantity, AdjustmentReason::CountCorrection, $this->owner);

        return $this->batch($variant, $batchNo, $at);
    }

    protected function batch(ProductVariant $variant, string $batchNo, ?Location $at = null): StockBatch
    {
        return StockBatch::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('location_id', ($at ?? $this->general)->getKey())
            ->where('batch_no', BatchService::normalizeBatchNo($batchNo))
            ->firstOrFail();
    }

    protected function unallocated(ProductVariant $variant, ?Location $at = null): ?StockBatch
    {
        return StockBatch::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('location_id', ($at ?? $this->general)->getKey())
            ->where('is_unallocated', true)
            ->first();
    }

    /** @return array<string, float> batch number (or "unallocated") => on hand, at a location, with stock or below zero */
    protected function batchQuantities(ProductVariant $variant, ?Location $at = null): array
    {
        return StockBatch::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('location_id', ($at ?? $this->general)->getKey())
            ->where('quantity', '!=', 0)
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (StockBatch $batch): array => [$batch->is_unallocated ? 'unallocated' : $batch->batch_no => (float) $batch->quantity])
            ->all();
    }

    protected function boxOf(ProductVariant $variant): int
    {
        return (int) $variant->units()->where('name', 'Box')->value('id');
    }

    /**
     * @param  list<CartLine>  $lines
     */
    protected function sell(array $lines, bool $offline = false, ?User $cashier = null, ?CarbonInterface $soldAt = null, ?string $uuid = null): Sale
    {
        return app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->till,
            cashier: $cashier ?? $this->cashier,
            lines: $lines,
            payments: [new PaymentInput(PaymentMethod::Cash, 10_000_000)],
            uuid: $uuid,
            isOfflineSync: $offline,
            soldAt: $offline ? ($soldAt ?? now()) : null,
        ));
    }

    /** The ledger is the truth: levels and batches are exactly the sums of their movements. */
    protected function assertLedgerMatches(): void
    {
        foreach (StockLevel::query()->with('variant.product')->get() as $level) {
            $movements = Quantity::normalize((float) StockMovement::query()
                ->where('location_id', $level->location_id)
                ->where('product_variant_id', $level->product_variant_id)
                ->sum('quantity'));

            $this->assertSame($movements, Quantity::normalize((float) $level->quantity), "Level {$level->getKey()} differs from its movements.");

            if ($level->variant->product->tracksBatches()) {
                $inBatches = Quantity::normalize((float) StockBatch::query()
                    ->where('location_id', $level->location_id)
                    ->where('product_variant_id', $level->product_variant_id)
                    ->sum('quantity'));

                $this->assertSame($movements, $inBatches, "The batches of level {$level->getKey()} do not add up to it.");
                $this->assertFalse(StockMovement::query()
                    ->where('location_id', $level->location_id)
                    ->where('product_variant_id', $level->product_variant_id)
                    ->whereNull('stock_batch_id')
                    ->exists(), 'A movement of a batch-tracked item has no batch.');
            }
        }

        foreach (StockBatch::query()->get() as $batch) {
            $movements = Quantity::normalize((float) StockMovement::query()->where('stock_batch_id', $batch->getKey())->sum('quantity'));

            $this->assertSame($movements, Quantity::normalize((float) $batch->quantity), "Batch {$batch->label()} differs from its movements.");
            $this->assertTrue($batch->is_unallocated || (float) $batch->quantity >= 0, "Batch {$batch->label()} went below zero.");

            $last = StockMovement::query()->where('stock_batch_id', $batch->getKey())->latest('id')->first();

            if ($last !== null) {
                $this->assertSame(Quantity::normalize((float) $batch->quantity), Quantity::normalize((float) $last->batch_balance_after), "Batch {$batch->label()}'s running balance is off.");
            }
        }
    }
}
