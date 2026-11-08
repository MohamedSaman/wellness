<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Models\Shift;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use App\Services\Receipts\ReceiptLayout;
use App\Services\ReceiptService;
use App\Services\ReturnService;
use App\Services\ShiftService;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithReceipts;

/**
 * The clinic's shift summary on the receipt printer: the till prints it like
 * a receipt (pages/pos/Receipt.vue, ReceiptLayout::shiftSummary()), with
 * every figure of the A4 summary as ShiftService::summary() works it out,
 * plus the invoice range and the discounts given. The A4 page stays at
 * ?format=a4; a retail shop keeps it (ShiftSummaryTest).
 */
class ShiftSummaryThermalTest extends BatchTestCase
{
    use WorksWithCheckout, WorksWithReceipts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stockTheClinic();
        $this->setShopSetting('max_cashier_discount_percent', 10);
    }

    /**
     * Float 5,000.00. T1-000001 by bank transfer (1,065.00); T1-000002, 10
     * Amoxil less 20.00 (330.00), paid 400.00 cash with 70.00 change;
     * T1-000003 (540.00 cash) voided; one capsule of T1-000002 back in cash
     * (33.00). Expected cash: 5,000.00 + 330.00 - 33.00 = 5,297.00.
     */
    private function aShift(): Shift
    {
        $shift = app(ShiftService::class)->open($this->till, $this->cashier, 500_000);
        $checkout = app(CheckoutService::class);
        $box = $this->unitNamed($this->panadol, 'Box of 30');

        $this->billAtGeneral();
        $discounted = $checkout->checkout(new CheckoutData(
            device: $this->till,
            cashier: $this->cashier,
            lines: [new CartLine($this->amoxil->getKey(), 10, prescriber: 'Dr. S. Perera')],
            payments: [new PaymentInput(PaymentMethod::Cash, 40_000)],
            saleDiscount: 2_000,
        ));
        $voided = $checkout->checkout(new CheckoutData(
            device: $this->till,
            cashier: $this->cashier,
            lines: [new CartLine($this->panadol->getKey(), 1, unitId: $box)],
            payments: [new PaymentInput(PaymentMethod::Cash, 54_000)],
        ));
        $checkout->void($voided, $this->owner, 'Rang up twice');
        app(ReturnService::class)->process($discounted, [$discounted->items[0]->getKey() => 1], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);

        return $shift->fresh() ?? $shift;
    }

    /** The summary page's lines, as the browser prints them (large print, 36 columns). */
    private function printedPage(Shift $shift, ?User $user = null): string
    {
        $lines = null;

        $this->actingAs($user ?? $this->cashier)
            ->get(route('pos.shift.summary', ['shift' => $shift->uuid]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$lines): void {
                $page->component('pos/Receipt');
                $lines = $page->toArray()['props']['thermal']['lines'];
            });

        return implode("\n", array_column((array) $lines, 'text'));
    }

    public function test_the_till_prints_the_summary_like_a_receipt(): void
    {
        $shift = $this->aShift();
        $this->travel(8)->hours();
        $closed = app(ShiftService::class)->close($shift, $this->cashier, 524_700);

        $this->actingAs($this->cashier)
            ->get(route('pos.shift.summary', ['shift' => $closed->uuid]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('pos/Receipt')
                ->where('title', 'Shift summary T1 18/10/2026')
                ->where('sale', null)
                ->where('invoiceUrl', null)
                ->where('thermal.paperWidth', 80)
                ->where('thermal.columns', 36)
                ->where('thermal.largePrint', true)
                ->where('thermal.lines.0.bold', true)
                ->where('thermal.lines.0.tall', true)
                ->where('thermal.escpos', fn (string $bytes): bool => str_contains((string) base64_decode($bytes, true), 'SHIFT SUMMARY')));

        $printed = $this->printedPage($closed);

        $this->assertSame(<<<'TXT'
                        DEMO CLINIC
                  45 Hospital Road, Kandy
                    Tel +94 81 000 0000
            ====================================
                       SHIFT SUMMARY
            Till                T1 Front counter
            Location            General Pharmacy
            Cashier               Clinic Cashier
            Opened              18/10/2026 10:00
            Closed              18/10/2026 18:00
            Printed             18/10/2026 18:00
            ------------------------------------
            Bills                              2
            Invoices      T1-000001 to T1-000003
            Sales                       1,395.00
            Discounts given                20.00
            Refunds (1)                   -33.00
            NET SALES LKR               1,362.00
            Voided bills (1)              540.00
            ------------------------------------
            Payments
            Cash                          330.00
            Bank Transfer               1,065.00
            Cash is net of change given back.
            ------------------------------------
            Opening float               5,000.00
            Cash sales                    330.00
            Cash refunds                  -33.00
            EXPECTED CASH LKR           5,297.00
            Counted                     5,247.00
            Difference                    -50.00
                    *** SHORT 50.00 ***
            ------------------------------------


            Cashier ____________________________


            Checked by _________________________


            Date _______________________________
            TXT, $printed);
        $this->assertLinesFit($printed, 36);
    }

    public function test_every_figure_is_the_a4_summarys_and_the_a4_page_is_still_there(): void
    {
        $shift = $this->aShift();
        $closed = app(ShiftService::class)->close($shift, $this->cashier, 540_000);
        $summary = app(ShiftService::class)->summary($closed);
        $money = fn (int $minor): string => ReceiptLayout::ascii(($minor < 0 ? '-' : '').number_format(abs($minor) / 100, 2));
        $printed = $this->printedPage($closed);

        // The figures ShiftService works out, unchanged, on the receipt.
        foreach ([
            'Bills' => (string) $summary['bills'],
            'Sales' => $money($summary['gross']),
            'Refunds (1)' => $money(-$summary['refunds']),
            'NET SALES LKR' => $money($summary['net']),
            'Voided bills (1)' => $money($summary['voids_total']),
            'Cash' => $money($summary['payments'][0]['amount']),
            'Bank Transfer' => $money($summary['payments'][1]['amount']),
            'Opening float' => $money($summary['cash']['opening']),
            'Cash sales' => $money($summary['cash']['sales']),
            'Cash refunds' => $money(-$summary['cash']['refunds']),
            'EXPECTED CASH LKR' => $money((int) $summary['cash']['expected']),
            'Counted' => $money((int) $summary['cash']['counted']),
            'Difference' => $money((int) $summary['cash']['difference']),
        ] as $label => $value) {
            $this->assertMatchesRegularExpression('/^'.preg_quote($label, '/').' +'.preg_quote($value, '/').'$/m', $printed, $label);
        }

        $this->assertStringContainsString('*** OVER 103.00 ***', $printed);

        // The A4 page, as it was: same figures, no new ones.
        $this->actingAs($this->cashier)
            ->get(route('pos.shift.summary', ['shift' => $closed->uuid, 'format' => 'a4']))
            ->assertOk()
            ->assertViewIs('pos.shift-summary')
            ->assertSee('Shift summary')
            ->assertSee($this->shop->formatMoney($summary['net']))
            ->assertSee($this->shop->formatMoney((int) $summary['cash']['expected']))
            ->assertDontSee('Discounts given')
            ->assertDontSee('T1-000003');
    }

    public function test_a_shift_with_no_bills_still_open_then_balanced(): void
    {
        $shift = app(ShiftService::class)->open($this->till, $this->cashier, 250_000);
        $printed = $this->printedPage($shift);

        $this->assertStringContainsString(implode("\n", [
            'Closed                    Still open',
            'Printed             18/10/2026 10:00',
            '------------------------------------',
            'Bills                              0',
            'Invoices                           -',
            'Sales                           0.00',
            'Discounts given                 0.00',
        ]), $printed);
        $this->assertStringContainsString("Payments\nNo payments.\nCash is net of change given back.", $printed);
        $this->assertStringContainsString("Counted                            -\nDifference                         -\n----", $printed);

        $closed = app(ShiftService::class)->close($shift, $this->cashier, 250_000);
        $printed = $this->printedPage($closed);

        $this->assertStringContainsString("Difference                      0.00\n              Balanced\n----", $printed);
    }

    public function test_long_names_wrap_within_the_paper(): void
    {
        $this->till->update(['name' => 'Front counter by the main entrance near the pharmacy window']);
        $this->general->update(['name' => 'General Pharmacy and Surgical Supplies Ground Floor']);
        $this->cashier->update(['name' => 'Wijesinghe Mudiyanselage Kumari Chandrika Perera']);
        $shift = $this->aShift();
        $closed = app(ShiftService::class)->close($shift, $this->cashier, 529_700);

        foreach ([80 => 36, 58 => 32] as $paper => $columns) {
            $this->setShopSetting('receipt_paper_width', $paper);
            $printed = $this->printedPage($closed);

            $this->assertLinesFit($printed, $columns);
            // Every word is printed: the names only wrap, under their labels.
            $flat = (string) preg_replace('/\s+/', ' ', $printed);
            $this->assertStringContainsString('Till T1 '.$this->till->name.' Location', $flat);
            $this->assertStringContainsString('Location '.$this->general->name.' Cashier', $flat);
            $this->assertStringContainsString('Cashier '.$this->cashier->name.' Opened', $flat);
        }

        // The ESC/POS copy, in the printer's own 48 columns on 80 mm.
        $this->setShopSetting('receipt_paper_width', 80);
        $shop = ReceiptLayout::forPrinter(app(ReceiptService::class)->shop());
        $lines = (new ReceiptLayout($shop))->shiftSummary([
            'till' => ['code' => 'T1', 'name' => $this->till->name, 'location' => $this->general->name],
            'cashier' => $this->cashier->name, 'openedAt' => '2026-10-18 10:00', 'closedAt' => null, 'printedAt' => '2026-10-18 10:00',
            'invoices' => ['first' => 'T1-000001', 'last' => 'T1-000001'], 'discounts' => 0,
            'summary' => app(ShiftService::class)->summary($closed),
        ]);
        $printed = implode("\n", array_column($lines, 'text'));

        $this->assertLinesFit($printed, 48);
        $this->assertMatchesRegularExpression('/^Invoices +T1-000001$/m', $printed);
        $this->assertStringContainsString("Difference                                  0.00\n                    Balanced", $printed);
    }

    public function test_only_the_cashier_whose_shift_it_is_or_the_owner_reads_it(): void
    {
        $shift = $this->aShift();
        $other = $this->cashierAt();

        $this->actingAs($other)->get(route('pos.shift.summary', ['shift' => $shift->uuid]))->assertForbidden();
        $this->actingAs($other)->get(route('pos.shift.summary', ['shift' => $shift->uuid, 'format' => 'a4']))->assertForbidden();
        $this->assertStringContainsString('SHIFT SUMMARY', $this->printedPage($shift, $this->owner));
    }

    private function assertLinesFit(string $printed, int $columns): void
    {
        foreach (explode("\n", $printed) as $line) {
            $this->assertLessThanOrEqual($columns, strlen($line), "Too wide for {$columns} columns: [{$line}]");
        }
    }
}
