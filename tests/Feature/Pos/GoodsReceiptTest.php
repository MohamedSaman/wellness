<?php

namespace Tests\Feature\Pos;

use App\Enums\GoodsReceiptStatus;
use App\Enums\PayableStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\StockMovementType;
use App\Exceptions\PosException;
use App\Models\GoodsReceipt;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierPayable;
use App\Models\User;
use App\Services\Data\CartLine;
use App\Services\Data\GoodsReceiptData;
use App\Services\Data\ReceiptLine;
use App\Services\GoodsReceiptService;
use App\Services\ProductCatalogService;
use App\Services\StockService;
use App\Services\SupplierPayablesService;
use App\Services\SupplierService;
use Database\Seeders\DemoClinicPurchasingSeeder;
use Database\Seeders\DemoClinicStockSeeder;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Pos\Concerns\WorksWithPurchasing;

/**
 * Goods received notes (purchasing, docs/PHARMACY.md §4.8): drafts move no
 * stock; posting brings each line in through StockService in base units,
 * into its batch, at its effective cost (free goods and discounts included),
 * re-averaging the location and settling oversold stock; posted GRNs never
 * change and are put right by a reversing GRN.
 */
class GoodsReceiptTest extends BatchTestCase
{
    use WorksWithPurchasing;

    private function grns(): GoodsReceiptService
    {
        return app(GoodsReceiptService::class);
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

    private function manager(array $permissions): User
    {
        $manager = User::factory()->create(['email' => 'manager@clinic.test']);
        $manager->forceFill(['shop_id' => $this->shop->getKey()])->save();
        $manager->givePermissionTo(['admin.access', ...$permissions]);

        return $manager;
    }

    public function test_a_draft_moves_no_stock_and_posting_receives_packs_in_base_units_into_their_batch(): void
    {
        $panadol = $this->medicine();
        $supplier = $this->supplier();

        // 10 boxes of 10 at 90.00 a box, 2 boxes free, 45.00 off the line.
        $draft = $this->draftReceipt($supplier, [new ReceiptLine($panadol->getKey(), 10, $this->boxOf($panadol), freeQuantity: 2, unitCost: 9_000, discount: 4_500, batchNo: 'pn-7', expiry: '12/2027')], invoiceNo: 'INV-881');

        $this->assertNull($draft->grn_number);
        $this->assertSame(GoodsReceiptStatus::Draft, $draft->status);
        $this->assertSame(0, StockMovement::query()->count());

        $line = $draft->items->sole();
        $this->assertSame(['Box', 10.0, 2.0, 90_000, 4_500, 85_500, 120.0, 'PN-7', '2027-12-31'], [
            $line->unit_name, (float) $line->quantity, (float) $line->free_quantity, $line->gross, $line->discount,
            $line->line_total, (float) $line->base_quantity, $line->batch_no, $line->expiry_date?->toDateString(),
        ]);

        $grn = $this->grns()->post($draft, $this->owner);

        $this->assertSame(['GRN-000001', GoodsReceiptStatus::Posted, $this->owner->getKey()], [$grn->grn_number, $grn->status, $grn->posted_by]);
        $this->assertSame(['PN-7' => 120.0], $this->batchQuantities($panadol));
        $this->assertSame(120.0, $panadol->stockAt($this->general));

        // Cost per tablet: 85,500 / 120 = 712.5000 (free goods included), kept to 4 places.
        $line = $grn->items->sole();
        $batch = $this->batch($panadol, 'PN-7');
        $this->assertSame('712.5000', $line->base_unit_cost);
        $this->assertSame([$batch->getKey(), '2027-12-31', '712.5000'], [$line->stock_batch_id, $batch->expiry_date?->toDateString(), $batch->unit_cost]);
        $this->assertTrue($batch->source()->firstOrFail()->is($grn), 'The batch remembers the GRN that brought it in.');

        $movement = StockMovement::query()->findOrFail($line->stock_movement_id);
        $this->assertSame([StockMovementType::StockIn, 120.0, 713, '712.5000', $batch->getKey()], [$movement->type, (float) $movement->quantity, $movement->unit_cost, $movement->received_cost, $movement->stock_batch_id]);
        $this->assertTrue($movement->reference()->firstOrFail()->is($grn));
        $this->assertStringContainsString('GRN-000001, invoice INV-881, Hemas Pharmaceuticals', (string) $movement->note);
        $this->assertSame('712.5000', app(StockService::class)->averageCost($panadol, $this->general));
        $this->assertSame(713, $panadol->fresh()?->last_cost_price);
        $this->assertLedgerMatches();
    }

    public function test_free_goods_and_discounts_lower_the_cost_and_only_the_receiving_location_re_averages(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'A', '12/2027', 30, cost: 1_000);
        $this->receive($panadol, 'A', '12/2027', 10, $this->doctors, cost: 1_300);

        $this->postReceipt($this->supplier(), [new ReceiptLine($panadol->getKey(), 10, $this->boxOf($panadol), freeQuantity: 2, unitCost: 9_000, discount: 4_500, batchNo: 'PN-7', expiry: '12/2027')]);

        // (30 x 1,000 + 120 x 712.5) / 150 = 770 at General Pharmacy; Doctor's Medicines is untouched.
        $stock = app(StockService::class);
        $this->assertSame('770.0000', $stock->averageCost($panadol, $this->general));
        $this->assertSame('1300.0000', $stock->averageCost($panadol, $this->doctors));
        $this->assertSame(['A' => 30.0, 'PN-7' => 120.0], $this->batchQuantities($panadol));
    }

