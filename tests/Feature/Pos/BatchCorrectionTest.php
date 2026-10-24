<?php

namespace Tests\Feature\Pos;

use App\Exceptions\PosException;
use App\Filament\Resources\StockBatches\Pages\ListStockBatches;
use App\Filament\Resources\StockLevels\Pages\ListStockLevels;
use App\Models\AuditLog;
use App\Models\StockBatch;
use App\Services\BatchService;
use App\Services\Data\BatchInput;
use App\Services\Data\CartLine;
use App\Services\ReceiptService;
use App\Services\StockService;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Correcting a batch number or expiry typed wrongly at receiving
 * (BatchService::correct(), batches.manage), how expiry is typed, and the
 * short-expiry window as a shop setting.
 */
class BatchCorrectionTest extends BatchTestCase
{
    private function correct(StockBatch $batch, string $batchNo, string $expiry, string $reason = 'Typo at receiving'): void
    {
        app(BatchService::class)->correct($batch, $batchNo, $expiry, $reason, $this->owner);
    }

    public function test_the_owner_corrects_a_batch_at_every_location_and_past_sales_keep_what_they_printed(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'PN1', '03/2027', 20);
        $this->receive($panadol, 'PN1', '03/2027', 10, $this->doctors);
        $sale = $this->sell([new CartLine($panadol->getKey(), 5)]);

        $this->correct($this->batch($panadol, 'PN1'), ' pn-1 ', '04/2027');

        // The same batch at both locations, same quantities.
        $this->assertSame(['PN-1' => 15.0], $this->batchQuantities($panadol));
        $this->assertSame(['PN-1' => 10.0], $this->batchQuantities($panadol, $this->doctors));
        $this->assertSame(['2027-04-30'], StockBatch::query()->where('is_unallocated', false)->pluck('expiry_date')->map(fn ($date) => $date->toDateString())->unique()->values()->all());

        // The sale line and its receipt keep the batch as sold.
        $piece = $sale->items->sole()->batches()->sole();
        $this->assertSame(['PN1', '2027-03-31'], [$piece->batch_no, $piece->expiry_date?->toDateString()]);
        $this->assertSame([['batchNo' => 'PN1', 'expiry' => '03/2027', 'quantity' => '5']], app(ReceiptService::class)->sale($sale->fresh() ?? $sale)['items'][0]['batches']);

        // Audited per location, with the reason.
        $entries = AuditLog::query()->where('event', 'batch_corrected')->orderBy('id')->get();
        $this->assertSame(['GEN', 'DOC'], $entries->map(fn (AuditLog $entry) => $entry->old_values['location'] ?? null)->all());
        $this->assertSameIgnoringKeyOrder(['batch_no' => 'PN1', 'expiry_date' => '2027-03-31', 'location' => 'GEN'], $entries[0]->old_values);
        $this->assertSameIgnoringKeyOrder(['batch_no' => 'PN-1', 'expiry_date' => '2027-04-30', 'location' => 'GEN', 'reason' => 'Typo at receiving'], $entries[0]->new_values);
        $this->assertSame($this->owner->getKey(), $entries[0]->user_id);

