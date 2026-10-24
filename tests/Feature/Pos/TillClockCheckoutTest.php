<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * An offline till's clock (docs/PHARMACY.md §7.5): a sale whose till clock
 * was out by more than the shop's tolerance is recorded as the till printed
 * it and flagged (`clock`); reports date it by the server's clock
 * (sales.reported_at). Online sales are on the server's clock.
 */
class TillClockCheckoutTest extends BatchTestCase
{
    use WorksWithCheckout;

    private ProductVariant $panadol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->panadol = $this->medicine();
        $this->receive($this->panadol, 'B1', '03/2027', 100);
    }

    private function replay(CarbonInterface $soldAt, ?int $offset, ?User $cashier = null): Sale
    {
        return app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->till,
            cashier: $cashier ?? $this->cashier,
            lines: [new CartLine($this->panadol->getKey(), 1, tillUnitPrice: 2_000)],
            payments: [new PaymentInput(PaymentMethod::Cash, 2_000)],
            uuid: (string) Str::uuid(),
            isOfflineSync: true,
            soldAt: $soldAt,
            clockOffsetSeconds: $offset,
        ))->fresh() ?? $this->fail('The sale was not saved.');
    }

    /** @return list<string> */
    private function reasons(Sale $sale): array
    {
        return array_column($sale->review_reasons ?? [], 'type');
    }

    public function test_a_clock_within_tolerance_is_kept_and_not_flagged(): void
    {
        $sale = $this->replay(now()->subHour()->addMinutes(9), 9 * 60);

        $this->assertFalse($sale->needs_review);
        $this->assertSame(540, $sale->clock_offset_seconds);
        $this->assertSame($sale->sold_at?->toDateTimeString(), $sale->reported_at?->toDateTimeString());
    }

    public function test_a_clock_out_by_more_than_the_tolerance_is_flagged_and_reported_on_the_servers_time(): void
    {
        // The till was 2 hours ahead: it printed 14:00 for a sale made at 12:00 server time.
        $ahead = $this->replay(now()->addHours(2)->subMinutes(30), 7_200);

        $this->assertSame(['clock'], $this->reasons($ahead));
        $this->assertSame("The till's clock was 120 minutes ahead. Reports date the sale by the server's clock.", $ahead->review_reasons[0]['message']);
        $this->assertSame(7_200, $ahead->clock_offset_seconds);
        $this->assertSame(now()->subMinutes(30)->toDateTimeString(), $ahead->reported_at?->toDateTimeString());
        $this->assertSame(now()->addHours(2)->subMinutes(30)->toDateTimeString(), $ahead->sold_at?->toDateTimeString());

        $behind = $this->replay(now()->subDays(3), -3 * 86_400 + 600);

        $this->assertSame(['clock'], $this->reasons($behind));
        $this->assertStringContainsString('minutes behind', $behind->review_reasons[0]['message']);
        $this->assertSame(now()->subMinutes(10)->toDateTimeString(), $behind->reported_at?->toDateTimeString());
    }

    public function test_without_a_measured_clock_a_sale_far_ahead_is_flagged_and_reported_when_it_arrived(): void
    {
        $sale = $this->replay(now()->addMinutes(45), null);

        $this->assertSame(['clock'], $this->reasons($sale));
        $this->assertSame("The sale time was 45 minutes ahead of the server's clock. Reports date it by when it reached the server.", $sale->review_reasons[0]['message']);
        $this->assertNull($sale->clock_offset_seconds);
        $this->assertSame(now()->toDateTimeString(), $sale->reported_at?->toDateTimeString());
        $this->assertSame($sale->synced_at?->toDateTimeString(), $sale->reported_at?->toDateTimeString());

        // A little ahead, or in the past (an old outbox sale), is fine.
        $this->assertFalse($this->replay(now()->addMinutes(9), null)->needs_review);
        $old = $this->replay(now()->subDays(2), null);
        $this->assertFalse($old->needs_review);
        $this->assertSame($old->sold_at?->toDateTimeString(), $old->reported_at?->toDateTimeString());
    }

    public function test_the_tolerance_is_a_shop_setting(): void
    {
        $this->setShopSetting('till_clock_tolerance_minutes', 60);
        $this->assertFalse($this->replay(now()->addMinutes(45), 45 * 60)->needs_review);

        $this->setShopSetting('till_clock_tolerance_minutes', 1);
        $this->assertSame(['clock'], $this->reasons($this->replay(now()->addMinutes(2), 120)));
    }

    public function test_online_sales_are_on_the_servers_clock(): void
    {
        $sale = app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->till,
            cashier: $this->cashier,
            lines: [new CartLine($this->panadol->getKey(), 1)],
            payments: [new PaymentInput(PaymentMethod::Cash, 2_000)],
            clockOffsetSeconds: 9_999,
        ))->fresh();

        $this->assertNotNull($sale);
        $this->assertFalse($sale->needs_review);
        $this->assertNull($sale->clock_offset_seconds);
        $this->assertSame(now()->toDateTimeString(), $sale->reported_at?->toDateTimeString());
    }

    public function test_a_disabled_cashiers_sale_is_still_recorded_and_flagged(): void
    {
        $this->cashier->forceFill(['is_active' => false])->save();

        $sale = $this->replay(now()->addHours(3), 3 * 3_600);

        $this->assertSame(['cashier', 'clock'], $this->reasons($sale));
        $this->assertSame(['B1' => 99.0], $this->batchQuantities($this->panadol));
    }
}
