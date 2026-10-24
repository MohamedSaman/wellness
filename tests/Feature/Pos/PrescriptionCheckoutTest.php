<?php

namespace Tests\Feature\Pos;

use App\Enums\DrugSchedule;
use App\Enums\PaymentMethod;
use App\Exceptions\PosException;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use App\Services\ProductCatalogService;
use Illuminate\Support\Str;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * Prescription lines (docs/PHARMACY.md §4.10): every medicine line keeps
 * its schedule; a prescription-only or controlled line needs a prescriber
 * when the shop says so (`rx_prescriber`, required in the pharmacy
 * profile), and with the controlled-drug register on, a controlled line
 * needs the patient's name. Offline these are flagged, never refused.
 */
class PrescriptionCheckoutTest extends BatchTestCase
{
    use WorksWithCheckout;

    private ProductVariant $amox;

    private ProductVariant $morphine;

    private ProductVariant $panadol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->amox = $this->medicine('Amoxicillin 250 mg Capsules', [], schedule: DrugSchedule::PrescriptionOnly);
        $this->morphine = $this->medicine('Morphine 10 mg Tablets', [], schedule: DrugSchedule::Controlled);
        $this->panadol = $this->medicine();

        foreach ([$this->amox, $this->morphine, $this->panadol] as $variant) {
            $this->receive($variant, 'B1', '03/2027', 100);
        }
    }

    /** @param  list<CartLine>  $lines */
    private function ring(array $lines, bool $offline = false, ?string $patient = null): Sale
    {
        return app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->till,
            cashier: $this->cashier,
            lines: $lines,
            payments: [new PaymentInput(PaymentMethod::Cash, 1_000_000)],
            uuid: (string) Str::uuid(),
            customerName: $patient,
            isOfflineSync: $offline,
            soldAt: $offline ? now()->subMinutes(3) : null,
        ));
    }

    private function assertRefused(callable $attempt, string $message): void
    {
        try {
            $attempt();
            $this->fail("Accepted; expected: {$message}");
        } catch (PosException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }

    public function test_a_prescription_line_needs_a_prescriber_when_the_shop_requires_one(): void
    {
        $this->assertSame('required', $this->shop->setting('rx_prescriber'));

        $this->assertRefused(fn () => $this->ring([new CartLine($this->panadol->getKey(), 1), new CartLine($this->amox->getKey(), 2)]), "{$this->amox->sku} is prescription only: enter the prescriber.");
        $this->assertRefused(fn () => $this->ring([new CartLine($this->amox->getKey(), 2, prescriber: '   ')]), "{$this->amox->sku} is prescription only: enter the prescriber.");
        $this->assertRefused(fn () => $this->ring([new CartLine($this->morphine->getKey(), 1)]), "{$this->morphine->sku} is prescription only: enter the prescriber.");
        $this->assertSame(0, Sale::query()->count());

        $sale = $this->ring([
            new CartLine($this->panadol->getKey(), 1),
            new CartLine($this->amox->getKey(), 2, prescriber: '  Dr Silva ', prescriptionRef: ' RX-1042 '),
            new CartLine($this->morphine->getKey(), 1, prescriber: 'Dr Silva', prescriptionRef: '  '),
        ]);

        $this->assertSame([DrugSchedule::Otc, DrugSchedule::PrescriptionOnly, DrugSchedule::Controlled], $sale->items->pluck('drug_schedule')->all());
        $this->assertSame([null, 'Dr Silva', 'Dr Silva'], $sale->items->pluck('prescriber')->all());
        $this->assertSame([null, 'RX-1042', null], $sale->items->pluck('prescription_ref')->all());
        $this->assertSame([null, null, null], $sale->items->pluck('prescription_id')->all());
    }

    public function test_the_till_may_sell_without_a_prescriber_when_the_shop_only_prompts(): void
    {
        $this->setShopSetting('rx_prescriber', 'prompt');

        $sale = $this->ring([new CartLine($this->amox->getKey(), 2), new CartLine($this->morphine->getKey(), 1)]);

        $this->assertFalse($sale->needs_review);
        $this->assertSame([null, null], $sale->items->pluck('prescriber')->all());
    }

    public function test_a_controlled_line_needs_the_patient_with_the_register_on(): void
    {
        $this->setShopSetting('controlled_register', true);
        $line = new CartLine($this->morphine->getKey(), 1, prescriber: 'Dr Silva');

        $this->assertRefused(fn () => $this->ring([$line]), "Enter the patient's name for {$this->morphine->sku} (controlled drug).");
        $this->assertRefused(fn () => $this->ring([$line], patient: ' '), "Enter the patient's name for {$this->morphine->sku} (controlled drug).");

        $this->assertSame('Nimal Perera', $this->ring([$line], patient: 'Nimal Perera')->customer_name);

        // Prescription-only lines are not register lines.
        $this->ring([new CartLine($this->amox->getKey(), 1, prescriber: 'Dr Silva')]);
    }

    public function test_offline_prescription_lines_are_recorded_and_flagged(): void
    {
        $this->setShopSetting('controlled_register', true);

        $sale = $this->ring([
            new CartLine($this->amox->getKey(), 2, tillUnitPrice: 2_000),
            new CartLine($this->morphine->getKey(), 1, tillUnitPrice: 2_000, prescriber: 'Dr Silva'),
        ], offline: true);

        $this->assertTrue($sale->needs_review);
        $this->assertSame([
            ['type' => 'rx_missing', 'sku' => $this->amox->sku, 'message' => "{$this->amox->sku} is prescription only and was sold without a prescriber."],
            ['type' => 'controlled_offline', 'sku' => $this->morphine->sku, 'message' => "{$this->morphine->sku} is a controlled drug and was sold offline. Check the controlled-drug register."],
        ], $sale->review_reasons);
        $this->assertSame(['B1' => 98.0], $this->batchQuantities($this->amox));

        // Register off (the default): a controlled line sold offline with its prescriber is not flagged.
        $this->setShopSetting('controlled_register', false);
        $this->assertFalse($this->ring([new CartLine($this->morphine->getKey(), 1, tillUnitPrice: 2_000, prescriber: 'Dr Silva')], offline: true)->needs_review);
    }

    public function test_the_checkout_endpoint_takes_the_prescription_and_the_patient(): void
    {
        $this->setShopSetting('controlled_register', true);
        $payload = fn (array $line, array $extra = []): array => [
            'device' => $this->till->uuid,
            'uuid' => (string) Str::uuid(),
            'lines' => [['variant_id' => $this->morphine->getKey(), 'quantity' => 1, ...$line]],
            'payments' => [['method' => 'cash', 'amount' => '100']],
            ...$extra,
        ];

        $this->actingAs($this->cashier)->postJson(route('pos.checkout'), $payload([]))
            ->assertUnprocessable()
            ->assertJsonPath('message', "{$this->morphine->sku} is prescription only: enter the prescriber.");
        $this->actingAs($this->cashier)->postJson(route('pos.checkout'), $payload(['prescriber' => 'Dr Silva']))
            ->assertUnprocessable()
            ->assertJsonPath('message', "Enter the patient's name for {$this->morphine->sku} (controlled drug).");
        $this->actingAs($this->cashier)->postJson(route('pos.checkout'), $payload(['prescriber' => str_repeat('x', 151)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.prescriber');

        $this->actingAs($this->cashier)->postJson(route('pos.checkout'), $payload(
            ['prescriber' => 'Dr Silva', 'prescription_ref' => 'RX-7'],
            ['customer_name' => 'Nimal Perera', 'customer_phone' => '0771234567'],
        ))->assertOk();

        $sale = Sale::query()->with('items')->sole();
        $this->assertSame('Nimal Perera', $sale->customer_name);
        $this->assertSame('0771234567', $sale->customer_phone);
        $this->assertSame('Dr Silva', $sale->items->sole()->prescriber);
        $this->assertSame('RX-7', $sale->items->sole()->prescription_ref);
        $this->assertSame(DrugSchedule::Controlled, $sale->items->sole()->drug_schedule);

        // The quote is a preview: it does not ask for the prescriber yet.
        $this->actingAs($this->cashier)->postJson(route('pos.quote'), $payload([]))
            ->assertOk()
            ->assertJsonPath('lines.0.drug_schedule', 'controlled');
    }

    public function test_a_service_line_has_no_schedule(): void
    {
        $service = app(ProductCatalogService::class)->create(['name' => 'Dressing', 'unit' => 'Each', 'track_stock' => false], [['price' => 50_000]], user: $this->owner)->variants()->firstOrFail();

        $this->assertNull($this->ring([new CartLine($service->getKey(), 1)])->items->sole()->drug_schedule);
    }
}
