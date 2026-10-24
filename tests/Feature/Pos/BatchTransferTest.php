<?php

namespace Tests\Feature\Pos;

use App\Exceptions\PosException;
use App\Filament\Resources\StockTransfers\Pages\CreateStockTransfer;
use App\Models\Location;
use App\Models\StockTransfer;
use App\Services\Data\TransferLine;
use App\Services\StockService;
use App\Services\StockTransferService;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Transfers of batch-tracked items (docs/PHARMACY.md §6.3): batch by batch,
 * first expiry first out by default (never an expired batch), or the batch
 * named on the line (any, e.g. expired stock into quarantine). Each batch
 * arrives as the same batch at the destination.
 */
class BatchTransferTest extends BatchTestCase
{
    /** @param  list<TransferLine>  $lines */
    private function transfer(array $lines, ?Location $from = null, ?Location $to = null): StockTransfer
    {
        return app(StockTransferService::class)->create($from ?? $this->general, $to ?? $this->doctors, $lines, $this->owner, 'Doctor ran short');
    }

    public function test_a_transfer_moves_batches_first_expiry_first_and_never_expired_ones(): void
    {
        $panadol = $this->medicine();
        $this->foundExpired($panadol, 'GONE', '09/2026', 10);
        $this->receive($panadol, 'A', '11/2026', 5, cost: 900);
        $this->receive($panadol, 'B', '03/2027', 20, cost: 1_200);

        $transfer = $this->transfer([new TransferLine($panadol->getKey(), 8)]);
        $line = $transfer->items->sole();

        $this->assertNull($line->out_movement_id, 'A batch line keeps its movements per batch.');
        $this->assertSame([['A', '2026-11-30', 5.0], ['B', '2027-03-31', 3.0]], $line->batches
            ->map(fn ($piece): array => [$piece->batch_no, $piece->expiry_date?->toDateString(), (float) $piece->quantity])->all());

        $this->assertSame(['GONE' => 10.0, 'B' => 17.0], $this->batchQuantities($panadol));
        $this->assertSame(['A' => 5.0, 'B' => 3.0], $this->batchQuantities($panadol, $this->doctors));

        // The same batch at DOC: same number, expiry, purchase cost and receipt time.
        $atDoctors = $this->batch($panadol, 'B', $this->doctors);
        $atGeneral = $this->batch($panadol, 'B');
        $this->assertSame([$atGeneral->expiry_date?->toDateString(), $atGeneral->unit_cost, $atGeneral->received_at->toDateTimeString()], [$atDoctors->expiry_date?->toDateString(), $atDoctors->unit_cost, $atDoctors->received_at->toDateTimeString()]);
        $this->assertTrue($atDoctors->source()->firstOrFail()->is($transfer));

        // The cost travels at GEN's average, as for any item.
        $stock = app(StockService::class);
        $this->assertSame($line->unit_cost, $stock->averageCost($panadol, $this->doctors));

        // A second transfer of B adds to the same batch at DOC.
        $this->transfer([new TransferLine($panadol->getKey(), 2)]);
        $this->assertSame(['A' => 5.0, 'B' => 5.0], $this->batchQuantities($panadol, $this->doctors));

        try {
            $this->transfer([new TransferLine($panadol->getKey(), 16)]);
            $this->fail('A transfer took expired stock or more than was on hand.');
        } catch (PosException $exception) {
            $this->assertStringContainsString('15 available, 16 requested', $exception->getMessage());
            $this->assertStringContainsString('10 expired', $exception->getMessage());
        }

        $this->assertLedgerMatches();
    }

    public function test_a_named_batch_moves_even_when_expired_for_example_into_quarantine(): void
    {
        $panadol = $this->medicine();
        $gone = $this->foundExpired($panadol, 'GONE', '09/2026', 10);
        $this->receive($panadol, 'B', '03/2027', 20);
        $quarantine = Location::query()->where('code', 'QUAR')->sole(); // the pharmacy profile's Quarantine / Expired

        $this->transfer([new TransferLine($panadol->getKey(), 10, batchId: $gone->getKey())], to: $quarantine);

        $this->assertSame(['B' => 20.0], $this->batchQuantities($panadol));
        $this->assertSame(['GONE' => 10.0], $this->batchQuantities($panadol, $quarantine));

        try {
            $this->transfer([new TransferLine($panadol->getKey(), 25, batchId: $this->batch($panadol, 'B')->getKey())]);
            $this->fail('A named batch gave more than it holds.');
        } catch (PosException $exception) {
            $this->assertStringContainsString('Only 20 Tablet left in batch B', $exception->getMessage());
        }

        try {
            $this->transfer([new TransferLine($panadol->getKey(), 1, batchId: $this->batch($panadol, 'GONE', $quarantine)->getKey())]);
            $this->fail('A batch of another location was moved.');
        } catch (PosException $exception) {
            $this->assertStringContainsString('is not at General Pharmacy', $exception->getMessage());
        }

        $this->assertLedgerMatches();
    }

    public function test_the_owner_picks_a_batch_on_the_transfer_screen(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'A', '11/2026', 5);
        $b = $this->receive($panadol, 'B', '03/2027', 20);

        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);

        Livewire::test(CreateStockTransfer::class)
            ->fillForm([
                'from_location_id' => $this->general->getKey(),
                'to_location_id' => $this->doctors->getKey(),
                'lines' => [['variant_id' => $panadol->getKey(), 'quantity' => 4, 'batch_id' => $b->getKey()]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(['B' => 4.0], $this->batchQuantities($panadol, $this->doctors));
        $this->assertSame(['A' => 5.0, 'B' => 16.0], $this->batchQuantities($panadol));
    }
}
