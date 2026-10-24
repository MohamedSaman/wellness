<?php

namespace Tests\Feature\Pos;

use App\Enums\AdjustmentReason;
use App\Enums\BatchStatus;
use App\Enums\StockMovementType;
use App\Exceptions\PosException;
use App\Models\ProductVariant;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Data\BatchInput;
use App\Services\Data\CartLine;
use App\Services\StockService;
use App\Support\BatchExpiry;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoClinicStockSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Batches in StockService (docs/PHARMACY.md §4.5, §6): receiving into a
 * batch, first expiry first out, expiry rules, counts per batch, and the
 * rebuild of batch quantities from the ledger.
 */
class BatchStockTest extends BatchTestCase
{
    public function test_stock_in_creates_a_batch_whose_quantity_is_its_movements(): void
    {
        $panadol = $this->medicine();
        $batch = $this->receive($panadol, ' pn-2401 ', '03/2027', 100);

        $this->assertSame('PN-2401', $batch->batch_no, 'Batch numbers are trimmed and stored in capitals.');
        $this->assertSame('2027-03-31', $batch->expiry_date?->toDateString(), 'A printed month means its last day.');
        $this->assertSame('1000.0000', $batch->unit_cost);
        $this->assertSame(100.0, (float) $batch->quantity);
        $this->assertFalse($batch->is_unallocated);
        $this->assertSame(BatchStatus::Active, $batch->status);

        $movement = StockMovement::query()->where('type', StockMovementType::StockIn)->sole();
        $this->assertSame($batch->getKey(), $movement->stock_batch_id);
        $this->assertSame(100.0, (float) $movement->batch_balance_after);
        $this->assertSame('1000.0000', $movement->received_cost);
        $this->assertTrue($batch->source()->firstOrFail()->is($movement), 'A batch remembers what brought it in.');

        // The same batch again adds to it; another batch is another row.
        $this->receive($panadol, 'PN-2401', '2027-03-31', 20);
        $this->receive($panadol, 'PN-2409', '09/2027', 50);

        $this->assertSame(['PN-2401' => 120.0, 'PN-2409' => 50.0], $this->batchQuantities($panadol));
        $this->assertSame(170.0, $panadol->stockAt($this->general));
        $this->assertLedgerMatches();
    }