    public function test_the_invoice_discount_is_spread_over_the_lines_by_value_and_adds_up_exactly(): void
    {
        $panadol = $this->medicine();
        $amoxil = $this->medicine('Amoxil 250 mg Capsules');

        // 5 boxes at 120.00 = 600.00 and 40 loose at 10.00 = 400.00, less 10.01 on the invoice.
        $grn = $this->postReceipt($this->supplier(), [
            new ReceiptLine($panadol->getKey(), 5, $this->boxOf($panadol), unitCost: 12_000, batchNo: 'P1', expiry: '12/2027'),
            new ReceiptLine($amoxil->getKey(), 40, unitCost: 1_000, batchNo: 'A1', expiry: '06/2028'),
        ], invoiceDiscount: 1_001);

        [$first, $second] = $grn->items->all();
        // 600.6 and 400.4: the odd minor unit goes to the larger line.
        $this->assertSame([601, 59_399, '1187.9800'], [$first->invoice_discount_share, $first->line_total, $first->base_unit_cost]);
        $this->assertSame([400, 39_600, '990.0000'], [$second->invoice_discount_share, $second->line_total, $second->base_unit_cost]);
        $this->assertSame([100_000, 0, 1_001, 98_999], [$grn->subtotal, $grn->line_discount_total, $grn->invoice_discount, $grn->total]);
        $this->assertSame($grn->total, $first->line_total + $second->line_total);
    }

    public function test_tax_on_a_line_is_part_of_its_cost_and_a_reversal_takes_it_back_out(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'A', '12/2027', 30, cost: 1_000);
        $supplier = $this->supplier();

        // 10 boxes of 10 at 90.00, 2 free, 45.00 off, and 153.90 VAT on the line (18%).
        $grn = $this->postReceipt($supplier, [new ReceiptLine($panadol->getKey(), 10, $this->boxOf($panadol), freeQuantity: 2, unitCost: 9_000, discount: 4_500, batchNo: 'PN-7', expiry: '12/2027', tax: 15_390)]);

        // The clinic doesn't reclaim the VAT: 855.00 + 153.90 = 1,008.90 over 120 tablets = 8.4075 a tablet.
        $line = $grn->items->sole();
        $this->assertSame([15_390, 0, 100_890, '840.7500'], [$line->tax, $line->invoice_tax_share, $line->line_total, $line->base_unit_cost]);
        $this->assertSame([90_000, 4_500, 0, 15_390, 0, 100_890], [$grn->subtotal, $grn->line_discount_total, $grn->invoice_discount, $grn->line_tax_total, $grn->invoice_tax, $grn->total]);
        $this->assertSame('840.7500', $this->batch($panadol, 'PN-7')->unit_cost);

