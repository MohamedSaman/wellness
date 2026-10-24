<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Exceptions\PosException;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use Illuminate\Support\Str;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * A bank transfer needs its reference when the shop says so
 * (bank_transfer_reference_required, on in the pharmacy profile; client
 * answers 2026-10-20). Online refused; offline recorded and flagged.
 */
class PaymentReferenceTest extends BatchTestCase
{
    use WorksWithCheckout;

    private ProductVariant $panadol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->panadol = $this->medicine();
        $this->receive($this->panadol, 'B1', '03/2027', 100);
    }

    /** @param  list<PaymentInput>  $payments */
    private function pay(array $payments, bool $offline = false): Sale
    {
        return app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->till,
            cashier: $this->cashier,
            lines: [new CartLine($this->panadol->getKey(), 5, tillUnitPrice: 2_000)],
            payments: $payments,
            uuid: (string) Str::uuid(),
            isOfflineSync: $offline,
            soldAt: $offline ? now()->subMinutes(3) : null,
        ));
    }

    public function test_a_bank_transfer_needs_its_reference(): void
    {
        $this->assertTrue((bool) $this->shop->setting('bank_transfer_reference_required'));

        foreach ([null, '', '  '] as $reference) {
            try {
                $this->pay([new PaymentInput(PaymentMethod::Cash, 5_000), new PaymentInput(PaymentMethod::BankTransfer, 5_000, $reference)]);
                $this->fail('A bank transfer without a reference was accepted.');
            } catch (PosException $exception) {
                $this->assertSame('Enter the bank transfer reference.', $exception->getMessage());
            }
        }

        $this->assertSame(0, Sale::query()->count());

        $sale = $this->pay([new PaymentInput(PaymentMethod::BankTransfer, 10_000, 'TRF 5521')]);
        $this->assertSame('TRF 5521', $sale->payments->sole()->reference);

        // Cash and card need none.
        $this->pay([new PaymentInput(PaymentMethod::Card, 10_000)]);
    }

    public function test_without_the_setting_a_reference_is_optional(): void
    {
        $this->setShopSetting('bank_transfer_reference_required', false);

        $this->assertFalse($this->pay([new PaymentInput(PaymentMethod::BankTransfer, 10_000)])->needs_review);
    }

    public function test_offline_a_transfer_without_a_reference_is_recorded_and_flagged(): void
    {
        $sale = $this->pay([new PaymentInput(PaymentMethod::BankTransfer, 10_000)], offline: true);

        $this->assertTrue($sale->needs_review);
        $this->assertSame([['type' => 'payment', 'message' => 'A bank transfer was taken without a reference.']], $sale->review_reasons);
        $this->assertSame(['B1' => 95.0], $this->batchQuantities($this->panadol));
    }

    public function test_the_endpoints_refuse_it_and_the_quote_previews_it(): void
    {
        $payload = [
            'device' => $this->till->uuid,
            'uuid' => (string) Str::uuid(),
            'lines' => [['variant_id' => $this->panadol->getKey(), 'quantity' => 5]],
            'payments' => [['method' => 'bank_transfer', 'amount' => '100']],
        ];

        $this->actingAs($this->cashier)->postJson(route('pos.quote'), $payload)
            ->assertOk()
            ->assertJsonPath('payment.error', 'Enter the bank transfer reference.');
        $this->actingAs($this->cashier)->postJson(route('pos.checkout'), $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Enter the bank transfer reference.');

        $payload['payments'][0]['reference'] = 'TRF 1';
        $this->actingAs($this->cashier)->postJson(route('pos.quote'), $payload)->assertOk()->assertJsonPath('payment.error', null);
        $this->actingAs($this->cashier)->postJson(route('pos.checkout'), $payload)->assertOk();
    }
}