        // New stock of the corrected batch joins it; the old details are gone.
        $this->receive($panadol, 'PN-1', '04/2027', 5);
        $this->assertSame(['PN-1' => 20.0], $this->batchQuantities($panadol));
        $this->assertLedgerMatches();
    }

    public function test_a_correction_that_would_merge_or_clash_is_refused(): void
    {
        $panadol = $this->medicine();
        $a = $this->receive($panadol, 'A', '03/2027', 10);
        $this->receive($panadol, 'B', '06/2027', 10);
        $this->receive($panadol, 'C', '09/2027', 10, $this->doctors);

        $refused = [
            'merges into B at GEN' => [fn () => $this->correct($a, 'B', '06/2027'), 'General Pharmacy already has batch B expiring 06/2027'],
            'B has another expiry' => [fn () => $this->correct($a, 'B', '12/2027'), 'already recorded with expiry 30/06/2027'],
            'nothing changes' => [fn () => $this->correct($a, 'a', '2027-03-31'), 'already the batch number and expiry'],
            'no reason' => [fn () => $this->correct($a, 'A2', '03/2027', ' '), 'Say why'],
            'blank number' => [fn () => $this->correct($a, '  ', '03/2027'), 'Enter the batch number'],
            'too far out' => [fn () => $this->correct($a, 'A', '01/2040'), 'more than 10 years away'],
            'not a date' => [fn () => $this->correct($a, 'A', '2027/13'), 'is not an expiry date'],
            'not the owner' => [fn () => app(BatchService::class)->correct($a, 'A2', '03/2027', 'Typo', $this->cashier), 'permission'],
        ];

        foreach ($refused as $case => [$attempt, $message]) {
            try {
                $attempt();
                $this->fail("A correction was accepted: {$case}.");
            } catch (PosException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage(), $case);
            }
        }

        $this->assertSame(['A' => 10.0, 'B' => 10.0], $this->batchQuantities($panadol));
        $this->assertSame(0, AuditLog::query()->where('event', 'batch_corrected')->count());

        // The rule is per item and location: GEN's A may become the C that DOC holds.
        $this->correct($a, 'C', '09/2027');
        $this->assertSame(['C' => 10.0, 'B' => 10.0], $this->batchQuantities($panadol));

        // Unallocated stock has no batch to correct.
        $this->shop->update(['settings' => ['allow_negative_stock' => true]]);
        $this->sell([new CartLine($panadol->getKey(), 25)]);
        $this->expectException(PosException::class);
        $this->correct($this->unallocated($panadol) ?? $a, 'X', '03/2027');
    }

    public function test_the_owner_corrects_a_batch_from_the_batches_list(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);
        $panadol = $this->medicine();
        $batch = $this->receive($panadol, 'PN1', '03/2027', 10);

        Livewire::test(ListStockBatches::class)
            ->assertTableActionVisible('correct', $batch)
            ->mountTableAction('correct', $batch)
            ->assertTableActionDataSet(['batch_no' => 'PN1', 'expiry' => '31/03/2027'])
            ->setTableActionData(['batch_no' => 'PN1', 'expiry' => '05/2027', 'reason' => 'Pack says 05/2027'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertSame('2027-05-31', $batch->fresh()?->expiry_date?->toDateString());

        Livewire::test(ListStockBatches::class)
            ->callTableAction('correct', $batch, data: ['batch_no' => 'PN2', 'expiry' => '05/2027', 'reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);

        $this->assertSame('PN1', $batch->fresh()?->batch_no);
    }

    public function test_expiry_is_typed_as_a_month_or_a_full_date(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);
        $panadol = $this->medicine();

        foreach (['M1' => '02/2028', 'M2' => '2028-11', 'M3' => '7/28', 'D1' => '15/03/2028', 'D2' => '2028-03-20'] as $batchNo => $typed) {
            Livewire::test(ListStockLevels::class)
                ->callTableAction('stockIn', $panadol, data: ['location_id' => $this->general->getKey(), 'quantity' => 1, 'batch_no' => $batchNo, 'expiry' => $typed, 'unit_cost' => '10'])
                ->assertHasNoTableActionErrors();
        }

        // A printed month means its last day (leap years included); a date is kept as it is.
        $this->assertSame([
            'M1' => '2028-02-29', 'M2' => '2028-11-30', 'M3' => '2028-07-31', 'D1' => '2028-03-15', 'D2' => '2028-03-20',
        ], StockBatch::query()->orderBy('id')->get()->mapWithKeys(fn (StockBatch $batch): array => [$batch->batch_no => $batch->expiry_date?->toDateString()])->all());
    }

    public function test_the_short_expiry_window_is_a_shop_setting_the_pharmacy_profile_starts_at_90_days(): void
    {
        $panadol = $this->medicine();
        $batches = app(BatchService::class);
        $stock = app(StockService::class);

        // Shops start at 180 days; the pharmacy profile (the demo clinic) at 90 (client answers, 2026-10-20).
        $this->assertSame(180, config('pos.shop_defaults.near_expiry_warning_days'));
        $this->assertSame(90, $batches->nearExpiryWarningDays());

        // 90 days from 18/10/2026 is 16/01/2027: December 2026 is short-dated, February 2027 is not.
        try {
            $stock->stockIn($panadol, $this->general, 1, $this->owner, batch: new BatchInput('DEC', '12/2026'));
            $this->fail('Short-dated stock went in without being accepted.');
        } catch (PosException $exception) {
            $this->assertStringContainsString('within 90 days', $exception->getMessage());
        }

        $stock->stockIn($panadol, $this->general, 1, $this->owner, batch: new BatchInput('FEB', '02/2027'));

        $this->shop->update(['settings' => ['near_expiry_warning_days' => 60]]);
        $this->assertSame(60, $batches->nearExpiryWarningDays());
        $stock->stockIn($panadol, $this->general, 1, $this->owner, batch: new BatchInput('DEC', '12/2026'));

        $this->assertSame(['FEB' => 1.0, 'DEC' => 1.0], $this->batchQuantities($panadol));
    }
}