    public function test_expiry_dates_are_read_as_printed(): void
    {
        $this->assertSame('2027-03-31', BatchExpiry::parse('03/2027')->toDateString());
        $this->assertSame('2027-03-31', BatchExpiry::parse('3/27')->toDateString());
        $this->assertSame('2028-02-29', BatchExpiry::parse('2028-02')->toDateString());
        $this->assertSame('2027-03-15', BatchExpiry::parse('15/03/2027')->toDateString());
        $this->assertSame('2027-03-15', BatchExpiry::parse('2027-03-15')->toDateString());

        foreach (['13/2027', '2027-02-30', 'March', ''] as $wrong) {
            try {
                BatchExpiry::parse($wrong);
                $this->fail("{$wrong} was read as a date.");
            } catch (PosException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_batch_tracked_item_needs_a_batch_with_a_sensible_future_expiry(): void
    {
        $panadol = $this->medicine();
        $stock = app(StockService::class);

        $refused = [
            'no batch' => [fn () => $stock->stockIn($panadol, $this->general, 10, $this->owner, 1_000), 'enter the batch number and expiry'],
            'blank number' => [fn () => $stock->stockIn($panadol, $this->general, 10, $this->owner, batch: new BatchInput('  ', '03/2027')), 'enter the batch number'],
            'expired' => [fn () => $stock->stockIn($panadol, $this->general, 10, $this->owner, batch: new BatchInput('X1', '09/2026')), 'expired on 30/09/2026'],
            'expires today' => [fn () => $stock->stockIn($panadol, $this->general, 10, $this->owner, batch: new BatchInput('X1', '2026-10-18')), 'expired on 18/10/2026'],
            'ten years away' => [fn () => $stock->stockIn($panadol, $this->general, 10, $this->owner, batch: new BatchInput('X1', '12/2037')), 'more than 10 years away'],
            'not a date' => [fn () => $stock->stockIn($panadol, $this->general, 10, $this->owner, batch: new BatchInput('X1', 'soon')), 'is not an expiry date'],
            'opening stock' => [fn () => $stock->opening($panadol, $this->general, 10, $this->owner), 'enter the batch number and expiry'],
        ];

        foreach ($refused as $case => [$attempt, $message]) {
            try {
                $attempt();
                $this->fail("Stock in was accepted: {$case}.");
            } catch (PosException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage(), $case);
            }
        }

        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(0, StockBatch::query()->count());

        // Nothing can bypass batches.
        $this->expectException(\LogicException::class);
        $stock->record($panadol, $this->general, StockMovementType::StockIn, 10, $this->owner);
    }

    public function test_stock_close_to_expiry_needs_the_owner_to_accept_it(): void
    {
        $panadol = $this->medicine();
        $stock = app(StockService::class);
        $shortDated = new BatchInput('SD1', '12/2026'); // within the 90-day warning

        try {
            $stock->stockIn($panadol, $this->general, 10, $this->owner, batch: $shortDated);
            $this->fail('Short-dated stock was received without being accepted.');
        } catch (PosException $exception) {
            $this->assertStringContainsString('within 90 days', $exception->getMessage());
            $this->assertStringContainsString('Accept short expiry', $exception->getMessage());
        }

        // Someone who may receive stock, but not accept short expiry.
        Role::findOrCreate('storekeeper', 'web')->syncPermissions(['stock.receive']);
        $storekeeper = User::factory()->create(['shop_id' => $this->shop->getKey()]);
        $storekeeper->assignRole('storekeeper');

        try {
            $stock->stockIn($panadol, $this->general, 10, $storekeeper, batch: new BatchInput('SD1', '12/2026', acceptNearExpiry: true));
            $this->fail('Short-dated stock was accepted without batches.manage.');
        } catch (PosException $exception) {
            $this->assertStringContainsString('Only the owner', $exception->getMessage());
        }

        $stock->stockIn($panadol, $this->general, 10, $this->owner, batch: new BatchInput('SD1', '12/2026', acceptNearExpiry: true));
        $stock->stockIn($panadol, $this->general, 10, $storekeeper, batch: new BatchInput('LD1', '06/2027'));

        // The window is a shop setting.
        $this->shop->update(['settings' => ['near_expiry_warning_days' => 30]]);
        $stock->stockIn($panadol, $this->general, 10, $storekeeper, batch: new BatchInput('SD2', '12/2026'));

        $this->assertSame(['SD1' => 10.0, 'LD1' => 10.0, 'SD2' => 10.0], $this->batchQuantities($panadol));
    }

    public function test_one_batch_number_has_one_expiry_at_every_location(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'PN1', '03/2027', 10);

        try {
            $this->receive($panadol, 'pn1', '04/2027', 5, $this->doctors);
            $this->fail('The same batch was received with another expiry.');
        } catch (PosException $exception) {
            $this->assertStringContainsString('already recorded with expiry 31/03/2027', $exception->getMessage());
        }

        // The same number on another item is a different batch.
        $amoxil = $this->medicine('Amoxil 250 mg Capsules', []);
        $this->receive($amoxil, 'PN1', '04/2027', 5);

        $this->assertSame(['PN1' => 5.0], $this->batchQuantities($amoxil));
    }

    public function test_sales_take_the_soonest_expiry_first_and_split_across_batches(): void
    {
        $panadol = $this->medicine();
        $later = $this->receive($panadol, 'LATER', '03/2027', 200);
        $sooner = $this->receive($panadol, 'SOONER', '11/2026', 10);

        // 25 tablets: all 10 of the sooner batch, then 15 of the later one (PHARMACY.md §6.2).
        $sale = $this->sell([new CartLine($panadol->getKey(), 25)]);
        $line = $sale->items->sole();

        $this->assertSame([[$sooner->getKey(), 10.0, 'SOONER', '2026-11-30'], [$later->getKey(), 15.0, 'LATER', '2027-03-31']], $line->batches()->get()
            ->map(fn ($piece): array => [$piece->stock_batch_id, (float) $piece->quantity, $piece->batch_no, $piece->expiry_date?->toDateString()])->all());

        // One sale movement per batch, with the level's balance running across them.
        $movements = StockMovement::query()->where('type', StockMovementType::Sale)->orderBy('id')->get();
        $this->assertSame([[-10.0, 200.0, 0.0], [-15.0, 185.0, 185.0]], $movements
            ->map(fn (StockMovement $m): array => [(float) $m->quantity, (float) $m->balance_after, (float) $m->batch_balance_after])->all());
        $this->assertSame($line->batches()->pluck('stock_movement_id')->all(), $movements->pluck('id')->all());

        $this->assertSame(['LATER' => 185.0], $this->batchQuantities($panadol));
        $this->assertLedgerMatches();
    }

    public function test_equal_expiry_goes_to_the_batch_received_first(): void
    {
        $panadol = $this->medicine();
        $first = $this->receive($panadol, 'FIRST', '03/2027', 5);
        $this->travel(1)->hours();
        $this->receive($panadol, 'SECOND', '03/2027', 5);

        $sale = $this->sell([new CartLine($panadol->getKey(), 3)]);

        $this->assertSame($first->getKey(), $sale->items->sole()->batches()->sole()->stock_batch_id);
    }

    public function test_expired_batches_are_never_sold_and_the_refusal_says_why(): void
    {
        $panadol = $this->medicine();
        $this->foundExpired($panadol, 'OLD', '09/2026', 30);
        $this->receive($panadol, 'NEW', '03/2027', 5);

        try {
            $this->sell([new CartLine($panadol->getKey(), 8)]);
            $this->fail('An expired batch was sold.');
        } catch (PosException $exception) {
            $this->assertStringContainsString('5 available, 8 requested', $exception->getMessage());
            $this->assertStringContainsString('30 expired', $exception->getMessage());
        }

        $sale = $this->sell([new CartLine($panadol->getKey(), 5)]);

        $this->assertSame('NEW', $sale->items->sole()->batches()->sole()->batch_no);
        $this->assertSame(['OLD' => 30.0], $this->batchQuantities($panadol));
        $this->assertLedgerMatches();
    }

    public function test_a_batch_is_sold_up_to_its_expiry_day_and_not_after(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'OCT', '10/2026', 10); // expires 31/10/2026

        $this->travelTo(CarbonImmutable::parse('2026-10-31 18:00:00', 'UTC')); // 23:30 on the 31st in Colombo
        $this->sell([new CartLine($panadol->getKey(), 1)]);

        $this->travelTo(CarbonImmutable::parse('2026-10-31 19:00:00', 'UTC')); // 00:30 on 1 November in Colombo

        $this->expectException(PosException::class);
        $this->sell([new CartLine($panadol->getKey(), 1)]);
    }

