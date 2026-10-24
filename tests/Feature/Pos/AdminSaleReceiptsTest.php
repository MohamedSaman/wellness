<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Filament\Resources\Sales\Pages\ViewSale;
use App\Models\AuditLog;
use App\Services\CheckoutService;
use App\Services\Data\ReturnLine;
use App\Services\ReceiptService;
use App\Services\ReturnService;
use Filament\Facades\Filament;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithReceipts;

/**
 * The admin sale page with receipts (docs/PHARMACY.md §9, branch 9): batch
 * and expiry stay on the sale page and its returns whatever receipts print;
 * void and refund slips print from it and reprint marked REPRINT; the A4
 * invoice follows the receipt settings.
 */
class AdminSaleReceiptsTest extends BatchTestCase
{
    use WorksWithCheckout, WorksWithReceipts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stockTheClinic();
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);
    }

    public function test_the_sale_page_and_its_returns_show_batch_and_expiry_though_receipts_hide_the_expiry(): void
    {
        $sale = $this->billAtGeneral();
        $box = $sale->items->firstWhere('sku', $this->panadol->sku);
        app(ReturnService::class)->processLines($sale, [new ReturnLine($box->getKey(), 10, inBaseUnits: true)], ReturnReason::ChangedMind, PaymentMethod::BankTransfer, $this->owner, 'BOC 449001');

        $this->assertFalse((bool) $this->shop->setting('receipt_show_expiry'));

        $this->get(route('filament.admin.resources.sales.view', $sale))
            ->assertOk()
            ->assertSee('PN2611 · exp 03/2027 x 30 (10 returned)')
            ->assertSee('AX0921 · exp 11/2026 x 5')
            ->assertSee('AX1102 · exp 02/2027 x 10')
            ->assertSee('Returns')
            ->assertSee('T1-000001-R1')
            ->assertSee('ref BOC 449001')
            ->assertSee('Panadol Tablets (Box of 30): 10 Tablet, refund LKR 180.00, back in stock (PN2611 · exp 03/2027 x 10)');
    }

    public function test_voiding_opens_the_void_slip_and_it_can_be_reprinted(): void
    {
        $sale = $this->billAtGeneral();

        Livewire::test(ViewSale::class, ['record' => $sale->getRouteKey()])
            ->callAction('void', data: ['reason' => 'Rang up twice'])
            ->assertHasNoActionErrors()
            ->assertNotified('Sale voided');

        $this->get(route('pos.receipt.void', $sale))->assertInertia(fn (Assert $page) => $page
            ->where('title', 'Void T1-000001')
            ->where('sale', null)
            ->where('thermal.lines.4.text', '                  *** VOID ***'));

        Livewire::test(ViewSale::class, ['record' => $sale->getRouteKey()])
            ->assertActionHidden('reprintRefund')
            ->callAction('reprintVoid')
            ->assertNotified('Void slip sent to print');

        $entry = AuditLog::query()->where('event', ReceiptService::REPRINT_EVENT)->sole();
        $this->assertSame('void', $entry->new_values['document']);

        $this->get(route('pos.receipt.reprinted', ['sale' => $sale, 'reprint' => $entry->getKey()]))->assertInertia(fn (Assert $page) => $page
            ->where('title', 'Reprint void T1-000001')
            ->where('thermal.lines.0.text', '                *** REPRINT ***')
            ->where('thermal.lines.5.text', '                  *** VOID ***'));
    }

    public function test_a_sale_that_was_not_voided_has_no_void_slip(): void
    {
        $sale = $this->billAtGeneral();

        $this->get(route('pos.receipt.void', $sale))->assertNotFound();
        Livewire::test(ViewSale::class, ['record' => $sale->getRouteKey()])->assertActionHidden('reprintVoid');
    }

    public function test_a_refund_slip_is_reprinted_from_the_sale_page(): void
    {
        $sale = $this->billAtGeneral();
        $first = app(ReturnService::class)->processLines($sale, [new ReturnLine($sale->items->first()->getKey(), 1)], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);
        $second = app(ReturnService::class)->processLines($sale, [new ReturnLine($sale->items->last()->getKey(), 5)], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);

        Livewire::test(ViewSale::class, ['record' => $sale->getRouteKey()])
            ->mountAction('reprintRefund')
            ->assertActionDataSet(['sale_return_id' => $second->getKey()])
            ->setActionData(['sale_return_id' => $first->getKey()])
            ->callMountedAction()
            ->assertNotified('Refund slip sent to print');

        $entry = AuditLog::query()->where('event', ReceiptService::REPRINT_EVENT)->sole();
        $this->assertSame(['refund', $first->getKey()], [$entry->new_values['document'], $entry->new_values['sale_return_id']]);

        $this->get(route('pos.receipt.reprinted', ['sale' => $sale, 'reprint' => $entry->getKey()]))->assertInertia(fn (Assert $page) => $page
            ->where('title', 'Reprint refund T1-000001-R1')
            ->where('thermal.lines.0.text', '                *** REPRINT ***')
            ->where('thermal.lines', fn ($lines): bool => collect($lines)->pluck('text')->contains('Refund no.                          T1-000001-R1')));
    }

    public function test_the_a4_invoice_follows_the_receipt_settings(): void
    {
        $sale = $this->billAtGeneral();

        $this->get(route('sales.invoice', $sale))
            ->assertOk()
            ->assertSee('Invoice')
            ->assertSee('T1-000001')
            ->assertSee('Panadol Tablets 500 mg')
            ->assertSee('Batch PN2611')
            ->assertSee('Prescriber: Dr. S. Perera')
            ->assertSee('BOC 448812')
            ->assertSee('Get well soon.')
            ->assertDontSee('03/2027');

        $this->setShopSetting('receipt_show_expiry', true);
        $this->setShopSetting('receipt_show_prescriber', false);

        $this->get(route('sales.invoice', $sale))
            ->assertSee('Batch PN2611 · exp 03/2027')
            ->assertDontSee('Dr. S. Perera');
    }

    public function test_the_a4_invoice_of_an_opd_bill_shows_one_medicines_amount(): void
    {
        $sale = $this->opdBill();

        $this->get(route('sales.invoice', $sale))
            ->assertSee('Medicines')
            ->assertSee('850.00')
            ->assertSee('Amoxil Capsules 250 mg × 10 Capsule')
            ->assertDontSee('495.84'); // a medicine's own share

        $this->setShopSetting('opd_receipt_medicines', 'totals');
        $this->get(route('sales.invoice', $sale))->assertDontSee('Amoxil');

        app(CheckoutService::class)->void($sale, $this->owner, 'Wrong patient');
        $this->get(route('sales.invoice', $sale))->assertSee('VOIDED');
    }

    public function test_the_a4_invoice_is_for_whoever_may_see_the_sale(): void
    {
        $sale = $this->billAtGeneral();

        $this->actingAs($this->cashier)->get(route('sales.invoice', $sale))->assertOk();
        $this->actingAs($this->cashierAt())->get(route('sales.invoice', $sale))->assertForbidden();
    }
}
