<?php

namespace Tests\Feature\Pos;

use App\Enums\AdjustmentReason;
use App\Enums\StockCountStatus;
use App\Enums\StockMovementType;
use App\Exceptions\PosException;
use App\Filament\Resources\StockCounts\Pages\CountStockCount;
use App\Filament\Resources\StockCounts\Pages\CreateStockCount;
use App\Filament\Resources\StockCounts\Pages\ReviewStockCount;
use App\Filament\Resources\StockCounts\StockCountResource;
use App\Models\Category;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StockMovement;
use App\Services\Data\BatchInput;
use App\Services\Data\CartLine;
use App\Services\StockCountService;
use App\Services\StockService;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Stock counts as documents (docs/PHARMACY.md §9, branch 10): a sheet per
 * location (all items, a category or a shelf), batch by batch, optionally
 * blind; counted in any unit; reviewed with values; posted as count
 * corrections in one transaction against what the system holds at posting
 * time, so a sale during the count shows as a variance.
 */
class StockCountTest extends BatchTestCase
{
    private function counts(): StockCountService
    {
        return app(StockCountService::class);
    }

    private function line(StockCount $count, string $batchNo): StockCountLine
    {
        return $count->lines()->where('batch_no', $batchNo)->firstOrFail();
    }

    public function test_the_sheet_lists_each_batch_with_stock_at_the_location(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'A', '2026-12-31', 10);
        $this->receive($panadol, 'B', '2027-06-30', 25);
        $this->receive($panadol, 'D', '2027-06-30', 5, $this->doctors);
        $this->foundExpired($panadol, 'OLD', '2026-09-30', 2);
        $c = $this->receive($panadol, 'C', '2027-01-31', 1);
        $this->sell([new CartLine($panadol->getKey(), 1)]); // from A, the first to expire that still sells
        $this->assertSame(9.0, (float) $this->batch($panadol, 'A')->quantity);

        $count = $this->counts()->start($this->general, $this->owner, note: 'Month end');