        // (30 x 1,000 + 120 x 840.75) / 150 = 872.60; the supplier is owed the tax too.
        $this->assertSame('872.6000', app(StockService::class)->averageCost($panadol, $this->general));
        $this->assertSame(100_890, app(SupplierService::class)->balance($supplier)['balance']);

        $reversal = $this->grns()->reverse($grn, 'Wrong VAT', $this->owner);

        $this->assertSame([-15_390, 0, -100_890], [$reversal->line_tax_total, $reversal->invoice_tax, $reversal->total]);
        $this->assertSame([-15_390, -100_890, '840.7500'], [$reversal->items->sole()->tax, $reversal->items->sole()->line_total, $reversal->items->sole()->base_unit_cost]);
        $this->assertSame('1000.0000', app(StockService::class)->averageCost($panadol, $this->general));
        $this->assertSame(0, app(SupplierService::class)->balance($supplier)['balance']);
        $this->assertLedgerMatches();
    }

    public function test_tax_on_the_invoice_is_spread_over_the_lines_by_value_after_discounts(): void
    {
        $panadol = $this->medicine();
        $amoxil = $this->medicine('Amoxil 250 mg Capsules');

        // 600.00 and 400.00 of goods, 10.01 off the invoice, then 178.20 VAT on the invoice.
        $grn = $this->postReceipt($this->supplier(), [
            new ReceiptLine($panadol->getKey(), 5, $this->boxOf($panadol), unitCost: 12_000, batchNo: 'P1', expiry: '12/2027'),
            new ReceiptLine($amoxil->getKey(), 40, unitCost: 1_000, batchNo: 'A1', expiry: '06/2028'),
        ], invoiceDiscount: 1_001, invoiceTax: 17_820);

        [$first, $second] = $grn->items->all();
        // After the discount the lines are worth 593.99 and 396.00: the tax goes 106.92 and 71.28
        // (the odd minor unit to the larger line), so the shares add up to the invoice's tax.
        $this->assertSame([601, 0, 10_692, 70_091, '1401.8200'], [$first->invoice_discount_share, $first->tax, $first->invoice_tax_share, $first->line_total, $first->base_unit_cost]);
        $this->assertSame([400, 0, 7_128, 46_728, '1168.2000'], [$second->invoice_discount_share, $second->tax, $second->invoice_tax_share, $second->line_total, $second->base_unit_cost]);
        $this->assertSame([100_000, 1_001, 0, 17_820, 116_819], [$grn->subtotal, $grn->invoice_discount, $grn->line_tax_total, $grn->invoice_tax, $grn->total]);
        $this->assertSame($grn->total, $first->line_total + $second->line_total);
        $this->assertSame('1401.8200', app(StockService::class)->averageCost($panadol, $this->general));

        $reversal = $this->grns()->reverse($grn, 'Wrong invoice', $this->owner);
        $this->assertSame([-17_820, -116_819], [$reversal->invoice_tax, $reversal->total]);
        $this->assertSame([-10_692, -7_128], $reversal->items->pluck('invoice_tax_share')->all());
    }

    public function test_tax_is_entered_once_and_never_below_zero(): void
    {
        $panadol = $this->medicine();
        $supplier = $this->supplier();
        $line = fn (int $tax = 0, int $cost = 1_000): ReceiptLine => new ReceiptLine($panadol->getKey(), 10, unitCost: $cost, batchNo: 'A', expiry: '12/2027', tax: $tax);

        $this->refused(fn () => $this->draftReceipt($supplier, [$line(tax: 1_800)], invoiceTax: 1_800), 'on the lines or for the whole invoice, not both');
        $this->refused(fn () => $this->draftReceipt($supplier, [$line(tax: -1)]), 'below zero');
        $this->refused(fn () => $this->draftReceipt($supplier, [$line()], invoiceTax: -1), 'below zero');
        // Goods that came free have nothing to put an invoice's tax on.
        $this->refused(fn () => $this->draftReceipt($supplier, [$line(cost: 0)], invoiceTax: 500), 'lines with a cost');

        // Tax on a free line is still what the goods cost.
        $draft = $this->draftReceipt($supplier, [$line(tax: 500, cost: 0)]);
        $this->assertSame([500, 500], [$draft->items->sole()->line_total, $draft->total]);
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_lines_are_checked_when_saved(): void
    {
        $panadol = $this->medicine();
        $supplier = $this->supplier();
        $box = $this->boxOf($panadol);

        $this->refused(fn () => $this->draftReceipt($supplier, [new ReceiptLine($panadol->getKey(), 0.5, $box, unitCost: 9_000)]), 'whole units only');
        $this->refused(fn () => $this->draftReceipt($supplier, [new ReceiptLine($panadol->getKey(), 1, $box, freeQuantity: 0.5, unitCost: 9_000)]), 'whole units only');
        $this->refused(fn () => $this->draftReceipt($supplier, [new ReceiptLine($panadol->getKey(), 0, $box)]), 'how many');
        $this->refused(fn () => $this->draftReceipt($supplier, [new ReceiptLine($panadol->getKey(), 1, $box, unitCost: 9_000, discount: 9_001)]), 'more than the line is worth');
        $this->refused(fn () => $this->draftReceipt($supplier, [new ReceiptLine($panadol->getKey(), 1, $box, unitCost: 9_000)], invoiceDiscount: 9_001), 'more than the lines are worth');
        $this->refused(fn () => $this->draftReceipt($supplier, [new ReceiptLine($panadol->getKey(), 1, $box, expiry: 'soon')]), 'is not an expiry date');
        $this->refused(fn () => $this->draftReceipt($supplier, []), 'at least one item');

        $dated = fn (string $receivedOn) => $this->grns()->saveDraft(null, new GoodsReceiptData($supplier->getKey(), $this->general->getKey(), [new ReceiptLine($panadol->getKey(), 1, $box)], receivedOn: $receivedOn), $this->owner);
        $this->refused(fn () => $dated('31/02/2026'), '"31/02/2026" is not a date');
        $this->refused(fn () => $dated('next week'), 'is not a date');
        $this->refused(fn () => $dated('2026-10-19'), 'can\'t be in the future');
        $this->assertSame('2026-10-01', $dated('01/10/2026')->received_on->toDateString());
        $this->grns()->deleteDraft(GoodsReceipt::query()->sole(), $this->owner);

        // A line that is all free goods costs nothing and is allowed.
        $draft = $this->draftReceipt($supplier, [new ReceiptLine($panadol->getKey(), 0, $box, freeQuantity: 1, batchNo: 'F1', expiry: '12/2027')]);
        $this->assertSame([0, 10.0, '0.0000'], [$draft->items->sole()->line_total, (float) $draft->items->sole()->base_quantity, $draft->items->sole()->base_unit_cost]);

        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_posting_needs_the_batch_and_a_good_expiry_and_is_all_or_nothing(): void
    {
        $panadol = $this->medicine();
        $amoxil = $this->medicine('Amoxil 250 mg Capsules');
        $supplier = $this->supplier();

        $this->refused(fn () => $this->postReceipt($supplier, [new ReceiptLine($panadol->getKey(), 10, unitCost: 1_000)]), 'enter the batch number and expiry');
        $this->refused(fn () => $this->postReceipt($supplier, [new ReceiptLine($panadol->getKey(), 10, unitCost: 1_000, batchNo: 'OLD', expiry: '09/2026')]), 'expired on 30/09/2026');

        // One good line and one bad one: nothing is received.
        $draft = $this->draftReceipt($supplier, [
            new ReceiptLine($amoxil->getKey(), 10, unitCost: 1_000, batchNo: 'GOOD', expiry: '12/2027'),
            new ReceiptLine($panadol->getKey(), 10, unitCost: 1_000, batchNo: 'X', expiry: '12/2027'),
            new ReceiptLine($panadol->getKey(), 10, unitCost: 1_000, batchNo: 'X', expiry: '06/2028'),
        ]);
        $this->refused(fn () => $this->grns()->post($draft, $this->owner), 'already recorded with expiry 31/12/2027');

        $this->assertSame(GoodsReceiptStatus::Draft, $draft->fresh()?->status);
        $this->assertNull($draft->fresh()?->grn_number);
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(0, StockBatch::query()->count());
    }

    public function test_short_expiry_needs_ticking_and_the_owner(): void
    {
        $panadol = $this->medicine();
        $supplier = $this->supplier();
        $line = fn (bool $accept): ReceiptLine => new ReceiptLine($panadol->getKey(), 10, unitCost: 1_000, batchNo: 'SD1', expiry: '12/2026', acceptShortExpiry: $accept);

        $this->refused(fn () => $this->postReceipt($supplier, [$line(false)]), 'Tick "Accept short expiry"');

        // Someone who may receive goods but not accept short expiry (a future pharmacist role).
        $receiver = $this->manager(['purchasing.receive']);
        $this->refused(fn () => $this->postReceipt($supplier, [$line(true)], user: $receiver), 'Only the owner can accept');
        $this->assertSame(0, StockMovement::query()->count());

        $this->postReceipt($supplier, [$line(true)]);
        $this->assertSame(['SD1' => 10.0], $this->batchQuantities($panadol));
    }

    public function test_receiving_settles_stock_sold_beyond_the_batches(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'A', '12/2027', 2);
        $this->sell([new CartLine($panadol->getKey(), 5, tillUnitPrice: 2_000)], offline: true);
        $this->assertSame(-3.0, (float) $this->unallocated($panadol)?->quantity);

        $this->postReceipt($this->supplier(), [new ReceiptLine($panadol->getKey(), 1, $this->boxOf($panadol), unitCost: 9_000, batchNo: 'B', expiry: '12/2027')]);

        // The 3 sold beyond the batches are attributed to the batch that arrived.
        $this->assertSame(['B' => 7.0], $this->batchQuantities($panadol));
        $this->assertSame(0.0, (float) $this->unallocated($panadol)?->quantity);
        $this->assertSame(7.0, $panadol->stockAt($this->general));
        $this->assertLedgerMatches();
    }

    public function test_a_posted_grn_never_changes(): void
    {
        $panadol = $this->medicine();
        $grn = $this->postReceipt($this->supplier(), [new ReceiptLine($panadol->getKey(), 10, unitCost: 1_000, batchNo: 'A', expiry: '12/2027')]);

        $this->refused(fn () => $this->grns()->saveDraft($grn, new GoodsReceiptData($grn->supplier_id, $grn->location_id, [new ReceiptLine($panadol->getKey(), 1)]), $this->owner), 'has been posted');
        $this->refused(fn () => $this->grns()->deleteDraft($grn, $this->owner), 'has been posted');
        $this->refused(fn () => $this->grns()->post($grn, $this->owner), 'has been posted');

        foreach ([fn () => $grn->update(['notes' => 'edited']), fn () => $grn->delete(), fn () => $grn->items->sole()->update(['unit_cost' => 1]), fn () => $grn->items->sole()->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('A posted GRN changed.');
            } catch (\LogicException) {
                // expected
            }
        }

        $this->assertSame(1_000, $grn->items->sole()->fresh()?->unit_cost);
    }

    public function test_a_draft_can_be_edited_and_deleted(): void
    {
        $panadol = $this->medicine();
        $supplier = $this->supplier();
        $draft = $this->draftReceipt($supplier, [new ReceiptLine($panadol->getKey(), 10, unitCost: 1_000)]);

        $edited = $this->grns()->saveDraft($draft, new GoodsReceiptData($supplier->getKey(), $this->doctors->getKey(), [
            new ReceiptLine($panadol->getKey(), 2, $this->boxOf($panadol), unitCost: 9_500, batchNo: 'E1', expiry: '2028-02'),
        ], supplierInvoiceNo: ' INV-9 '), $this->owner);

        $this->assertSame([$this->doctors->getKey(), 'INV-9', 19_000], [$edited->location_id, $edited->supplier_invoice_no, $edited->total]);
        $this->assertSame(['Box', '2028-02-29'], [$edited->items->sole()->unit_name, $edited->items->sole()->expiry_date?->toDateString()]);

        $this->grns()->deleteDraft($edited, $this->owner);
        $this->assertSame(0, GoodsReceipt::query()->count());
    }

    public function test_the_same_supplier_invoice_is_entered_once_unless_reversed(): void
    {
        $panadol = $this->medicine();
        $supplier = $this->supplier();
        $line = fn (string $batch): ReceiptLine => new ReceiptLine($panadol->getKey(), 10, unitCost: 1_000, batchNo: $batch, expiry: '12/2027');

        $first = $this->postReceipt($supplier, [$line('A')], invoiceNo: 'INV-1');
        $again = $this->draftReceipt($supplier, [$line('B')], invoiceNo: 'INV-1');
        $this->refused(fn () => $this->grns()->post($again, $this->owner), 'Invoice INV-1 from Hemas Pharmaceuticals is already entered on GRN-000001');

        // Another supplier's invoice may carry the same number.
        $this->postReceipt($this->supplier('State Pharmaceuticals'), [$line('C')], invoiceNo: 'INV-1');

        $this->grns()->reverse($first, 'Wrong quantities', $this->owner);
        $this->assertSame('GRN-000004', $this->grns()->post($again, $this->owner)->grn_number);
    }

    public function test_a_reversing_grn_takes_the_receipt_back_out_and_restores_the_average(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'A', '12/2027', 30, cost: 1_000);
        $grn = $this->postReceipt($this->supplier(), [new ReceiptLine($panadol->getKey(), 10, $this->boxOf($panadol), freeQuantity: 2, unitCost: 9_000, discount: 4_500, batchNo: 'PN-7', expiry: '12/2027')]);
        $this->assertSame('770.0000', app(StockService::class)->averageCost($panadol, $this->general));

        $reversal = $this->grns()->reverse($grn, 'Typed the wrong cost', $this->owner);

        $this->assertSame(['GRN-000002', $grn->getKey(), GoodsReceiptStatus::Posted, -85_500, 'Typed the wrong cost'], [$reversal->grn_number, $reversal->reversal_of_id, $reversal->status, $reversal->total, $reversal->notes]);
        $this->assertTrue($grn->fresh()?->reversal?->is($reversal));

        $line = $reversal->items->sole();
        $this->assertSame([-10.0, -2.0, -120.0, -85_500, '712.5000'], [(float) $line->quantity, (float) $line->free_quantity, (float) $line->base_quantity, $line->line_total, $line->base_unit_cost]);

        $movement = StockMovement::query()->findOrFail($line->stock_movement_id);
        $this->assertSame([StockMovementType::ReceiptReversal, -120.0, '712.5000'], [$movement->type, (float) $movement->quantity, $movement->received_cost]);
        $this->assertTrue($movement->reference()->firstOrFail()->is($reversal));
        $this->assertStringContainsString('Reverses GRN-000001: Typed the wrong cost', (string) $movement->note);

        // Back exactly where it was before the GRN.
        $this->assertSame(['A' => 30.0], $this->batchQuantities($panadol));
        $this->assertSame('1000.0000', app(StockService::class)->averageCost($panadol, $this->general));
        $this->assertSame(1_000, $panadol->fresh()?->cost_price);
        $this->assertLedgerMatches();

        // The cost rebuild replays the reversal exactly.
        $this->assertSame([], app(StockService::class)->rebuildCosts());
        $this->assertSame(0, Artisan::call('pos:stock-rebuild', ['--costs' => true]));

        $this->refused(fn () => $this->grns()->reverse($grn, 'Again', $this->owner), 'already been reversed');
        $this->refused(fn () => $this->grns()->reverse($reversal, 'Undo', $this->owner), 'itself a reversal');
        $this->refused(fn () => $this->grns()->reverse($grn, ' ', $this->owner), 'Say why');
    }

    public function test_stock_sold_since_the_receipt_cant_be_reversed(): void
    {
        $panadol = $this->medicine();
        $grn = $this->postReceipt($this->supplier(), [new ReceiptLine($panadol->getKey(), 1, $this->boxOf($panadol), unitCost: 9_000, batchNo: 'B', expiry: '12/2027')]);
        $this->sell([new CartLine($panadol->getKey(), 1)]);

        $this->refused(fn () => $this->grns()->reverse($grn, 'Wrong item', $this->owner), '10 Tablet were received in batch B · exp 12/2027, but only 9 is left at General Pharmacy');

        $this->assertSame(1, GoodsReceipt::query()->count());
        $this->assertSame(['B' => 9.0], $this->batchQuantities($panadol));
    }

    public function test_who_may_receive_post_and_reverse(): void
    {
        $panadol = $this->medicine();
        $supplier = $this->supplier();
        $line = new ReceiptLine($panadol->getKey(), 10, unitCost: 1_000, batchNo: 'A', expiry: '12/2027');

        $this->assertNotContains('purchasing.receive', config('pos.roles.cashier'));
        $this->refused(fn () => $this->draftReceipt($supplier, [$line], user: $this->cashier), 'permission');

        $draft = $this->draftReceipt($supplier, [$line]);
        $this->refused(fn () => $this->grns()->post($draft, $this->cashier), 'permission');

        // Receiving is a separate permission from managing purchasing (a future pharmacist role).
        $receiver = $this->manager(['purchasing.receive']);
        $grn = $this->grns()->post($draft, $receiver);
        $this->assertSame($receiver->getKey(), $grn->posted_by);
        $this->refused(fn () => $this->grns()->reverse($grn, 'Mistake', $receiver), 'permission');

        $this->assertTrue($this->owner->can('purchasing.receive') && $this->owner->can('purchasing.manage'));
    }

    public function test_a_service_or_inactive_supplier_cant_be_received(): void
    {
        $supplier = $this->supplier();
        $consultation = app(ProductCatalogService::class)->create(['name' => 'Consultation', 'unit' => 'Visit', 'track_stock' => false], [['price' => 150_000]], user: $this->owner)->variants()->firstOrFail();

        $this->refused(fn () => $this->draftReceipt($supplier, [new ReceiptLine($consultation->getKey(), 1)]), 'is a service');

        app(SupplierService::class)->update($supplier, ['is_active' => false], $this->owner);
        $this->refused(fn () => $this->draftReceipt($supplier->fresh() ?? $supplier, [new ReceiptLine($this->medicine()->getKey(), 1)]), 'not an active supplier');
    }

    public function test_the_local_demo_clinic_purchasing_goes_through_the_services(): void
    {
        $this->seed(DemoClinicStockSeeder::class);
        $this->seed(DemoClinicPurchasingSeeder::class);
        $this->seed(DemoClinicPurchasingSeeder::class); // once per clinic

        $hemas = Supplier::query()->where('name', 'Hemas Pharmaceuticals')->sole();
        $order = PurchaseOrder::query()->sole();
        $this->assertSame([PurchaseOrderStatus::PartiallyReceived, 2], [$order->status, Supplier::query()->count()]);
        $this->assertSame([GoodsReceiptStatus::Posted, GoodsReceiptStatus::Draft, GoodsReceiptStatus::Posted], GoodsReceipt::query()->orderBy('id')->pluck('status')->all());

        $amoxil = ProductVariant::query()->whereHas('product', fn ($p) => $p->where('name', 'Amoxil 250 mg Capsules'))->firstOrFail();
        $this->assertSame(['AM1188' => 100.0, 'AM1290' => 220.0], $this->batchQuantities($amoxil));

        // 3 boxes at 950.00 + 20 strips at 165.00, less 50.00, and 4 boxes on the older invoice; 20 expired capsules back at 16.00; 2,000.00 paid.
        $this->assertSame(['opening' => 0, 'purchases' => 990_000, 'returns' => 32_000, 'payments' => 200_000, 'balance' => 758_000], app(SupplierService::class)->balance($hemas));
        $spc = Supplier::query()->where('name', 'State Pharmaceuticals Corporation')->sole();
        $this->assertSame(['opening' => 1_590_000, 'purchases' => 0, 'returns' => 0, 'payments' => 0, 'balance' => 1_590_000], app(SupplierService::class)->balance($spc));

        // Payables: the older Hemas invoice is overdue and part paid, the return is credit to allocate;
        // SPC's go-live lump sum is due on its as-of date + 45 days' terms, its opening invoice this week.
        $payables = app(SupplierPayablesService::class);
        $old = SupplierPayable::query()->where('invoice_no', 'HPL-54980')->sole();
        $this->assertSame([PayableStatus::PartPaid, 180_000, '2026-09-28'], [$old->status, $old->outstanding(), $old->due_on->toDateString()]);
        $this->assertSame(['outstanding' => 790_000, 'overdue' => 180_000, 'credit' => 32_000], $payables->summary((int) $hemas->getKey()));
        $this->assertSame('2026-10-21', SupplierPayable::query()->where('invoice_no', 'SPC-88012')->sole()->due_on->toDateString());
        $this->assertSame('2026-11-18', SupplierPayable::query()->whereNull('invoice_no')->where('supplier_id', $spc->getKey())->sole()->due_on->toDateString());
        $this->assertSame(['count' => 1, 'amount' => 340_000], $payables->dueSoon()['due_this_week']);
        $this->assertSame(['count' => 1, 'amount' => 180_000], $payables->dueSoon()['overdue']);
        $this->assertSame([0, 0], [app(SupplierService::class)->reconcile($hemas)['difference'], app(SupplierService::class)->reconcile($spc)['difference']]);
        $this->assertLedgerMatches();
        $this->assertSame([], app(StockService::class)->rebuildCosts());
    }

    public function test_what_purchases_by_supplier_and_month_need_is_stored(): void
    {
        $panadol = $this->medicine();
        $hemas = $this->supplier();
        $spc = $this->supplier('State Pharmaceuticals');
        $line = fn (string $batch, int $cost): ReceiptLine => new ReceiptLine($panadol->getKey(), 10, unitCost: $cost, batchNo: $batch, expiry: '12/2027');

        $september = $this->grns()->post($this->grns()->saveDraft(null, new GoodsReceiptData($hemas->getKey(), $this->general->getKey(), [$line('A', 1_000)], receivedOn: '2026-09-30'), $this->owner), $this->owner);
        $this->postReceipt($hemas, [$line('B', 1_100)]);
        $this->postReceipt($spc, [$line('C', 900)]);
        $this->grns()->reverse($september, 'Entered twice', $this->owner);
        $this->draftReceipt($spc, [$line('D', 5_000)]); // drafts are not purchases

        $byMonth = GoodsReceipt::query()->with('supplier')->where('status', GoodsReceiptStatus::Posted)->get()
            ->groupBy(fn (GoodsReceipt $grn): string => $grn->supplier->name.' '.$grn->received_on->format('Y-m'))
            ->map(fn ($grns): int => (int) $grns->sum('total'))
            ->sortKeys()
            ->all();

        // The reversal counts in the month it was made, against the supplier it reverses.
        $this->assertSame([
            'Hemas Pharmaceuticals 2026-09' => 10_000,
            'Hemas Pharmaceuticals 2026-10' => 11_000 - 10_000,
            'State Pharmaceuticals 2026-10' => 9_000,
        ], $byMonth);
    }
}
