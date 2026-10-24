<?php

namespace Tests\Feature\Pos;

use App\Enums\AdjustmentReason;
use App\Enums\StockMovementType;
use App\Exceptions\PosException;
use App\Models\AuditLog;
use App\Models\StockMovement;
use App\Models\StockWriteOff;
use App\Services\Data\CartLine;
use App\Services\Data\WriteOffLine;
use App\Services\StockService;
use App\Services\StockWriteOffService;

/**
 * Expiry write-off (docs/PHARMACY.md §9, branch 10): batches leave as
 * adjustment_out movements with the reason, valued at what each batch cost,
 * one write-off note per location, owner only, audited, printable.
 */
class StockWriteOffTest extends BatchTestCase
{
    private function writeOffs(): StockWriteOffService
    {
        return app(StockWriteOffService::class);
    }

    public function test_an_expired_batch_is_written_off_at_its_own_cost_through_the_ledger(): void
    {
        $panadol = $this->medicine();
        $old = $this->foundExpired($panadol, 'OLD', '2026-09-30', 12);  // costs the item's 1,000
        $this->receive($panadol, 'NEW', '03/2027', 20, cost: '1500.25'); // moves the average, not OLD's cost
        $levelAverage = app(StockService::class)->averageCost($panadol, $this->general);

        [$note] = $this->writeOffs()->writeOff([new WriteOffLine((int) $old->getKey())], AdjustmentReason::Expired, $this->owner, 'Destroyed with the pharmacist');

        $this->assertSame('WO-000001', $note->write_off_number);
        $this->assertSame(AdjustmentReason::Expired, $note->reason);
        $this->assertSame(12_000, $note->total);
        $this->assertSame((int) $this->general->getKey(), (int) $note->location_id);

        $item = $note->items->sole();
        $this->assertSame('OLD', $item->batch_no);
        $this->assertSame('2026-09-30', $item->expiry_date?->toDateString());
        $this->assertSame(12.0, (float) $item->quantity);
        $this->assertSame('1000.0000', $item->unit_cost);
        $this->assertSame(12_000, $item->line_total);

        $movement = StockMovement::query()->findOrFail($item->stock_movement_id);
        $this->assertSame(StockMovementType::AdjustmentOut, $movement->type);
        $this->assertSame(-12.0, (float) $movement->quantity);
        $this->assertSame('expired', $movement->reason_code);
        $this->assertSame(1_000, (int) $movement->unit_cost, 'The ledger keeps the batch cost, not the average.');
        $this->assertSame((int) $old->getKey(), (int) $movement->stock_batch_id);
        $this->assertTrue($movement->reference()->is($note));

        $this->assertSame(['NEW' => 20.0], $this->batchQuantities($panadol));
        $this->assertSame(20.0, $panadol->stockAt($this->general));
        $this->assertSame($levelAverage, app(StockService::class)->averageCost($panadol, $this->general), 'Writing off never changes the average.');
        $this->assertLedgerMatches();
    }

    public function test_batches_at_two_locations_make_one_note_each_with_the_reason_and_note(): void
    {
        $panadol = $this->medicine();
        $amoxil = $this->medicine('Amoxil 250 mg Capsules');
        $gen = $this->receive($panadol, 'P1', '2026-12-31', 10, cost: 700);
        $gen2 = $this->receive($amoxil, 'A1', '2026-11-30', 4, cost: '333.3333');
        $doc = $this->receive($panadol, 'P1', '2026-12-31', 6, $this->doctors, cost: 700);

        $notes = $this->writeOffs()->writeOff([
            new WriteOffLine((int) $doc->getKey()),
            new WriteOffLine((int) $gen2->getKey()),
            new WriteOffLine((int) $gen->getKey(), 4),
        ], AdjustmentReason::Damaged, $this->owner, 'Water damage');

        $this->assertSame(['WO-000001', 'WO-000002'], array_map(fn (StockWriteOff $note): string => $note->write_off_number, $notes));
        $this->assertSame([(int) $this->general->getKey(), (int) $this->doctors->getKey()], array_map(fn (StockWriteOff $note): int => (int) $note->location_id, $notes));
        // 4 x 700 + 4 x 333.3333 (1,333.3332 -> 1,333)
        $this->assertSame(2_800 + 1_333, $notes[0]->total);
        $this->assertSame(4_200, $notes[1]->total);
        $this->assertSame('Water damage', $notes[1]->note);

        $this->assertSame(['P1' => 6.0], $this->batchQuantities($panadol));
        $this->assertSame([], $this->batchQuantities($panadol, $this->doctors));
        $this->assertSame(['damaged'], StockMovement::query()->where('type', 'adjustment_out')->distinct()->pluck('reason_code')->all());
        $this->assertLedgerMatches();
    }