    public function test_minimum_days_to_expiry_come_from_the_shop_or_the_medicine(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'NOV', '11/2026', 10); // 43 days left
        $this->receive($panadol, 'MAR', '03/2027', 10);

        $this->shop->update(['settings' => ['min_days_to_expiry_for_sale' => 60]]);
        $sale = $this->sell([new CartLine($panadol->getKey(), 2)]);
        $this->assertSame('MAR', $sale->items->sole()->batches()->sole()->batch_no, 'Too close to expiry for the shop.');

        try {
            $this->sell([new CartLine($panadol->getKey(), 9)]);
            $this->fail('A batch inside the minimum days was sold.');
        } catch (PosException $exception) {
            $this->assertStringContainsString('10 too close to expiry to sell', $exception->getMessage());
        }

        // A medicine's own setting wins over the shop's.
        $insulin = $this->medicine('Insulin', [], minDaysToExpiry: 30);
        $this->receive($insulin, 'IN1', '11/2026', 5);
        $this->assertSame('IN1', $this->sell([new CartLine($insulin->getKey(), 1)])->items->sole()->batches()->sole()->batch_no);
    }

    public function test_quarantined_and_recalled_batches_are_not_sold(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'Q1', '11/2026', 10)->update(['status' => BatchStatus::Quarantined]);
        $this->receive($panadol, 'R1', '12/2026', 10)->update(['status' => BatchStatus::Recalled]);
        $this->receive($panadol, 'OK', '03/2027', 10);

        $this->assertSame('OK', $this->sell([new CartLine($panadol->getKey(), 4)])->items->sole()->batches()->sole()->batch_no);

        try {
            $this->sell([new CartLine($panadol->getKey(), 7)]);
            $this->fail('A blocked batch was sold.');
        } catch (PosException $exception) {
            $this->assertStringContainsString('20 quarantined or recalled', $exception->getMessage());
        }
    }

    public function test_a_batch_tracked_item_is_counted_batch_by_batch(): void
    {
        $panadol = $this->medicine();
        $stock = app(StockService::class);
        $batch = $this->receive($panadol, 'PN1', '03/2027', 40);

        try {
            $stock->adjustTo($panadol, $this->general, 30, AdjustmentReason::CountCorrection, $this->owner);
            $this->fail('A batch-tracked item was counted as a whole.');
        } catch (PosException $exception) {
            $this->assertStringContainsString('counted batch by batch', $exception->getMessage());
        }

        $movement = $stock->adjustBatchTo($panadol, $this->general, $batch, 37, AdjustmentReason::Damaged, $this->owner, 'Crushed box');
        $this->assertSame([StockMovementType::AdjustmentOut, -3.0, $batch->getKey()], [$movement?->type, (float) $movement?->quantity, $movement?->stock_batch_id]);
        $this->assertNull($stock->adjustBatchTo($panadol, $this->general, $batch, 37, AdjustmentReason::CountCorrection, $this->owner));

        // A batch found on the shelf that was never received; expired stock can be recorded to write it off.
        $stock->adjustBatchTo($panadol, $this->general, new BatchInput('FOUND', '08/2026'), 6, AdjustmentReason::CountCorrection, $this->owner);
        $stock->adjustBatchTo($panadol, $this->general, $this->batch($panadol, 'FOUND'), 0, AdjustmentReason::Expired, $this->owner);

        $this->assertSame(['PN1' => 37.0], $this->batchQuantities($panadol));
        $this->assertSame(37.0, $panadol->stockAt($this->general));
        $this->assertSame('expired', StockMovement::query()->latest('id')->firstOrFail()->reason_code);
        $this->assertLedgerMatches();
    }

    public function test_rebuild_restores_batch_quantities_from_the_ledger(): void
    {
        $panadol = $this->medicine();
        $batch = $this->receive($panadol, 'PN1', '03/2027', 40);
        $this->sell([new CartLine($panadol->getKey(), 5)]);

        DB::table('stock_batches')->where('id', $batch->getKey())->update(['quantity' => 99]);

        $this->assertSame(1, app(StockService::class)->rebuildBatches());
        $this->assertSame(35.0, (float) $batch->fresh()?->quantity);
        $this->assertSame(0, app(StockService::class)->rebuildBatches());
        $this->assertSame([], app(StockService::class)->batchMismatches());

        // Stock that moved without a batch (written behind StockService's back) is reported.
        DB::table('stock_levels')->where('product_variant_id', $panadol->getKey())->update(['quantity' => 50]);
        DB::table('stock_movements')->insert([
            'uuid' => (string) Str::uuid(), 'shop_id' => $this->shop->getKey(), 'location_id' => $this->general->getKey(),
            'product_variant_id' => $panadol->getKey(), 'type' => 'adjustment_in', 'quantity' => 15, 'balance_after' => 50,
            'occurred_at' => now(), 'created_at' => now(),
        ]);

        $this->assertSame(1, Artisan::call('pos:stock-rebuild'));
        $this->assertStringContainsString('has 50 on hand but 35 in batches', Artisan::output());
    }

    public function test_the_local_demo_clinic_stock_goes_through_the_ledger(): void
    {
        $this->seed(DemoClinicStockSeeder::class);

        $panadol = ProductVariant::query()->whereHas('product', fn ($p) => $p->where('name', 'Panadol 500 mg Tablets'))->firstOrFail();
        $this->assertSame(['PN2401' => 60.0, 'PN2409' => 200.0], $this->batchQuantities($panadol));
        $this->assertSame(['PN2409' => 50.0], $this->batchQuantities($panadol, $this->doctors));
        $this->assertTrue(StockBatch::query()->where('batch_no', 'AM0921')->firstOrFail()->isExpiredOn(now()));
        $this->assertLedgerMatches();
    }
}
