<?php

namespace Tests\Feature\Pos\Mysql;

use App\Enums\GoodsReceiptStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\GoodsReceipt;
use App\Models\Sale;
use App\Models\SupplierOpeningBalance;
use App\Services\Data\CartLine;
use App\Services\Data\PurchaseLine;
use App\Services\Data\ReceiptLine;
use App\Services\GoodsReceiptService;
use App\Services\StockService;
use App\Services\SupplierService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Pos\Concerns\WorksWithPurchasing;

/**
 * Purchasing on MySQL (docs/PHARMACY.md §11): posting locks the GRN, its
 * order and the shop's numbering, so a draft is posted once however many
 * clicks race, deliveries against one order all count, GRN numbers never
 * repeat, and a reversal and the tills never take a batch below zero.
 */
#[Group('mysql')]
class PurchasingMysqlTest extends MysqlTestCase
{
    use WorksWithPurchasing;

    public function test_a_draft_posted_from_several_places_at_once_is_posted_once(): void
    {
        $panadol = $this->medicine();
        $draft = $this->draftReceipt($this->supplier(), [new ReceiptLine($panadol->getKey(), 10, unitCost: 1_000, batchNo: 'A', expiry: '12/2027')]);

        $outcomes = $this->race(4, fn () => app(GoodsReceiptService::class)->post($draft, $this->owner));

        $this->assertSame(1, count(array_keys($outcomes, 'ok', true)), implode("\n", $outcomes));
        foreach (array_diff($outcomes, ['ok']) as $refused) {
            $this->assertStringContainsString('has been posted', $refused);
        }

        $this->assertSame(['GRN-000001', GoodsReceiptStatus::Posted], [$draft->fresh()?->grn_number, $draft->fresh()?->status]);
        $this->assertSame(['A' => 10.0], $this->batchQuantities($panadol));
        $this->assertLedgerMatches();
    }

    public function test_deliveries_against_one_order_at_once_all_count_and_are_numbered_once(): void
    {
        $panadol = $this->medicine();
        $supplier = $this->supplier();
        $order = $this->sentOrder($supplier, [new PurchaseLine($panadol->getKey(), 10, $this->boxOf($panadol), 9_000)]);
        $line = (int) $order->items->sole()->getKey();
        $drafts = array_map(fn (int $i): GoodsReceipt => $this->draftReceipt($supplier, [
            new ReceiptLine($panadol->getKey(), 2, $this->boxOf($panadol), unitCost: 9_000, batchNo: "B{$i}", expiry: '12/2027', purchaseOrderItemId: $line),
        ], order: $order), range(0, 3));

        $outcomes = $this->race(4, fn (int $worker) => app(GoodsReceiptService::class)->post($drafts[$worker], $this->owner));

        $this->assertSame(array_fill(0, 4, 'ok'), $outcomes);
        $this->assertSame(80.0, (float) $order->items->sole()->fresh()?->received_base_quantity);
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $order->fresh()?->status);
        $this->assertEqualsCanonicalizing(['GRN-000001', 'GRN-000002', 'GRN-000003', 'GRN-000004'], GoodsReceipt::query()->pluck('grn_number')->all());
        $this->assertSame(80.0, $panadol->stockAt($this->general));
        $this->assertSame('900.0000', app(StockService::class)->averageCost($panadol, $this->general));
        $this->assertLedgerMatches();
        $this->assertSame([], app(StockService::class)->rebuildCosts());
    }

    public function test_an_opening_balance_entered_from_several_places_at_once_is_entered_once(): void
    {
        $supplier = $this->supplier();

        $outcomes = $this->race(4, fn (int $worker) => app(SupplierService::class)->recordOpeningBalance($supplier, 100_000 + $worker, $this->owner));

        $this->assertSame(1, count(array_keys($outcomes, 'ok', true)), implode("\n", $outcomes));
        foreach (array_diff($outcomes, ['ok']) as $refused) {
            $this->assertStringContainsString('already has an opening balance', $refused);
        }

        $this->assertSame(1, SupplierOpeningBalance::query()->count());

        // Reversing it twice at once reverses it once.
        $opening = SupplierOpeningBalance::query()->sole();
        $outcomes = $this->race(3, fn () => app(SupplierService::class)->reverseOpeningBalance($opening, 'Wrong amount', $this->owner));

        $this->assertSame(1, count(array_keys($outcomes, 'ok', true)), implode("\n", $outcomes));
        $this->assertSame(0, app(SupplierService::class)->balance($supplier)['balance']);
    }

    public function test_a_reversal_racing_the_tills_never_takes_a_batch_below_zero(): void
    {
        $panadol = $this->medicine();
        $grn = $this->postReceipt($this->supplier(), [new ReceiptLine($panadol->getKey(), 10, unitCost: 1_000, batchNo: 'ONLY', expiry: '12/2027')]);

        $outcomes = $this->race(4, function (int $worker) use ($grn, $panadol): void {
            $worker === 0
                ? app(GoodsReceiptService::class)->reverse($grn, 'Entered twice', $this->owner)
                : $this->sell([new CartLine($panadol->getKey(), 1)]);
        });

        $reversed = $outcomes[0] === 'ok';
        $sales = Sale::query()->count();

        // Either the reversal came first and no till could sell, or a till sold first and the reversal was refused.
        if ($reversed) {
            $this->assertSame(0, $sales, implode("\n", $outcomes));
            $this->assertSame([], $this->batchQuantities($panadol));
        } else {
            $this->assertStringContainsString('can\'t be reversed', $outcomes[0]);
            $this->assertGreaterThan(0, $sales);
            $this->assertSame(['ONLY' => 10.0 - $sales], $this->batchQuantities($panadol));
        }

        $this->assertLedgerMatches();
    }
}
