<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Filament\Pages\ReceiptSettings;
use App\Models\Sale;
use App\Services\CatalogSyncService;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\PaymentInput;
use App\Services\ReceiptService;
use App\Services\ReturnService;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;

/**
 * Retail with pharmacy off (docs/PHARMACY.md §9, branch 9): the sale receipt
 * is the HTML receipt it always was (plus the payment references of branch
 * 8), the receipt settings start where retail always was, and the new refund
 * and void slips and the A4 invoice work for a retail shop too.
 */
class RetailReceiptsTest extends AdminTestCase
{
    private function sale(): Sale
    {
        return app(CheckoutService::class)->checkout($this->cart(
            [new CartLine($this->variant('CPR8EA-9')->getKey(), 2)],
            [new PaymentInput(PaymentMethod::Card, 290_000, 'VISA 4242')],
        ));
    }

    public function test_the_retail_receipt_is_the_html_receipt_it_always_was(): void
    {
        $sale = $this->sale();

        $this->actingAs($this->cashier)->get(route('pos.receipt', $sale))->assertInertia(fn (Assert $page) => $page
            ->component('pos/Receipt')
            ->where('thermal', null)
            ->where('sale.invoiceNo', $sale->invoice_no)
            ->missing('sale.items.0.batches')
            ->where('sale.items.0.strength', null)
            ->where('sale.payments.0.reference', 'VISA 4242')
            ->where('shop.receipt', ['paperWidth' => 80, 'showBatch' => true, 'showExpiry' => true, 'showPrescriber' => false]));
    }

    public function test_retail_receipt_settings_start_as_retail_always_was_and_have_no_pharmacy_choices(): void
    {
        $this->assertSame(['paperWidth' => 80, 'showBatch' => true, 'showExpiry' => true, 'showPrescriber' => false], ReceiptService::printOptions($this->shop));
        $this->assertSame(ReceiptService::printOptions($this->shop), app(CatalogSyncService::class)->shopSettings($this->shop)['receipt']);

        $before = $this->shop->settings ?? [];
        $this->actingAs($this->owner);

        Livewire::test(ReceiptSettings::class)
            ->assertFormFieldIsHidden('receipt_show_expiry')
            ->assertFormFieldIsHidden('receipt_show_batch')
            ->assertFormFieldIsHidden('receipt_show_prescriber')
            ->assertFormFieldIsHidden('opd_receipt_medicines')
            ->assertSee('Refund slip')
            ->fillForm(['receipt_header' => 'Welcome', 'receipt_footer' => 'No returns after 14 days'])
            ->call('save')
            ->assertHasNoFormErrors();

        $shop = $this->shop->fresh();
        $this->assertSame(['Welcome', 'No returns after 14 days'], [$shop->receipt_header, $shop->receipt_footer]);
        $this->assertSameIgnoringKeyOrder([...$before, 'receipt_paper_width' => 80], $shop->settings);
    }

    public function test_a_retail_refund_and_void_print_as_slips_and_the_sale_as_an_a4_invoice(): void
    {
        $sale = $this->sale();
        $return = app(ReturnService::class)->process($sale, [$sale->items->sole()->getKey() => 1], ReturnReason::ChangedMind, PaymentMethod::Card, $this->owner, refundReference: 'VISA 4242 R');
        $this->actingAs($this->owner);

        $this->get(route('pos.receipt.refund', ['sale' => $sale, 'saleReturn' => $return]))->assertInertia(fn (Assert $page) => $page
            ->where('thermal.columns', 48)
            ->where('thermal.lines', fn ($lines): bool => collect($lines)->pluck('text')->contains('  Ref VISA 4242 R')));

        $this->get(route('sales.invoice', $sale))->assertOk()->assertSee($sale->invoice_no)->assertSee('ref VISA 4242', false);

        $voided = $this->sale();
        app(CheckoutService::class)->void($voided, $this->owner, 'Mistake');
        $this->get(route('pos.receipt.void', $voided))->assertInertia(fn (Assert $page) => $page
            ->where('thermal.lines', fn ($lines): bool => collect($lines)->pluck('text')->contains('Reason: Mistake')));
    }
}