        $this->assertSame('SC-000001', $count->count_number);
        $this->assertSame(StockCountStatus::InProgress, $count->status);
        $this->assertSame(['OLD' => 2.0, 'A' => 9.0, 'C' => 1.0, 'B' => 25.0], $count->lines->mapWithKeys(fn (StockCountLine $line): array => [$line->batch_no => (float) $line->expected_quantity])->all());
        $this->assertSame((int) $c->getKey(), (int) $this->line($count, 'C')->stock_batch_id);
        $this->assertSame(['D'], $this->counts()->start($this->doctors, $this->owner)->lines->pluck('batch_no')->all());
        $this->assertLedgerMatches();
    }

    public function test_a_count_can_cover_one_category_or_one_shelf(): void
    {
        $tablets = Category::query()->create(['name' => 'Tablets', 'slug' => 'tablets']);
        $panadol = $this->medicine();
        $amoxil = $this->medicine('Amoxil 250 mg Capsules');
        $panadol->product->update(['category_id' => $tablets->getKey()]);
        $amoxil->update(['bin_location' => 'B2-01']);
        $this->receive($panadol, 'P', '2027-06-30', 10);
        $this->receive($amoxil, 'M', '2027-06-30', 10);

        $byCategory = $this->counts()->start($this->general, $this->owner, categoryId: (int) $tablets->getKey());
        $this->assertSame(['P'], $byCategory->lines->pluck('batch_no')->all());
        $this->assertSame('Category: Tablets', $byCategory->coverage());

        $byShelf = $this->counts()->start($this->general, $this->owner, binPrefix: 'B2');
        $this->assertSame(['M'], $byShelf->lines->pluck('batch_no')->all());

        $this->expectExceptionMessage('Nothing to count at General Pharmacy with that filter.');
        $this->counts()->start($this->general, $this->owner, binPrefix: 'Z9');
    }

    public function test_counts_are_entered_in_any_unit_and_posted_as_count_corrections_valued_at_average_cost(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'A', '2026-12-31', 10, cost: 1_000);
        $this->receive($panadol, 'B', '2027-06-30', 25, cost: 1_000);
        $count = $this->counts()->start($this->general, $this->owner);

        // A: 1 box (10) = no change. B: 2 boxes and 3 loose = 23, two missing.
        $this->counts()->enter($this->line($count, 'A'), 1, $this->boxOf($panadol), $this->owner);
        $this->counts()->enter($this->line($count, 'B'), 23, null, $this->owner);
        $this->assertSame(10.0, (float) $this->line($count, 'A')->counted_quantity);
        $this->assertSame(1.0, (float) $this->line($count, 'A')->counted_unit_quantity);

        $review = $this->counts()->review($count);
        $this->assertSame(-2_000, $review['net']);
        $this->assertSame(2, $review['counted']);

        $posted = $this->counts()->post($count, $this->owner);

        $this->assertSame(StockCountStatus::Posted, $posted->status);
        $this->assertSame(-2_000, $posted->variance_value);
        $b = $this->line($count, 'B');
        $this->assertSame(25.0, (float) $b->system_quantity);
        $this->assertSame(-2.0, (float) $b->variance);
        $this->assertSame(-2_000, $b->variance_value);
        $this->assertNull($this->line($count, 'A')->stock_movement_id, 'No difference, no movement.');

        $movement = StockMovement::query()->findOrFail($b->stock_movement_id);
        $this->assertSame(StockMovementType::AdjustmentOut, $movement->type);
        $this->assertSame(AdjustmentReason::CountCorrection->value, $movement->reason_code);
        $this->assertSame('Stock count SC-000001', $movement->note);
        $this->assertTrue($movement->reference()->is($count));
        $this->assertSame(['A' => 10.0, 'B' => 23.0], $this->batchQuantities($panadol));
        $this->assertLedgerMatches();

        $this->expectExceptionMessage('SC-000001 is Posted');
        $this->counts()->enter($this->line($count, 'A'), 3, null, $this->owner);
    }

    public function test_what_moves_between_counting_and_posting_is_not_a_variance(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'A', '2027-06-30', 20);
        $this->receive($panadol, 'B', '2027-09-30', 10);
        $count = $this->counts()->start($this->general, $this->owner);

        // A counted as it stands; B counted two short.
        $this->counts()->enter($this->line($count, 'A'), 20, null, $this->owner);
        $this->counts()->enter($this->line($count, 'B'), 8, null, $this->owner);
        $this->assertSame(20.0, (float) $this->line($count, 'A')->counted_system_quantity);
        $this->assertSame(10.0, (float) $this->line($count, 'B')->counted_system_quantity);

        // The count never blocks a sale, nor a receipt: three tablets sold from A, five more of B arrive.
        $this->sell([new CartLine($panadol->getKey(), 3)]);
        $this->receive($panadol, 'B', '2027-09-30', 5);

        $review = collect($this->counts()->review($count)['lines'])->keyBy(fn (array $row): string => (string) $row['line']?->batch_no);
        $this->assertSame([20.0, 20.0, 17.0, 0.0], [$review['A']['snapshot'], $review['A']['counted'], $review['A']['current'], $review['A']['variance']]);
        $this->assertSame([10.0, 8.0, 15.0, -2.0], [$review['B']['snapshot'], $review['B']['counted'], $review['B']['current'], $review['B']['variance']]);

        $this->counts()->post($count, $this->owner);

        $a = $this->line($count, 'A');
        $this->assertSame([17.0, 0.0, null], [(float) $a->system_quantity, (float) $a->variance, $a->stock_movement_id]);
        $b = $this->line($count, 'B');
        $this->assertSame([15.0, -2.0], [(float) $b->system_quantity, (float) $b->variance]);
        $this->assertSame(['A' => 17.0, 'B' => 13.0], $this->batchQuantities($panadol), 'Counted less what sold since, plus what arrived since: no phantom gain.');
        $this->assertSame(-2_000, $count->fresh()?->variance_value);
        $this->assertLedgerMatches();
    }

    public function test_counting_a_line_again_takes_a_new_snapshot_and_a_variance_never_goes_below_zero(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'A', '2027-06-30', 10);
        $count = $this->counts()->start($this->general, $this->owner);
        $line = $this->line($count, 'A');

        $this->counts()->enter($line, 9, null, $this->owner);
        $this->sell([new CartLine($panadol->getKey(), 4)]);
        $this->counts()->enter($line, 5, null, $this->owner); // counted again after the sale
        $this->assertSame(6.0, (float) $line->fresh()?->counted_system_quantity);

        // Then the rest sells before posting: the missing one can't take the batch below zero.
        $this->sell([new CartLine($panadol->getKey(), 6)]);
        $this->counts()->post($count, $this->owner);

        $this->assertSame(0.0, (float) $this->batch($panadol, 'A')->quantity);
        $this->assertSame(0.0, (float) $this->line($count, 'A')->variance);
        $this->assertLedgerMatches();

        // Clearing a count clears its snapshot.
        $other = $this->counts()->start($this->general, $this->owner);
        $this->counts()->addLine($other, (int) $panadol->getKey(), $this->owner, batchId: (int) $this->batch($panadol, 'A')->getKey(), quantity: 0);
        $cleared = $this->counts()->enter($other->lines()->sole(), null, null, $this->owner);
        $this->assertNull($cleared->counted_system_quantity);
    }

    public function test_a_batch_found_on_the_shelf_is_added_and_counted_in(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'A', '2027-06-30', 5);
        $count = $this->counts()->start($this->general, $this->owner);
        $this->counts()->enter($this->line($count, 'A'), 5, null, $this->owner);

        $found = $this->counts()->addLine($count, (int) $panadol->getKey(), $this->owner, newBatch: new BatchInput('X9', '08/2026'), quantity: 4);
        $this->assertNull($found->stock_batch_id);
        $this->assertSame('2026-08-31', $found->expiry_date?->toDateString());

        try {
            $this->counts()->addLine($count, (int) $panadol->getKey(), $this->owner, batchId: (int) $this->batch($panadol, 'A')->getKey());
            $this->fail('A batch went on the sheet twice.');
        } catch (PosException $exception) {
            $this->assertStringContainsString('already on this count', $exception->getMessage());
        }

        $this->counts()->post($count, $this->owner);

        $this->assertSame(['A' => 5.0, 'X9' => 4.0], $this->batchQuantities($panadol));
        $this->assertSame((int) $this->batch($panadol, 'X9')->getKey(), (int) $found->fresh()?->stock_batch_id);
        $this->assertLedgerMatches();
    }

    public function test_an_item_counted_in_full_settles_its_unallocated_stock(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'A', '2027-06-30', 2);
        $this->sell([new CartLine($panadol->getKey(), 5)], offline: true); // 3 sold beyond the batches
        $this->assertSame(-3.0, (float) $this->unallocated($panadol)?->quantity);
        $count = $this->counts()->start($this->general, $this->owner);
        $this->counts()->addLine($count, (int) $panadol->getKey(), $this->owner, newBatch: new BatchInput('SHELF', '2027-01-31'), quantity: 6);

        $review = $this->counts()->review($count);
        $this->assertSame('Unallocated (sold beyond its batches)', end($review['lines'])['batch']);

        $this->counts()->post($count, $this->owner);

        $this->assertSame(['SHELF' => 6.0], $this->batchQuantities($panadol));
        $this->assertSame(0.0, (float) $this->unallocated($panadol)?->quantity);
        $this->assertSame(6.0, $panadol->stockAt($this->general));
        $this->assertLedgerMatches();
    }

    public function test_uncounted_lines_are_left_alone_and_unallocated_stock_waits_for_a_full_count(): void
    {
        $panadol = $this->medicine();
        $this->foundExpired($panadol, 'OLD', '2026-09-30', 3);
        $this->receive($panadol, 'A', '2026-12-31', 2);
        $this->sell([new CartLine($panadol->getKey(), 5)], offline: true); // A's 2, then 3 beyond the batches
        $count = $this->counts()->start($this->general, $this->owner);
        $this->counts()->enter($this->line($count, 'OLD'), 2, null, $this->owner);
        $this->counts()->addLine($count, (int) $panadol->getKey(), $this->owner, newBatch: new BatchInput('D', '2027-12-31'));

        $this->counts()->post($count, $this->owner);

        $this->assertSame(['OLD' => 2.0, 'unallocated' => -3.0], $this->batchQuantities($panadol), 'Batch D was not counted, so neither D nor the unallocated stock changed.');
        $this->assertNull($count->lines()->where('batch_no', 'D')->sole()->variance);
        $this->assertLedgerMatches();
    }

    public function test_items_without_batches_are_counted_too(): void
    {
        $bandage = $this->medicine('Bandage 10 cm');
        $bandage->product->update(['track_batches' => false]);
        app(StockService::class)->stockIn($bandage->fresh(), $this->general, 7, $this->owner, 500);

        $count = $this->counts()->start($this->general, $this->owner);
        $line = $count->lines()->where('product_variant_id', $bandage->getKey())->sole();
        $this->assertNull($line->batch_no);
        $this->assertSame(7.0, (float) $line->expected_quantity);

        $this->counts()->enter($line, 9, null, $this->owner);
        $this->counts()->post($count, $this->owner);

        $this->assertSame(9.0, $bandage->fresh()?->stockAt($this->general));
        $this->assertSame(1_000, $count->fresh()?->variance_value);
    }

    public function test_whole_units_negative_counts_and_permissions(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'A', '2027-06-30', 5);
        $count = $this->counts()->start($this->general, $this->owner);
        $line = $this->line($count, 'A');

        foreach ([[1.5, null, 'whole units only'], [-1, null, 'cannot be below zero']] as [$quantity, $unit, $message]) {
            try {
                $this->counts()->enter($line, $quantity, $unit, $this->owner);
                $this->fail("{$quantity} was accepted.");
            } catch (PosException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }

        // Cashiers neither count nor post, unless given stock.count; posting needs stock.adjust.
        try {
            $this->counts()->start($this->general, $this->cashier);
            $this->fail('A cashier started a count.');
        } catch (PosException $exception) {
            $this->assertSame('You do not have permission to count stock.', $exception->getMessage());
        }

        $this->cashier->givePermissionTo('stock.count');
        $this->counts()->enter($line, 4, null, $this->cashier);

        try {
            $this->counts()->post($count, $this->cashier);
            $this->fail('A cashier posted a count.');
        } catch (PosException $exception) {
            $this->assertSame('You do not have permission to post stock counts.', $exception->getMessage());
        }

        $this->counts()->cancel($count, $this->cashier);
        $this->assertSame(StockCountStatus::Cancelled, $count->fresh()?->status);
        $this->assertSame(['A' => 5.0], $this->batchQuantities($panadol));
    }

    public function test_the_sheet_prints_blind_or_with_system_quantities(): void
    {
        $panadol = $this->medicine();
        $panadol->update(['bin_location' => 'A1-03']);
        $this->receive($panadol, 'A', '2027-06-30', 37);

        $open = $this->counts()->start($this->general, $this->owner);
        $blind = $this->counts()->start($this->general, $this->owner, blind: true);

        $this->actingAs($this->owner)->get(route('stock.print.count', $open))
            ->assertOk()->assertSee('SC-000001')->assertSee('A1-03')->assertSee('37 Tablet')->assertSee('Box of 10');
        $this->actingAs($this->owner)->get(route('stock.print.count', $blind))
            ->assertOk()->assertSee('Blind')->assertDontSee('37 Tablet');
        $this->actingAs($this->cashier)->get(route('stock.print.count', $open))->assertForbidden();
    }

    public function test_the_screens_start_count_review_and_post(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);
        $panadol = $this->medicine();
        $this->receive($panadol, 'A', '2027-06-30', 30, cost: 1_000);

        Livewire::test(CreateStockCount::class)
            ->fillForm(['location_id' => $this->general->getKey(), 'blind' => true])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();
        $count = StockCount::query()->sole();
        $line = $count->lines->sole();

        $this->get(StockCountResource::getUrl('count', ['record' => $count]))->assertOk()->assertSee('blind');

        Livewire::test(CountStockCount::class, ['record' => $count->getRouteKey()])
            ->assertTableColumnHidden('expected_quantity')
            ->callTableAction('countInPacks', $line, data: ['unit_id' => $this->boxOf($panadol), 'quantity' => 2])
            ->assertHasNoTableActionErrors();
        $this->assertSame(20.0, (float) $line->fresh()?->counted_quantity);

        $this->get(StockCountResource::getUrl('review', ['record' => $count]))->assertOk()->assertSee('-10')->assertSee('LKR 100.00');

        Livewire::test(ReviewStockCount::class, ['record' => $count->getRouteKey()])
            ->callAction('post')
            ->assertRedirect(StockCountResource::getUrl('count', ['record' => $count]));

        $this->assertSame(StockCountStatus::Posted, $count->fresh()?->status);
        $this->assertSame(['A' => 20.0], $this->batchQuantities($panadol));
        $this->get(StockCountResource::getUrl('count', ['record' => $count]))->assertOk()->assertSee('System at posting');

        // The review is the owner's.
        $this->cashier->givePermissionTo(['admin.access', 'stock.count']);
        $this->flushSession();
        $this->actingAs($this->cashier)->get(StockCountResource::getUrl('review', ['record' => $count]))->assertForbidden();
        $this->get(StockCountResource::getUrl('count', ['record' => $count]))->assertOk();
    }
}
