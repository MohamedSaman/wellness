<?php

namespace Tests\Feature\Pos;

use App\Enums\SaleStatus;
use App\Models\Device;
use App\Models\Sale;
use App\Models\Shop;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

class PosCheckoutScreenTest extends PosTestCase
{
    /** @return array<string, mixed> */
    private function payload(array $override = []): array
    {
        $plug = $this->variant('CPR8EA-9');

        return [
            'device' => $this->till->uuid,
            'uuid' => (string) Str::uuid(),
            'lines' => [['variant_id' => $plug->getKey(), 'quantity' => 2]],
            'sale_discount' => '0',
            'payments' => [['method' => 'cash', 'amount' => '5000']],
            ...$override,
        ];
    }

    private function send(string $route, array $data, $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->cashier)->postJson(route($route), $data);
    }

    public function test_quote_uses_server_prices_and_matches_what_checkout_charges(): void
    {
        $payload = $this->payload();

        $quote = $this->send('pos.quote', $payload)->assertOk();
        $checkout = $this->send('pos.checkout', $payload)->assertOk();

        $this->assertSame(290_000, $quote->json('totals.grand_total'));
        $this->assertSame($quote->json('totals.grand_total'), $checkout->json('sale.grand_total'));
        $this->assertSame(210_000, $checkout->json('sale.change_due'));
    }

    public function test_quote_includes_line_discounts_sale_discount_and_payment_preview(): void
    {
        $response = $this->send('pos.quote', $this->payload([
            'lines' => [['variant_id' => $this->variant('CPR8EA-9')->getKey(), 'quantity' => 2, 'line_discount' => '100']],
            'sale_discount' => '50',
            'payments' => [['method' => 'card', 'amount' => '1000']],
        ]))->assertOk();

        $this->assertSame(290_000 - 10_000 - 5_000, $response->json('totals.grand_total'));
        $this->assertSame(15_000, $response->json('totals.discount_total'));
        $this->assertSame(100_000, $response->json('payment.paid_total'));
        $this->assertSame(175_000, $response->json('payment.short_by'));
        $this->assertNotNull($response->json('payment.error'));
    }

    public function test_quote_returns_the_discount_limit_error_from_the_server(): void
    {
        $this->send('pos.quote', $this->payload(['sale_discount' => '1000']))
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'above your limit'));
    }

    public function test_owner_may_exceed_the_cashier_discount_limit(): void
    {
        $this->send('pos.quote', $this->payload(['sale_discount' => '1000']), $this->owner)->assertOk();
    }

    public function test_quote_never_exposes_cost_prices(): void
    {
        $json = $this->send('pos.quote', $this->payload())->assertOk()->getContent();

        $this->assertStringNotContainsString('unit_cost', $json);
        $this->assertStringNotContainsString('cost', strtolower($json));
    }

    public function test_a_tampered_price_is_ignored(): void
    {
        $payload = $this->payload();
        $payload['lines'][0]['unit_price'] = 1;
        $payload['grand_total'] = 1;

        $this->send('pos.checkout', $payload)->assertOk()->assertJsonPath('sale.grand_total', 290_000);

        $sale = Sale::query()->firstOrFail();
        $this->assertSame(145_000, $sale->items->first()->unit_price);

        // lines.*.price is a typed price (branch 8), only for services that allow it: refused here.
        $payload = $this->payload();
        $payload['lines'][0]['price'] = '0.01';

        $this->send('pos.checkout', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', "CPR8EA-9's price can't be changed at the till.");
        $this->assertSame(1, Sale::query()->count());
    }

    public function test_the_same_uuid_returns_the_original_sale_and_records_it_once(): void
    {
        $payload = $this->payload();
        $plug = $this->variant('CPR8EA-9');

        $first = $this->send('pos.checkout', $payload)->assertOk();
        $retry = $this->send('pos.checkout', $payload)->assertOk();

        $this->assertSame($first->json('sale.invoice_no'), $retry->json('sale.invoice_no'));
        $this->assertSame(1, Sale::query()->count());
        $this->assertSame(38.0, $this->stockOf($plug));
    }

    public function test_checkout_records_the_sale_for_the_signed_in_cashier_and_reduces_stock(): void
    {
        $this->send('pos.checkout', $this->payload())->assertOk();

        $sale = Sale::query()->firstOrFail();

        $this->assertSame($this->cashier->getKey(), $sale->user_id);
        $this->assertSame(SaleStatus::Completed, $sale->status);
        $this->assertSame(38.0, $this->stockOf($this->variant('CPR8EA-9')));
    }

    public function test_a_short_payment_is_refused_with_the_servers_message(): void
    {
        $this->send('pos.checkout', $this->payload(['payments' => [['method' => 'cash', 'amount' => '100']]]))
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'short'));

        $this->assertSame(0, Sale::query()->count());
    }

    public function test_change_can_only_come_from_cash(): void
    {
        $this->send('pos.checkout', $this->payload(['payments' => [['method' => 'card', 'amount' => '5000']]]))
            ->assertUnprocessable();

        $this->assertSame(0, Sale::query()->count());
    }

    public function test_split_payments_across_methods_are_accepted(): void
    {
        $this->send('pos.checkout', $this->payload(['payments' => [
            ['method' => 'card', 'amount' => '1000'],
            ['method' => 'bank_transfer', 'amount' => '1000', 'reference' => 'TR-1'],
            ['method' => 'cash', 'amount' => '1000'],
        ]]))->assertOk()->assertJsonPath('sale.change_due', 10_000);

        $this->assertSame(3, Sale::query()->firstOrFail()->payments()->count());
    }

    public function test_selling_more_than_is_in_stock_is_refused(): void
    {
        $this->send('pos.checkout', $this->payload(['lines' => [['variant_id' => $this->variant('CPR8EA-9')->getKey(), 'quantity' => 500]]]))
            ->assertUnprocessable();
    }

    public function test_a_cashier_cannot_sell_on_another_shops_till(): void
    {
        $other = Shop::query()->create(['name' => 'Other Shop', 'code' => 'OTHER']);
        $foreign = (new Device)->forceFill([
            'shop_id' => $other->getKey(),
            'location_id' => $this->location->getKey(),
            'name' => 'Foreign till',
            'code' => 'X1',
        ]);
        $foreign->save();

        $this->send('pos.checkout', $this->payload(['device' => $foreign->uuid]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('device');

        $this->assertSame(0, Sale::query()->count());
    }

    public function test_validation_rejects_malformed_carts(): void
    {
        $this->send('pos.checkout', $this->payload(['lines' => []]))->assertUnprocessable()->assertJsonValidationErrors('lines');
        $this->send('pos.checkout', $this->payload(['uuid' => 'not-a-uuid']))->assertUnprocessable()->assertJsonValidationErrors('uuid');
        $this->send('pos.checkout', $this->payload(['payments' => [['method' => 'gold', 'amount' => '1']]]))->assertUnprocessable()->assertJsonValidationErrors('payments.0.method');
        $this->send('pos.checkout', $this->payload(['sale_discount' => '-5']))->assertUnprocessable()->assertJsonValidationErrors('sale_discount');
    }

    public function test_guests_cannot_check_out(): void
    {
        $this->postJson(route('pos.checkout'), $this->payload())->assertUnauthorized();
    }
}
