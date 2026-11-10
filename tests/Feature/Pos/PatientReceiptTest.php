<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Models\Sale;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PatientInput;
use App\Services\Data\PaymentInput;
use App\Services\PatientService;
use App\Services\ReceiptService;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithReceipts;

/**
 * The patient on the receipt (docs/PHARMACY.md, branch 13): their name and
 * their age at the visit, each printed only when the shop says so (both
 * off by default), on the first print and every reprint, read from the
 * sale, never from the patient as they are now. The till prints offline
 * receipts with the same layout (ReceiptLayoutParityTest).
 */
class PatientReceiptTest extends BatchTestCase
{
    use WorksWithCheckout, WorksWithReceipts;

    private function saleTo(?PatientInput $patient): Sale
    {
        $this->stockTheClinic();

        return app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->genTill(),
            cashier: $this->cashier,
            lines: [new CartLine($this->panadol->getKey(), 2)],
            payments: [new PaymentInput(PaymentMethod::Cash, 100_000)],
            uuid: (string) Str::uuid(),
            patient: $patient,
        ));
    }

    private function receipt(Sale $sale): string
    {
        return $this->printed('sale', app(ReceiptService::class)->sale($sale->fresh() ?? $sale));
    }

    public function test_the_patient_prints_only_as_the_shop_says(): void
    {
        $sale = $this->saleTo(new PatientInput((string) Str::uuid(), 'Nimal Perera', 408));

        $this->assertSame(['name' => 'Nimal Perera', 'age' => '34 y'], app(ReceiptService::class)->sale($sale)['patient']);

        // Off by default: the sale keeps the patient, the receipt does not print them.
        $this->assertStringNotContainsString('Nimal', $this->receipt($sale));
        $this->assertStringNotContainsString('34 y', $this->receipt($sale));

        $this->setShopSetting('receipt_show_patient_name', true);
        $this->assertMatchesRegularExpression('/^Cashier .+\nPatient +Nimal Perera$/m', $this->receipt($sale));

        $this->setShopSetting('receipt_show_patient_age', true);
        $this->assertMatchesRegularExpression('/^Patient +Nimal Perera \(34 y\)$/m', $this->receipt($sale));

        $this->setShopSetting('receipt_show_patient_name', false);
        $this->assertMatchesRegularExpression('/^Age +34 y$/m', $this->receipt($sale));
        $this->assertStringNotContainsString('Nimal', $this->receipt($sale));
    }

    public function test_a_reprint_prints_the_patient_as_sold(): void
    {
        $this->setShopSetting('receipt_show_patient_name', true);
        $this->setShopSetting('receipt_show_patient_age', true);
        $sale = $this->saleTo(new PatientInput((string) Str::uuid(), 'Baby Silva', 8));
        app(PatientService::class)->update($sale->patient, $this->owner, ['name' => 'Baby K. Silva', 'age' => '3']);

        $receipts = app(ReceiptService::class);
        $reprint = $receipts->sale($sale->fresh() ?? $sale, $receipts->recordReprint($sale, $this->owner, null));

        $this->assertMatchesRegularExpression('/^Patient +Baby Silva \(8 m\)$/m', $this->printed('sale', $reprint));
    }

    public function test_a_sale_without_a_patient_prints_no_patient_row(): void
    {
        $this->setShopSetting('receipt_show_patient_name', true);
        $this->setShopSetting('receipt_show_patient_age', true);
        $sale = $this->saleTo(null);

        $this->assertNull(app(ReceiptService::class)->sale($sale)['patient']);
        $this->assertDoesNotMatchRegularExpression('/^(Patient|Age) /m', $this->receipt($sale));
    }

    public function test_the_tills_receipt_page_carries_the_patient_and_the_settings(): void
    {
        $this->setShopSetting('receipt_show_patient_name', true);
        $sale = $this->saleTo(new PatientInput((string) Str::uuid(), 'Nimal Perera', 408));

        $this->actingAs($this->cashier)->get(route('pos.receipt', $sale))->assertInertia(fn (Assert $page) => $page
            ->component('pos/Receipt')
            ->where('sale.patient', ['name' => 'Nimal Perera', 'age' => '34 y'])
            ->where('shop.receipt.showPatientName', true)
            ->where('shop.receipt.showPatientAge', false));
    }

    public function test_the_a4_invoice_names_the_patient_and_prints_the_age_when_the_shop_does(): void
    {
        $sale = $this->saleTo(new PatientInput((string) Str::uuid(), 'Nimal Perera', 408));

        // The name, as the invoice always showed the patient's name.
        $this->actingAs($this->owner)->get(route('sales.invoice', $sale))->assertOk()
            ->assertSee('Nimal Perera')
            ->assertDontSee('34 y');

        $this->setShopSetting('receipt_show_patient_age', true);
        $this->actingAs($this->owner)->get(route('sales.invoice', $sale))->assertOk()->assertSee('34 y');
    }
}
