<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Services\CheckoutService;
use App\Services\Receipts\ReceiptLayout;
use App\Services\ReceiptService;
use App\Services\ReturnService;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithReceipts;

/**
 * The printed receipts of the clinic (docs/PHARMACY.md §9, branch 9), line
 * for line, at 48 columns (80 mm) and 32 (58 mm): a General Pharmacy bill,
 * an OPD bill with its medicines by name or totals only, a refund slip, a
 * void slip and a reprint. The doctor wants no expiry date on any of them
 * (2026-10-21): hidden by default, printed when the shop turns it on.
 */
class ReceiptLayoutTest extends BatchTestCase
{
    use WorksWithCheckout, WorksWithReceipts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stockTheClinic();
    }

    private function receipts(): ReceiptService
    {
        return app(ReceiptService::class);
    }

    /** 10 tablets back from the box of 30, refunded by bank transfer. */
    private function tabletsBack(Sale $sale): SaleReturn
    {
        $box = $sale->items->firstWhere('sku', $this->panadol->sku);

        return app(ReturnService::class)->process($sale, [$box->getKey() => 10], ReturnReason::ChangedMind, PaymentMethod::BankTransfer, $this->owner, inBaseUnits: true, refundReference: 'BOC 449001');
    }

    private function voided(): Sale
    {
        $sale = $this->billAtGeneral();
        app(CheckoutService::class)->void($sale, $this->owner, 'Rang up twice');

        return $sale->fresh() ?? $sale;
    }

    public function test_a_general_pharmacy_bill_at_80_mm(): void
    {
        $this->assertSame(<<<'TXT'
                              DEMO CLINIC
                        45 Hospital Road, Kandy
                          Tel +94 81 000 0000
            ================================================
            Bill no.                               T1-000001
            Date                            18/10/2026 10:00
            Till                            T1 Front counter
            Location                        General Pharmacy
            Cashier                           Clinic Cashier
            ------------------------------------------------
            Panadol Tablets 500 mg
              1 Box of 30 x 540.00                    540.00
              Batch PN2611
            Amoxil Capsules 250 mg
              15 Capsule x 35.00                      525.00
              Batch AX0921 x 5 Capsule
              Batch AX1102 x 10 Capsule
              Prescriber: Dr. S. Perera
              Rx ref: RX-1042
            ------------------------------------------------
            TOTAL LKR                               1,065.00
            ------------------------------------------------
            Bank Transfer                           1,065.00
              Ref BOC 448812
            Change                                      0.00
            ------------------------------------------------
            Get well soon. Medicines are not returnable once
                               dispensed.
            TXT, $this->printed('sale', $this->receipts()->sale($this->billAtGeneral())));
    }

    public function test_a_general_pharmacy_bill_at_58_mm(): void
    {
        $this->assertSame(<<<'TXT'
                      DEMO CLINIC
                45 Hospital Road, Kandy
                  Tel +94 81 000 0000
            ================================
            Bill no.               T1-000001
            Date            18/10/2026 10:00
            Till            T1 Front counter
            Location        General Pharmacy
            Cashier           Clinic Cashier
            --------------------------------
            Panadol Tablets 500 mg
              1 Box of 30 x 540.00    540.00
              Batch PN2611
            Amoxil Capsules 250 mg
              15 Capsule x 35.00      525.00
              Batch AX0921 x 5 Capsule
              Batch AX1102 x 10 Capsule
              Prescriber: Dr. S. Perera
              Rx ref: RX-1042
            --------------------------------
            TOTAL LKR               1,065.00
            --------------------------------
            Bank Transfer           1,065.00
              Ref BOC 448812
            Change                      0.00
            --------------------------------
            Get well soon. Medicines are not
               returnable once dispensed.
            TXT, $this->printed('sale', $this->receipts()->sale($this->billAtGeneral()), 58));
    }

    public function test_the_expiry_prints_only_when_the_shop_turns_it_on(): void
    {
        $sale = $this->billAtGeneral();

        $this->assertStringNotContainsString('Exp ', $this->printed('sale', $this->receipts()->sale($sale)));

        $this->setShopSetting('receipt_show_expiry', true);

        $this->assertStringContainsString(<<<'TXT'
            Panadol Tablets 500 mg
              1 Box of 30 x 540.00                    540.00
              Batch PN2611 Exp 03/2027
            Amoxil Capsules 250 mg
              15 Capsule x 35.00                      525.00
              Batch AX0921 Exp 11/2026 x 5 Capsule
              Batch AX1102 Exp 02/2027 x 10 Capsule
            TXT, $this->printed('sale', $this->receipts()->sale($sale)));

        // 32 columns: a batch line that does not fit wraps under itself.
        $this->assertStringContainsString(<<<'TXT'
              Batch AX0921 Exp 11/2026 x 5
                Capsule
            TXT, $this->printed('sale', $this->receipts()->sale($sale), 58));
    }

    public function test_batch_and_prescriber_have_their_own_settings(): void
    {
        $sale = $this->billAtGeneral();
        $this->setShopSetting('receipt_show_batch', false);
        $this->setShopSetting('receipt_show_prescriber', false);

        $printed = $this->printed('sale', $this->receipts()->sale($sale));

        $this->assertStringNotContainsString('Batch', $printed);
        $this->assertStringNotContainsString('Perera', $printed);
        $this->assertStringNotContainsString('RX-1042', $printed);

        // Expiry alone, without the batch number.
        $this->setShopSetting('receipt_show_expiry', true);
        $this->assertStringContainsString("  Exp 11/2026 x 5 Capsule\n  Exp 02/2027 x 10 Capsule", $this->printed('sale', $this->receipts()->sale($sale)));
    }

    public function test_an_opd_bill_lists_the_medicines_by_name_without_prices(): void
    {
        $sale = $this->opdBill();

        $this->assertSame(<<<'TXT'
                              DEMO CLINIC
                        45 Hospital Road, Kandy
                          Tel +94 81 000 0000
            ================================================
            Bill no.                               T2-000001
            Date                            18/10/2026 10:00
            Till                              T2 OPD counter
            Location                      Doctor's Medicines
            Cashier                           Cashier at DOC
            ------------------------------------------------
            Consultation
              1 Visit x 1,500.00                    1,500.00
            Medicines                                 850.00
              Paracetamol Syrup
                1 Bottle
                Batch PS0426
                Prescriber: Dr. S. Perera
              Amoxil Capsules 250 mg
                10 Capsule
                Batch AX1102
                Prescriber: Dr. S. Perera
            ------------------------------------------------
            TOTAL LKR                               2,350.00
            ------------------------------------------------
            Cash                                    3,000.00
            Change                                    650.00
            ------------------------------------------------
            Get well soon. Medicines are not returnable once
                               dispensed.
            TXT, $this->printed('sale', $this->receipts()->sale($sale)));

        $this->assertSame(<<<'TXT'
                      DEMO CLINIC
                45 Hospital Road, Kandy
                  Tel +94 81 000 0000
            ================================
            Bill no.               T2-000001
            Date            18/10/2026 10:00
            Till              T2 OPD counter
            Location      Doctor's Medicines
            Cashier           Cashier at DOC
            --------------------------------
            Consultation
              1 Visit x 1,500.00    1,500.00
            Medicines                 850.00
              Paracetamol Syrup
                1 Bottle
                Batch PS0426
                Prescriber: Dr. S. Perera
              Amoxil Capsules 250 mg
                10 Capsule
                Batch AX1102
                Prescriber: Dr. S. Perera
            --------------------------------
            TOTAL LKR               2,350.00
            --------------------------------
            Cash                    3,000.00
            Change                    650.00
            --------------------------------
            Get well soon. Medicines are not
               returnable once dispensed.
            TXT, $this->printed('sale', $this->receipts()->sale($sale), 58));
    }

    public function test_an_opd_bill_prints_totals_only_when_the_shop_says_so(): void
    {
        $sale = $this->opdBill();
        $this->setShopSetting('opd_receipt_medicines', 'totals');

        $this->assertStringContainsString(<<<'TXT'
            ------------------------------------------------
            Consultation
              1 Visit x 1,500.00                    1,500.00
            Medicines                                 850.00
            ------------------------------------------------
            TOTAL LKR                               2,350.00
            TXT, $this->printed('sale', $this->receipts()->sale($sale)));

        $this->assertStringContainsString(<<<'TXT'
            --------------------------------
            Consultation
              1 Visit x 1,500.00    1,500.00
            Medicines                 850.00
            --------------------------------
            TOTAL LKR               2,350.00
            TXT, $this->printed('sale', $this->receipts()->sale($sale), 58));
    }

    public function test_a_refund_slip(): void
    {
        $return = $this->tabletsBack($this->billAtGeneral());

        $this->assertSame(<<<'TXT'
                              DEMO CLINIC
                        45 Hospital Road, Kandy
                          Tel +94 81 000 0000
            ================================================
                             *** REFUND ***
            Refund no.                          T1-000001-R1
            Date                            18/10/2026 10:00
            Bill no.                               T1-000001
            Bill date                       18/10/2026 10:00
            Till                            T1 Front counter
            Location                        General Pharmacy
            Cashier                           Clinic Cashier
            Refunded by                         Clinic Owner
            ------------------------------------------------
            Panadol Tablets 500 mg
              10 Tablet                               180.00
              Batch PN2611
            ------------------------------------------------
            REFUND TOTAL LKR                          180.00
            ------------------------------------------------
            Bank Transfer                             180.00
              Ref BOC 449001
            Reason: Customer changed mind
            ------------------------------------------------
            Get well soon. Medicines are not returnable once
                               dispensed.
            TXT, $this->printed('refund', $this->receipts()->refund($return)));

        $this->assertSame(<<<'TXT'
                      DEMO CLINIC
                45 Hospital Road, Kandy
                  Tel +94 81 000 0000
            ================================
                     *** REFUND ***
            Refund no.          T1-000001-R1
            Date            18/10/2026 10:00
            Bill no.               T1-000001
            Bill date       18/10/2026 10:00
            Till            T1 Front counter
            Location        General Pharmacy
            Cashier           Clinic Cashier
            Refunded by         Clinic Owner
            --------------------------------
            Panadol Tablets 500 mg
              10 Tablet               180.00
              Batch PN2611
            --------------------------------
            REFUND TOTAL LKR          180.00
            --------------------------------
            Bank Transfer             180.00
              Ref BOC 449001
            Reason: Customer changed mind
            --------------------------------
            Get well soon. Medicines are not
               returnable once dispensed.
            TXT, $this->printed('refund', $this->receipts()->refund($return), 58));
    }

    public function test_an_opd_refund_slip_prints_the_medicines_as_one_amount(): void
    {
        $sale = $this->opdBill();
        $capsules = $sale->items->firstWhere('sku', $this->amoxil->sku);
        $return = app(ReturnService::class)->process($sale, [$capsules->getKey() => 4], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);

        $this->assertStringContainsString(<<<'TXT'
            ------------------------------------------------
            Medicines                                 198.34
              Amoxil Capsules 250 mg
                4 Capsule
                Batch AX1102
            ------------------------------------------------
            REFUND TOTAL LKR                          198.34
            ------------------------------------------------
            Cash                                      198.34
            TXT, $this->printed('refund', $this->receipts()->refund($return)));

        // Totals only: the Medicines amount without the names, as on the bill.
        $this->setShopSetting('opd_receipt_medicines', 'totals');
        $this->assertStringNotContainsString('Amoxil', $this->printed('refund', $this->receipts()->refund($return), 58));
    }

    public function test_a_void_slip(): void
    {
        $sale = $this->voided();

        $this->assertSame(<<<'TXT'
                              DEMO CLINIC
                        45 Hospital Road, Kandy
                          Tel +94 81 000 0000
            ================================================
                              *** VOID ***
            Bill no.                               T1-000001
            Bill date                       18/10/2026 10:00
            Voided                          18/10/2026 10:00
            Voided by                           Clinic Owner
            Till                            T1 Front counter
            Location                        General Pharmacy
            Cashier                           Clinic Cashier
            ------------------------------------------------
            Panadol Tablets 500 mg
              1 Box of 30 x 540.00                    540.00
              Batch PN2611
            Amoxil Capsules 250 mg
              15 Capsule x 35.00                      525.00
              Batch AX0921 x 5 Capsule
              Batch AX1102 x 10 Capsule
              Prescriber: Dr. S. Perera
              Rx ref: RX-1042
            ------------------------------------------------
            TOTAL VOIDED LKR                        1,065.00
            ------------------------------------------------
            Paid
            Bank Transfer                           1,065.00
              Ref BOC 448812
            Change                                      0.00
            TO GIVE BACK LKR                        1,065.00
            Reason: Rang up twice
            ------------------------------------------------
            Get well soon. Medicines are not returnable once
                               dispensed.
            TXT, $this->printed('void', $this->receipts()->voidSlip($sale)));

        $this->assertStringContainsString(<<<'TXT'
                      *** VOID ***
            Bill no.               T1-000001
            Bill date       18/10/2026 10:00
            Voided          18/10/2026 10:00
            Voided by           Clinic Owner
            TXT, $this->printed('void', $this->receipts()->voidSlip($sale), 58));
    }

    public function test_a_reprint_is_marked_with_when_and_by_whom(): void
    {
        $sale = $this->billAtGeneral();
        $this->tabletsBack($sale);
        $this->travel(65)->minutes();
        $reprint = $this->receipts()->recordReprint($sale, $this->owner, null);
        $payload = $this->receipts()->sale($sale->fresh() ?? $sale, $reprint);

        $this->assertSame(<<<'TXT'
                            *** REPRINT ***
                              DEMO CLINIC
                        45 Hospital Road, Kandy
                          Tel +94 81 000 0000
            ================================================
            Bill no.                               T1-000001
            Date                            18/10/2026 10:00
            Till                            T1 Front counter
            Location                        General Pharmacy
            Cashier                           Clinic Cashier
            ------------------------------------------------
            Panadol Tablets 500 mg
              1 Box of 30 x 540.00                    540.00
              Batch PN2611
              Returned 10 Tablet
            Amoxil Capsules 250 mg
              15 Capsule x 35.00                      525.00
              Batch AX0921 x 5 Capsule
              Batch AX1102 x 10 Capsule
              Prescriber: Dr. S. Perera
              Rx ref: RX-1042
            ------------------------------------------------
            TOTAL LKR                               1,065.00
            ------------------------------------------------
            Bank Transfer                           1,065.00
              Ref BOC 448812
            Change                                      0.00
            Refunded                                 -180.00
            ------------------------------------------------
            Get well soon. Medicines are not returnable once
                               dispensed.
            ================================================
                        REPRINT 18/10/2026 11:05
                            by Clinic Owner
            TXT, $this->printed('sale', $payload));

        $this->assertStringEndsWith(<<<'TXT'
            ================================
                REPRINT 18/10/2026 11:05
                    by Clinic Owner
            TXT, $this->printed('sale', $payload, 58));
        $this->assertStringStartsWith('        *** REPRINT ***', $this->printed('sale', $payload, 58));
    }

    public function test_refund_and_void_slips_are_reprinted_marked_reprint(): void
    {
        $return = $this->tabletsBack($this->billAtGeneral());
        $voided = $this->voided();
        $refund = $this->receipts()->recordReprint($return->sale, $this->owner, null, 'refund', $return);
        $void = $this->receipts()->recordReprint($voided, $this->owner, null, 'void');

        foreach ([$this->printed('refund', $this->receipts()->refund($return, $refund)), $this->printed('void', $this->receipts()->voidSlip($voided, $void))] as $printed) {
            $this->assertStringStartsWith('                *** REPRINT ***', $printed);
            $this->assertStringEndsWith("            REPRINT 18/10/2026 10:00\n                by Clinic Owner", $printed);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function documents(): array
    {
        return ['General Pharmacy bill' => ['gen'], 'OPD bill' => ['opd'], 'refund slip' => ['refund'], 'void slip' => ['void'], 'reprint' => ['reprint']];
    }

    #[DataProvider('documents')]
    public function test_no_printed_document_shows_the_expiry_unless_the_shop_turns_it_on(string $document): void
    {
        // One document, printed at both widths with the expiry off, then on: read when printing.
        $receipts = $this->receipts();
        [$kind, $payload] = match ($document) {
            'gen' => ['sale', $receipts->sale($this->billAtGeneral())],
            'opd' => ['sale', $receipts->sale($this->opdBill())],
            'refund' => ['refund', $receipts->refund($this->tabletsBack($this->billAtGeneral()))],
            'void' => ['void', $receipts->voidSlip($this->voided())],
            'reprint' => ['sale', $receipts->sale($sale = $this->billAtGeneral(), $receipts->recordReprint($sale, $this->owner, null))],
        };
        $print = fn (int $paperWidth): string => $this->printed($kind, $payload, $paperWidth);

        foreach ([80, 58] as $paperWidth) {
            $printed = $print($paperWidth);
            $this->assertStringContainsString('Batch ', $printed);
            $this->assertDoesNotMatchRegularExpression('#Exp|\d{2}/20(26|27)\b(?! \d{2}:)#', $printed);
            $this->assertLinesFit($printed, ReceiptLayout::columns($paperWidth));
        }

        $this->setShopSetting('receipt_show_expiry', true);

        foreach ([80, 58] as $paperWidth) {
            $printed = $print($paperWidth);
            $this->assertMatchesRegularExpression('#Exp (03|02|06)/2027#', $printed);
            $this->assertLinesFit($printed, ReceiptLayout::columns($paperWidth));
        }
    }

    public function test_long_names_wrap_and_amounts_stay_right_aligned_with_tax_on(): void
    {
        $shop = $this->receipts()->shop();
        $shop['receipt']['paperWidth'] = 58;
        $sale = [
            'invoiceNo' => 'T1-000009', 'status' => 'completed', 'billType' => 'standard', 'soldAt' => '2026-10-18 10:00', 'cashier' => 'Clinic Cashier', 'till' => null,
            'subtotal' => 1_234_500, 'discountTotal' => 10_000, 'taxTotal' => 90_000, 'taxRate' => 8.0, 'pricesIncludeTax' => true,
            'grandTotal' => 1_224_500, 'changeDue' => 0, 'refundedTotal' => 0, 'reprint' => null,
            'items' => [
                ['description' => 'Amoxicillin and Clavulanic Acid Extended Release Tablets (Box of 100)', 'strength' => '1000 mg', 'unitName' => 'Box of 100', 'quantity' => '12',
                    'unitPrice' => 100_000, 'discount' => 10_000, 'lineTotal' => 1_190_000, 'taxExempt' => false],
                ['description' => 'Bandage', 'unitName' => 'Piece', 'quantity' => '3', 'unitPrice' => 14_833, 'discount' => 0, 'lineTotal' => 44_500, 'taxExempt' => true],
            ],
            'payments' => [['method' => 'Card', 'amount' => 1_224_500, 'reference' => 'VISA 4242 AUTH 991234 TERMINAL 7']],
        ];

        $this->assertSame(<<<'TXT'
            --------------------------------
            Amoxicillin and Clavulanic Acid
              Extended Release Tablets 1000
              mg
              12 Box of 100 x
                1,000.00         11,900.00
              Discount             -100.00
            Bandage
              3 Piece x 148.33      445.00 E
            --------------------------------
            Subtotal             12,345.00
            Discount               -100.00
            Tax 8% (incl.)          900.00
            TOTAL LKR            12,245.00
            E = tax exempt
            --------------------------------
            Card                 12,245.00
              Ref VISA 4242 AUTH 991234
                TERMINAL 7
            Change                    0.00
            TXT, implode("\n", array_slice(array_column((new ReceiptLayout($shop))->sale($sale), 'text'), 7, 20)));
    }

    public function test_the_receipt_page_sends_the_thermal_layout_and_esc_pos_bytes(): void
    {
        $sale = $this->billAtGeneral();

        $this->actingAs($this->cashier)->get(route('pos.receipt', $sale))->assertInertia(fn (Assert $page) => $page
            ->component('pos/Receipt')
            ->where('thermal.paperWidth', 80)
            ->where('thermal.columns', 48)
            ->where('thermal.lines.0.text', '                  DEMO CLINIC')
            ->where('thermal.lines.0.bold', true)
            ->where('thermal.lines.0.tall', true)
            ->where('invoiceUrl', route('sales.invoice', $sale))
            ->where('sale.items.0.strength', '500 mg')
            ->where('sale.till.location', 'General Pharmacy')
            ->where('shop.receipt', ['paperWidth' => 80, 'showBatch' => true, 'showExpiry' => false, 'showPrescriber' => true]));

        $this->setShopSetting('receipt_paper_width', 58);

        $this->actingAs($this->cashier)->get(route('pos.receipt', $sale))->assertInertia(fn (Assert $page) => $page
            ->where('thermal.paperWidth', 58)
            ->where('thermal.columns', 32));
    }

    private function assertLinesFit(string $printed, int $columns): void
    {
        foreach (explode("\n", $printed) as $line) {
            $this->assertLessThanOrEqual($columns, strlen($line), "Too wide for {$columns} columns: [{$line}]");
        }
    }
}