    public function test_each_note_is_audited(): void
    {
        $panadol = $this->medicine();
        $batch = $this->foundExpired($panadol, 'OLD', '2026-09-30', 5);

        [$note] = $this->writeOffs()->writeOff([new WriteOffLine((int) $batch->getKey())], AdjustmentReason::Expired, $this->owner);

        $audit = AuditLog::query()->where('event', 'stock_written_off')->sole();
        $this->assertTrue($audit->auditable()->is($note));
        $this->assertSame((int) $this->owner->getKey(), (int) $audit->user_id);
        $this->assertSame(5_000, $audit->new_values['total']);
        $this->assertSame('OLD', $audit->new_values['batches'][0]['batch_no']);
    }

    public function test_only_the_owner_writes_off(): void
    {
        $panadol = $this->medicine();
        $batch = $this->foundExpired($panadol, 'OLD', '2026-09-30', 5);

        $this->expectExceptionMessage('You do not have permission to write stock off.');

        try {
            $this->writeOffs()->writeOff([new WriteOffLine((int) $batch->getKey())], AdjustmentReason::Expired, $this->cashier);
        } finally {
            $this->assertSame(0, StockWriteOff::query()->count());
            $this->assertSame(5.0, (float) $batch->fresh()?->quantity);
        }
    }

    public function test_reasons_are_expired_damaged_or_other_with_a_note(): void
    {
        $panadol = $this->medicine();
        $batch = $this->foundExpired($panadol, 'OLD', '2026-09-30', 5);
        $line = [new WriteOffLine((int) $batch->getKey())];

        foreach ([[AdjustmentReason::CountCorrection, null, 'Write stock off as expired, damaged or other.'], [AdjustmentReason::Other, ' ', 'Add a note explaining the write-off.']] as [$reason, $note, $message]) {
            try {
                $this->writeOffs()->writeOff($line, $reason, $this->owner, $note);
                $this->fail("{$reason->value} was accepted.");
            } catch (PosException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }

        [$note] = $this->writeOffs()->writeOff($line, AdjustmentReason::Other, $this->owner, 'Recalled by the importer');
        $this->assertSame(AdjustmentReason::Other, $note->reason);
    }

    public function test_never_more_than_the_batch_holds_and_nothing_moves_when_one_line_fails(): void
    {
        $panadol = $this->medicine();
        $a = $this->receive($panadol, 'A', '2026-12-31', 10);
        $b = $this->receive($panadol, 'B', '2027-01-31', 3);

        try {
            $this->writeOffs()->writeOff([new WriteOffLine((int) $a->getKey(), 10), new WriteOffLine((int) $b->getKey(), 4)], AdjustmentReason::Damaged, $this->owner);
            $this->fail('More than the batch holds was written off.');
        } catch (PosException $exception) {
            $this->assertStringContainsString('Only 3 Tablet left in batch B', $exception->getMessage());
        }

        $this->assertSame(['A' => 10.0, 'B' => 3.0], $this->batchQuantities($panadol));
        $this->assertSame(0, StockWriteOff::query()->count());
    }

    public function test_the_unallocated_batch_is_never_written_off(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'A', '2026-12-31', 1);
        $this->sell([new CartLine($panadol->getKey(), 3)], offline: true);

        $this->expectExceptionMessage('Unallocated stock has no batch to write off');

        $this->writeOffs()->writeOff([new WriteOffLine((int) $this->unallocated($panadol)?->getKey())], AdjustmentReason::Expired, $this->owner);
    }

    public function test_the_write_off_note_prints_with_costs_for_the_owner_only(): void
    {
        $panadol = $this->medicine();
        $batch = $this->foundExpired($panadol, 'OLD', '2026-09-30', 5);
        [$note] = $this->writeOffs()->writeOff([new WriteOffLine((int) $batch->getKey())], AdjustmentReason::Expired, $this->owner, 'Witnessed');

        $this->actingAs($this->owner)->get(route('stock.print.write-off', $note))
            ->assertOk()
            ->assertSee('WO-000001')
            ->assertSee('OLD')
            ->assertSee('30/09/2026')
            ->assertSee('LKR 50.00')
            ->assertSee('Witnessed by');

        // A cashier sees stock, so may print it, but without what it cost.
        $this->actingAs($this->cashier)->get(route('stock.print.write-off', $note))
            ->assertOk()
            ->assertDontSee('LKR 50.00');
    }
}
