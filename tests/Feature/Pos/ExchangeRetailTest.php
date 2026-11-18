<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Filament\Pages\Reports\ExchangesReport;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use App\Services\ReportService;
use App\Services\ShiftService;
use App\Support\ReportPeriod;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Retail is unchanged by branch 19 (docs/PHARMACY.md): Return / Exchange is
 * pharmacy only, so a retail till has no button nor endpoints, its quote,
 * shift summary and payment methods are as they were, and there is no
 * Exchanges & returns report. Exchange credit is refused there too.
 */
class ExchangeRetailTest extends PosTestCase
{
    public function test_the_retail_till_has_no_return_exchange_nor_endpoints(): void
    {
        $this->actingAs($this->owner)->withCookie('pos_till', $this->till->uuid)->get(route('pos.index'))
            ->assertInertia(fn (Assert $page) => $page->where('exchanges', false));
        $this->actingAs($this->owner)
            ->getJson(route('pos.exchanges.index', ['device' => $this->till->uuid]))
            ->assertNotFound();
        $this->actingAs($this->owner)
            ->postJson(route('pos.exchanges.store'), ['device' => $this->till->uuid, 'uuid' => (string) Str::uuid()])
            ->assertNotFound();
        $this->actingAs($this->owner)->get(ExchangesReport::getUrl())->assertForbidden();
    }

    public function test_the_retail_quote_ignores_an_exchange_credit(): void
    {
        $variant = $this->variant('CPR8EA-9');

        $response = $this->actingAs($this->cashier)->postJson(route('pos.quote'), [
            'lines' => [['variant_id' => $variant->getKey(), 'quantity' => 1]],
            'exchange_credit' => 1_000,
        ])->assertOk();

        $this->assertArrayNotHasKey('exchange', $response->json());
        $this->assertNull($response->json('payment'));
    }

    public function test_the_retail_summary_and_payment_methods_are_as_they_were(): void
    {
        $shifts = app(ShiftService::class);
        $shift = $shifts->open($this->till, $this->cashier, 100_000);
        $variant = $this->variant('CPR8EA-9');
        $sale = app(CheckoutService::class)->checkout($this->cart([new CartLine($variant->getKey(), 1)]));

        $summary = $shifts->summary($shift);
        $this->assertArrayNotHasKey('exchanges', $summary);
        $this->assertArrayNotHasKey('till_returns', $summary);
        $this->assertSame(['cash'], array_column($summary['payments'], 'method'));
        $this->assertSame(['Cash'], array_column($summary['payments'], 'label'));
        $this->assertSame(['cash'], array_column(app(ReportService::class)->paymentMethods(ReportPeriod::today($this->shop)), 'key'));

        $this->actingAs($this->cashier)->get(route('pos.shift.summary', ['shift' => $shift->uuid]))
            ->assertOk()
            ->assertDontSee('Exchanges')
            ->assertDontSee('at the till');
        $this->assertSame(1, $sale->payments()->count());
    }

    public function test_retail_checkout_refuses_exchange_credit(): void
    {
        $variant = $this->variant('CPR8EA-9');

        $this->expectExceptionMessage('Exchange credit is only taken in an exchange');

        app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->till,
            cashier: $this->cashier,
            lines: [new CartLine($variant->getKey(), 1)],
            payments: [new PaymentInput(PaymentMethod::Exchange, 10_000_000)],
        ));
    }
}
