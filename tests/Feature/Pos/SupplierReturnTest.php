<?php

namespace Tests\Feature\Pos;

use App\Enums\StockMovementType;
use App\Enums\SupplierReturnReason;
use App\Exceptions\PosException;
use App\Models\StockMovement;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Services\Data\CartLine;
use App\Services\Data\ReceiptLine;
use App\Services\Data\SupplierReturnLine;
use App\Services\ReportService;
use App\Services\StockService;
use App\Services\SupplierReturnService;
use App\Services\SupplierService;
use App\Support\ReportPeriod;
use Tests\Feature\Pos\Concerns\WorksWithPurchasing;

/**
 * Supplier returns (purchasing, docs/PHARMACY.md §4.9): stock goes back
 * out of the exact batches picked, expired ones included, at each batch's
 * purchase cost; the location's average does not change; the credit
 * lowers the supplier's balance.
 */
class SupplierReturnTest extends BatchTestCase
{
    use WorksWithPurchasing;

    private function returns(): SupplierReturnService
    {
        return app(SupplierReturnService::class);
    }

    /** @param  callable(): mixed  $attempt */
    private function refused(callable $attempt, string $message): void
    {
        try {
            $attempt();
            $this->fail("Expected: {$message}");
        } catch (PosException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    public function test_an_expired_batch_goes_back_to_the_supplier_at_its_own_cost(): void
    {
        $panadol = $this->medicine();
        $supplier = $this->supplier();
        // OLD at 900 a tablet (90.00 a box), NEW at 1,100: the average is (20 x 900 + 30 x 1,100) / 50 = 1,020.
        $this->postReceipt($supplier, [
            new ReceiptLine($panadol->getKey(), 2, $this->boxOf($panadol), unitCost: 9_000, batchNo: 'OLD', expiry: '12/2026', acceptShortExpiry: true),
            new ReceiptLine($panadol->getKey(), 30, unitCost: 1_100, batchNo: 'NEW', expiry: '12/2027'),
        ]);
        $this->assertSame('1020.0000', app(StockService::class)->averageCost($panadol, $this->general));

        // January: OLD has expired. It can't be sold, but it can go back.
        $this->travelTo(now()->addMonths(3));
        $sale = $this->sell([new CartLine($panadol->getKey(), 1)]);
        $this->assertSame('NEW', $sale->items->sole()->batches->sole()->batch_no);

        $old = $this->batch($panadol, 'OLD');
        $return = $this->returns()->create($supplier, $this->general, SupplierReturnReason::Expired, [
            new SupplierReturnLine($panadol->getKey(), 2, $this->boxOf($panadol), $old->getKey()),
        ], $this->owner, notes: 'Collected by rep');

        $this->assertSame(['SR-000001', SupplierReturnReason::Expired, 18_000, 'Collected by rep'], [$return->return_number, $return->reason, $return->total, $return->notes]);

        $line = $return->items->sole();
        $this->assertSame(['OLD', '2026-12-31', 'Box', 2.0, 20.0, '900.0000', 18_000], [
            $line->batch_no, $line->expiry_date?->toDateString(), $line->unit_name, (float) $line->quantity, (float) $line->base_quantity, $line->unit_cost, $line->line_total,
        ]);

        $movement = StockMovement::query()->findOrFail($line->stock_movement_id);
        $this->assertSame([StockMovementType::SupplierReturn, -20.0, $old->getKey(), 900, null], [$movement->type, (float) $movement->quantity, $movement->stock_batch_id, $movement->unit_cost, $movement->received_cost]);
        $this->assertTrue($movement->reference()->firstOrFail()->is($return));
        $this->assertStringContainsString('SR-000001 to Hemas Pharmaceuticals: Expired', (string) $movement->note);

        // Out of that batch only; the average stays.
        $this->assertSame(['NEW' => 29.0], $this->batchQuantities($panadol));
        $this->assertSame('1020.0000', app(StockService::class)->averageCost($panadol, $this->general));
        $this->assertLedgerMatches();

        // The credit lowers what is owed: 18,000 + 33,000 received, 18,000 back.
        $this->assertSame(['opening' => 0, 'purchases' => 51_000, 'returns' => 18_000, 'payments' => 0, 'balance' => 33_000], app(SupplierService::class)->balance($supplier));
        $this->assertSame([], app(StockService::class)->rebuildCosts());
    }

    public function test_a_return_takes_only_what_the_batch_holds_at_that_location(): void
    {
        $panadol = $this->medicine();
        $supplier = $this->supplier();
        $atGeneral = $this->receive($panadol, 'A', '12/2027', 20);
        $atDoctors = $this->receive($panadol, 'B', '12/2027', 5, $this->doctors);
        $return = fn (array $lines, ?User $user = null): SupplierReturn => $this->returns()->create($supplier, $this->general, SupplierReturnReason::Damaged, $lines, $user ?? $this->owner);

        $this->refused(fn () => $return([new SupplierReturnLine($panadol->getKey(), 21, batchId: $atGeneral->getKey())]), 'Only 20 Tablet left in batch A');
        $this->refused(fn () => $return([new SupplierReturnLine($panadol->getKey(), 1, batchId: $atDoctors->getKey())]), 'Choose the batch of');
        $this->refused(fn () => $return([new SupplierReturnLine($panadol->getKey(), 1)]), 'Choose the batch of');
        $this->refused(fn () => $return([new SupplierReturnLine($panadol->getKey(), 0, batchId: $atGeneral->getKey())]), 'how many');
        $this->refused(fn () => $return([new SupplierReturnLine($panadol->getKey(), 1.5, batchId: $atGeneral->getKey())]), 'whole units only');
        $this->refused(fn () => $return([]), 'at least one item');
        $this->refused(fn () => $return([new SupplierReturnLine($panadol->getKey(), 1, batchId: $atGeneral->getKey())], $this->cashier), 'permission');

        $this->assertSame(0, SupplierReturn::query()->count());
        $this->assertSame(['A' => 20.0], $this->batchQuantities($panadol));
    }

    public function test_the_stock_movements_report_names_the_grn_and_the_return(): void
    {
        $panadol = $this->medicine();
        $supplier = $this->supplier();
        $grn = $this->postReceipt($supplier, [new ReceiptLine($panadol->getKey(), 10, unitCost: 1_000, batchNo: 'A', expiry: '12/2027')]);
        $this->returns()->create($supplier, $this->general, SupplierReturnReason::Damaged, [new SupplierReturnLine($panadol->getKey(), 3, batchId: $grn->items->sole()->stock_batch_id)], $this->owner);

        $reports = app(ReportService::class);
        $period = ReportPeriod::between($this->shop->fresh() ?? $this->shop, '2026-10-18', '2026-10-18');
        $rows = $reports->stockMovementsQuery($period, true)->get()->map(fn ($row): array => $reports->presentMovement($row, $period))->keyBy('type');

        $this->assertSame(['GRN-000001', 10.0, 'A · exp 12/2027'], [$rows['Stock In']['reference'], $rows['Stock In']['quantity'], $rows['Stock In']['batch']]);
        $this->assertSame(['SR-000001', -3.0, 1_000], [$rows['Supplier Return']['reference'], $rows['Supplier Return']['quantity'], $rows['Supplier Return']['unit_cost']]);
    }

    public function test_only_the_credit_note_number_is_added_afterwards(): void
    {
        $panadol = $this->medicine();
        $batch = $this->receive($panadol, 'A', '12/2027', 20);
        $return = $this->returns()->create($this->supplier(), $this->general, SupplierReturnReason::Recalled, [new SupplierReturnLine($panadol->getKey(), 5, batchId: $batch->getKey())], $this->owner);

        $this->assertSame('CN-77', $this->returns()->setCreditNote($return, ' CN-77 ', $this->owner)->fresh()?->credit_note_no);

        foreach ([fn () => $return->update(['total' => 1]), fn () => $return->delete(), fn () => $return->items->sole()->update(['quantity' => 1])] as $attempt) {
            try {
                $attempt();
                $this->fail('A posted supplier return changed.');
            } catch (\LogicException) {
                // expected
            }
        }

        $this->assertSame(5_000, $return->fresh()?->total);
    }
}
