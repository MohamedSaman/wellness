<?php

namespace Tests\Feature\Pos;

use App\Enums\BillType;
use App\Enums\DrugSchedule;
use App\Enums\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use App\Services\ReceiptService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * The receipt of a pharmacy sale (docs/PHARMACY.md §9, branches 8 and 9):
 * an OPD bill prints the consultation and one Medicines amount, listing the
 * medicines by name or not as the shop says; Rx lines carry the prescriber,
 * bank transfers their reference, and batch lines the batches as sold.
 */
class OpdReceiptTest extends BatchTestCase
{
    use WorksWithCheckout;

    private ProductVariant $consultationFee;

    private ProductVariant $paracetamol;

    private ProductVariant $panadol;

    private User $opdCashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consultationFee = $this->consultation();
        $this->paracetamol = $this->syrup();
        $this->panadol = $this->medicine();
        $this->receive($this->paracetamol, 'PS1', '06/2027', 600, $this->doctors);
        $this->receive($this->panadol, 'P1', '01/2027', 4, $this->doctors);
        $this->receive($this->panadol, 'P2', '03/2027', 50, $this->doctors);
        $this->opdCashier = $this->cashierAt($this->doctors);
    }

    private function bottleOf(ProductVariant $variant): int
    {
        return (int) $variant->units()->where('name', 'Bottle')->value('id');
    }

    /**
     * Consultation (LKR 1,500) + 2 bottles of syrup (list 2 x 250) + 10 tablets
     * (list 10 x 20) at the OPD counter.
     *
     * @return list<CartLine>
     */
    private function opdLines(): array
    {
        return [
            new CartLine($this->consultationFee->getKey(), 1),
            new CartLine($this->paracetamol->getKey(), 2, unitId: $this->bottleOf($this->paracetamol)),
            new CartLine($this->panadol->getKey(), 10),
        ];
    }

    /**
     * An OPD bill for a Medicines amount of LKR 350, stored as CheckoutService
     * stores one (contract §5.6): rung up as a bill, then its medicine lines
     * made OPD lines carrying their shares (weights 500 : 200 => 250 + 100).
     * Built by hand so the receipt is tested apart from the OPD checkout.
     */
    private function opdSale(): Sale
    {
        $sale = app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->docTill(),
            cashier: $this->opdCashier,
            lines: $this->opdLines(),
            payments: [new PaymentInput(PaymentMethod::Cash, 300_000)],
        ));

        [$fee, $syrup, $tablets] = $sale->items->all();
        $syrup->forceFill(['list_price' => 25_000, 'is_opd_medicine' => true, 'line_total' => 25_000])->save();
        $tablets->forceFill(['list_price' => 2_000, 'is_opd_medicine' => true, 'line_total' => 10_000])->save();
        $sale->forceFill([
            'bill_type' => BillType::Opd,
            'opd_medicines_amount' => 35_000,
            'subtotal' => $fee->line_total + 35_000,
            'grand_total' => $fee->line_total + 35_000,
            'change_due' => 300_000 - $fee->line_total - 35_000,
        ])->save();

        return $sale->fresh() ?? $sale;
    }

    public function test_an_opd_bill_prints_one_medicines_amount_and_lists_the_medicines_by_name(): void
    {
        $receipt = app(ReceiptService::class)->sale($this->opdSale());

        $this->assertSame('opd', $receipt['billType']);
        $this->assertSame('names', $receipt['opdReceiptMedicines']);
        $this->assertSame(35_000, $receipt['medicinesTotal']);
        $this->assertSame(185_000, $receipt['grandTotal']);
        $this->assertSame([false, true, true], array_column($receipt['items'], 'isOpdMedicine'));
        $this->assertSame(['Visit', 'Bottle', 'Tablet'], array_column($receipt['items'], 'unitName'));
        // A medicine's own share is never printed (nor sent): only the Medicines amount.
        $this->assertSame([150_000, null, null], array_column($receipt['items'], 'lineTotal'));
        $this->assertSame([150_000, null, null], array_column($receipt['items'], 'unitPrice'));
        $this->assertSame([0, null, null], array_column($receipt['items'], 'discount'));
    }

    public function test_an_opd_bill_prints_totals_only_when_the_shop_says_so(): void
    {
        $this->setShopSetting('opd_receipt_medicines', 'totals');
        $sale = $this->opdSale();

        $this->actingAs($this->opdCashier)->get(route('pos.receipt', $sale))->assertInertia(fn (Assert $page) => $page
            ->component('pos/Receipt')
            ->where('sale.billType', 'opd')
            ->where('sale.opdReceiptMedicines', 'totals')
            ->where('sale.medicinesTotal', 35_000)
            ->where('sale.items.1.isOpdMedicine', true));

        // The setting is read when printing: switching back lists the names again, also for this bill.
        $this->setShopSetting('opd_receipt_medicines', 'names');
        $this->assertSame('names', app(ReceiptService::class)->sale($sale)['opdReceiptMedicines']);
    }

    public function test_a_standard_bill_has_no_medicines_total(): void
    {
        $this->receive($this->panadol, 'G1', '05/2027', 30);
        $sale = $this->sell([new CartLine($this->panadol->getKey(), 1, unitId: $this->boxOf($this->panadol))], cashier: $this->owner);
        $receipt = app(ReceiptService::class)->sale($sale);

        $this->assertSame('standard', $receipt['billType']);
        $this->assertNull($receipt['medicinesTotal']);
        $this->assertSame('Box', $receipt['items'][0]['unitName']);
        $this->assertFalse($receipt['items'][0]['isOpdMedicine']);
        $this->assertNull($receipt['items'][0]['prescriber']);
        $this->assertNull($receipt['items'][0]['prescriptionRef']);
    }

    public function test_an_rx_line_prints_its_prescriber_and_prescription_reference(): void
    {
        $amoxicillin = $this->medicine('Amoxicillin 500 mg Capsules', schedule: DrugSchedule::PrescriptionOnly);
        $this->receive($amoxicillin, 'AX1', '05/2027', 30);
        $this->receive($this->panadol, 'G1', '05/2027', 30);

        $sale = $this->sell([
            new CartLine($amoxicillin->getKey(), 15, prescriber: '  Dr. S. Perera ', prescriptionRef: 'RX-1042'),
            new CartLine($this->panadol->getKey(), 2),
        ], cashier: $this->owner);

        $items = app(ReceiptService::class)->sale($sale)['items'];

        $this->assertSame('Dr. S. Perera', $items[0]['prescriber']);
        $this->assertSame('RX-1042', $items[0]['prescriptionRef']);
        $this->assertNull($items[1]['prescriber']);
        $this->assertNull($items[1]['prescriptionRef']);
    }

    public function test_a_bank_transfer_prints_its_reference(): void
    {
        $this->receive($this->panadol, 'G1', '05/2027', 30);

        $sale = app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->genTill(),
            cashier: $this->owner,
            lines: [new CartLine($this->panadol->getKey(), 1, unitId: $this->boxOf($this->panadol))],
            payments: [
                new PaymentInput(PaymentMethod::BankTransfer, 10_000, 'TRX-889'),
                new PaymentInput(PaymentMethod::Cash, 8_000),
            ],
        ));

        $payments = collect(app(ReceiptService::class)->sale($sale)['payments'])->keyBy('method');

        $this->assertSame(['method' => 'Bank Transfer', 'amount' => 10_000, 'reference' => 'TRX-889'], $payments['Bank Transfer']);
        $this->assertSame(['method' => 'Cash', 'amount' => 8_000, 'reference' => null], $payments['Cash']);
    }

    public function test_a_reprinted_opd_bill_shows_the_batches_as_sold(): void
    {
        $sale = $this->opdSale();
        $till = $this->docTill();

        // A later correction of a batch never changes what the duplicate says.
        DB::table('stock_batches')->where('batch_no', 'P1')->update(['batch_no' => 'P1-FIXED', 'expiry_date' => '2027-12-31']);

        $url = $this->actingAs($this->opdCashier)
            ->postJson(route('pos.receipt.reprint', $sale), ['device' => $till->uuid])
            ->assertOk()
            ->json('receipt_url');

        $this->get($url)->assertInertia(fn (Assert $page) => $page
            ->where('sale.billType', 'opd')
            ->where('sale.medicinesTotal', 35_000)
            ->where('sale.reprint.printedBy', $this->opdCashier->name)
            ->where('sale.items.1.batches', [['batchNo' => 'PS1', 'expiry' => '06/2027', 'quantity' => '120']])
            ->where('sale.items.2.batches', [
                ['batchNo' => 'P1', 'expiry' => '01/2027', 'quantity' => '4'],
                ['batchNo' => 'P2', 'expiry' => '03/2027', 'quantity' => '6'],
            ])
            ->missing('sale.items.0.batches'));
    }

    /**
     * End to end through CheckoutService's OPD bill (contract §5.6, built in
     * another track of this branch): the receipt's Medicines amount is the
     * amount typed, spread over the medicine lines by list price.
     */
    public function test_an_opd_bill_rung_up_at_the_opd_counter_prints_the_amount_typed(): void
    {
        $sale = app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->docTill(),
            cashier: $this->opdCashier,
            lines: $this->opdLines(),
            payments: [new PaymentInput(PaymentMethod::Cash, 200_000)],
            billType: BillType::Opd,
            opdMedicinesAmount: 35_000,
        ));

        $receipt = app(ReceiptService::class)->sale($sale);

        $this->assertSame('opd', $receipt['billType']);
        $this->assertSame(35_000, $receipt['medicinesTotal']);
        $this->assertSame(185_000, $receipt['grandTotal']);
        $this->assertSame([false, true, true], array_column($receipt['items'], 'isOpdMedicine'));
        $this->assertSame([150_000, null, null], array_column($receipt['items'], 'lineTotal'));
        $this->assertSame(35_000, (int) $sale->items->where('is_opd_medicine', true)->sum(fn (SaleItem $item): int => $item->line_total));
    }
}
