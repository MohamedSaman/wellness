<?php

namespace Tests\Feature\Pos;

use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use App\Services\OfflineSaleSyncService;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * The till's clock on offline sales (docs/PHARMACY.md §7.5): each upload
 * carries the till's clock (`device_time`); a sale time in the future is no
 * longer rejected. A clock out of tolerance is recorded and flagged `clock`,
 * and reports date the sale by the server's clock (`reported_at`), while
 * `sold_at` stays what the receipt printed.
 */
class TillClockSyncTest extends BatchTestCase
{
    use WorksWithCheckout;

    private string $token;

    private ProductVariant $panadol;

    private int $invoice = 0;

    protected function setUp(): void
    {
        parent::setUp();

        app(OfflineSaleSyncService::class)->reserveInvoiceBlock($this->till);
        $this->token = $this->till->issueSyncToken('General counter browser')->plainTextToken;
        $this->panadol = $this->medicine();
        $this->receive($this->panadol, 'P1', '03/2027', 50);
    }

    /**
     * One sale of two tablets, as the till uploads it.
     *
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function offlineSale(CarbonInterface $soldAt, array $override = []): array
    {
        $this->invoice++;

        return [
            'uuid' => (string) Str::uuid(),
            'invoice_no' => sprintf('T1-%06d', $this->invoice),
            'sold_at' => $soldAt->toIso8601String(),
            'cashier_id' => $this->cashier->getKey(),
            'lines' => [['variant_id' => $this->panadol->getKey(), 'quantity' => 2, 'unit_price' => 2_000]],
            'payments' => [['method' => 'cash', 'amount' => 4_000]],
            'grand_total' => 4_000,
            ...$override,
        ];
    }

    /** @param  list<array<string, mixed>>  $sales */
    private function sync(array $sales, ?string $deviceTime = null): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token)->postJson(route('pos.sync.sales'), [
            'sales' => $sales,
            ...($deviceTime === null ? [] : ['device_time' => $deviceTime]),
        ]);
    }

    /** @param  array<string, mixed>  $payload */
    private function recorded(array $payload): Sale
    {
        return Sale::query()->where('uuid', $payload['uuid'])->firstOrFail();
    }

    /** @return list<string> */
    private function reviewTypes(Sale $sale): array
    {
        return array_column($sale->review_reasons ?? [], 'type');
    }

    public function test_a_till_clock_far_ahead_is_recorded_flagged_and_reported_at_the_servers_time(): void
    {
        // The till runs 30 minutes fast; by its clock the sale was 5 minutes before sending.
        $tillNow = now()->addMinutes(30);
        $payload = $this->offlineSale($tillNow->subMinutes(5));

        $this->sync([$payload], $tillNow->toIso8601String())
            ->assertOk()
            ->assertJsonPath('results.0.status', 'synced')
            ->assertJsonPath('results.0.needs_review', true);

        $sale = $this->recorded($payload);
        $this->assertSame(['clock'], $this->reviewTypes($sale));
        $this->assertStringContainsString('30 minutes ahead', $sale->review_reasons[0]['message']);
        $this->assertSame(1_800, $sale->clock_offset_seconds);
        $this->assertTrue($sale->sold_at->equalTo($tillNow->subMinutes(5)), 'sold_at stays what the receipt printed.');
        $this->assertTrue($sale->reported_at?->equalTo(now()->subMinutes(5)), "Reported at {$sale->reported_at}");
    }

    public function test_a_till_clock_far_behind_is_flagged_and_reported_at_the_servers_time(): void
    {
        $tillNow = now()->subHours(2);
        $payload = $this->offlineSale($tillNow->subMinutes(10));

        $this->sync([$payload], $tillNow->toIso8601String())->assertJsonPath('results.0.status', 'synced');

        $sale = $this->recorded($payload);
        $this->assertSame(['clock'], $this->reviewTypes($sale));
        $this->assertStringContainsString('120 minutes behind', $sale->review_reasons[0]['message']);
        $this->assertSame(-7_200, $sale->clock_offset_seconds);
        $this->assertTrue($sale->reported_at?->equalTo(now()->subMinutes(10)), "Reported at {$sale->reported_at}");
    }

    public function test_a_clock_within_tolerance_is_stored_but_not_flagged(): void
    {
        $tillNow = now()->addMinutes(3);
        $payload = $this->offlineSale($tillNow->subHour());

        $this->sync([$payload], $tillNow->toIso8601String())->assertJsonPath('results.0.needs_review', false);

        $sale = $this->recorded($payload);
        $this->assertSame(180, $sale->clock_offset_seconds);
        $this->assertTrue($sale->reported_at?->equalTo($sale->sold_at), 'Within tolerance, reports keep the till time.');
    }

    public function test_the_offset_is_measured_once_for_every_sale_of_an_upload(): void
    {
        $tillNow = now()->addMinutes(45);
        $first = $this->offlineSale($tillNow->subHours(3));
        $second = $this->offlineSale($tillNow->subMinutes(1));

        $this->sync([$first, $second], $tillNow->toIso8601String())->assertOk();

        $this->assertSame([2_700, 2_700], [$this->recorded($first)->clock_offset_seconds, $this->recorded($second)->clock_offset_seconds]);
        $this->assertTrue($this->recorded($first)->reported_at?->equalTo(now()->subHours(3)));
    }

    public function test_the_offset_is_the_tills_clock_minus_the_servers_in_whole_seconds(): void
    {
        $this->travelTo(now()->setMicrosecond(400_000));
        $offset = fn (string $deviceTime): int => OfflineSaleSyncService::clockOffsetSeconds($deviceTime);

        $this->assertSame(0, $offset(now()->toIso8601String()));
        $this->assertSame(2_700, $offset(now()->addMinutes(45)->toIso8601ZuluString('microsecond')));
        // The same instant written in Colombo time, and a till 90.6 s behind.
        $this->assertSame(-90, $offset(now()->subMilliseconds(90_200)->setTimezone('Asia/Colombo')->format('Y-m-d\TH:i:s.uP')));
        $this->assertSame(-91, $offset(now()->subMilliseconds(90_600)->format('Y-m-d\TH:i:s.uP')));
    }

    public function test_the_tolerance_is_the_shops_setting(): void
    {
        $this->setShopSetting('till_clock_tolerance_minutes', 60);
        $tillNow = now()->addMinutes(30);
        $payload = $this->offlineSale($tillNow->subMinutes(5));

        $this->sync([$payload], $tillNow->toIso8601String())->assertJsonPath('results.0.needs_review', false);

        $this->assertSame(1_800, $this->recorded($payload)->clock_offset_seconds);
    }

    public function test_without_the_tills_clock_a_sale_time_in_the_future_is_recorded_flagged_and_reported_when_synced(): void
    {
        $payload = $this->offlineSale(now()->addHours(2));

        $this->sync([$payload])
            ->assertJsonPath('results.0.status', 'synced')
            ->assertJsonPath('results.0.needs_review', true);

        $sale = $this->recorded($payload);
        $this->assertSame(['clock'], $this->reviewTypes($sale));
        $this->assertNull($sale->clock_offset_seconds);
        $this->assertTrue($sale->sold_at->equalTo(now()->addHours(2)));
        $this->assertNotNull($sale->synced_at);
        $this->assertTrue($sale->reported_at?->equalTo($sale->synced_at), "Reported at {$sale->reported_at}, synced at {$sale->synced_at}");
    }

    public function test_a_sale_time_after_the_sale_reached_the_server_is_flagged_even_with_the_clock_right_at_upload(): void
    {
        // The till's clock was 3 days fast while offline and put right (NTP) just before the upload.
        $payload = $this->offlineSale(now()->addDays(3));

        $this->sync([$payload], now()->toIso8601String())
            ->assertJsonPath('results.0.status', 'synced')
            ->assertJsonPath('results.0.needs_review', true);

        $sale = $this->recorded($payload);
        $this->assertSame(['clock'], $this->reviewTypes($sale));
        $this->assertStringContainsString('4320 minutes after the sale reached the server', $sale->review_reasons[0]['message']);
        $this->assertSame(0, $sale->clock_offset_seconds);
        $this->assertTrue($sale->sold_at->equalTo(now()->addDays(3)), 'sold_at stays what the receipt printed.');
        $this->assertTrue($sale->reported_at?->equalTo($sale->synced_at), "Reported at {$sale->reported_at}, synced at {$sale->synced_at}");

        // Ahead of the server even after the offset: dated when it arrived, not in the future.
        $tillNow = now()->addHour();
        $ahead = $this->offlineSale($tillNow->addDays(2));
        $this->sync([$ahead], $tillNow->toIso8601String())->assertJsonPath('results.0.needs_review', true);
        $this->assertStringContainsString('60 minutes ahead', $this->recorded($ahead)->review_reasons[0]['message']);
        $this->assertTrue($this->recorded($ahead)->reported_at?->equalTo(now()));
    }

    public function test_a_sale_time_no_column_can_hold_is_recorded_flagged_and_dated_when_it_arrived(): void
    {
        // A till whose clock jumped to 2040 (past what a TIMESTAMP holds).
        $future = $this->offlineSale(now()->setDate(2040, 3, 1));
        // A sale on a right clock, uploaded after the till's clock was reset to 2000:
        // corrected by the offset it would land in 2052.
        $reset = $this->offlineSale(now()->subHour());

        $this->sync([$future], now()->setDate(2040, 3, 1)->toIso8601String())
            ->assertJsonPath('results.0.status', 'synced')
            ->assertJsonPath('results.0.needs_review', true);
        $this->sync([$reset], now()->setDate(2000, 1, 1)->toIso8601String())
            ->assertJsonPath('results.0.status', 'synced')
            ->assertJsonPath('results.0.needs_review', true);
        // A clock past what clock_offset_seconds (INT) holds is flagged all the same.
        $far = $this->offlineSale(now()->subMinutes(30));
        $this->sync([$far], now()->setDate(2200, 1, 1)->toIso8601String())
            ->assertJsonPath('results.0.status', 'synced')
            ->assertJsonPath('results.0.needs_review', true);

        $sale = $this->recorded($future)->refresh();
        $this->assertSame(['clock'], $this->reviewTypes($sale));
        $this->assertStringContainsString('The till recorded the sale at 2040-03-01', $sale->review_reasons[0]['message']);
        $this->assertTrue($sale->sold_at->equalTo($sale->synced_at), "Sold at {$sale->sold_at}");
        $this->assertTrue($sale->reported_at?->equalTo($sale->synced_at));

        $sale = $this->recorded($reset)->refresh();
        $this->assertSame(['clock'], $this->reviewTypes($sale));
        $this->assertStringContainsString('behind', $sale->review_reasons[0]['message']);
        $this->assertTrue($sale->sold_at->equalTo(now()->subHour()));
        $this->assertTrue($sale->reported_at?->equalTo($sale->synced_at), "Reported at {$sale->reported_at}");

        $sale = $this->recorded($far)->refresh();
        $this->assertSame(2_147_483_647, $sale->clock_offset_seconds);
        $this->assertTrue($sale->reported_at?->equalTo($sale->synced_at), "Reported at {$sale->reported_at}");
        $this->assertSame(['P1' => 44.0], $this->batchQuantities($this->panadol));
    }

    public function test_an_older_till_without_its_clock_is_recorded_as_before(): void
    {
        $payload = $this->offlineSale(now()->subHour());

        $this->sync([$payload])->assertJsonPath('results.0.needs_review', false);

        $sale = $this->recorded($payload);
        $this->assertNull($sale->clock_offset_seconds);
        $this->assertTrue($sale->reported_at?->equalTo($sale->sold_at));
    }

    public function test_a_tills_clock_that_is_not_a_time_with_an_offset_refuses_the_upload(): void
    {
        foreach (['yesterday', '2026-10-18 10:00:00', '2026-10-18T10:00:00', 'not a date+05:30'] as $deviceTime) {
            $this->sync([$this->offlineSale(now()->subHour())], $deviceTime)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('device_time');
        }

        $this->assertSame(0, Sale::query()->count());
    }

    public function test_a_disabled_cashier_is_still_accepted_and_flagged(): void
    {
        $this->cashier->forceFill(['is_active' => false])->save();
        $payload = $this->offlineSale(now()->subHour());

        $this->sync([$payload], now()->toIso8601String())
            ->assertJsonPath('results.0.status', 'synced')
            ->assertJsonPath('results.0.needs_review', true);

        $this->assertSame(['cashier'], $this->reviewTypes($this->recorded($payload)));
    }

    public function test_a_cashier_who_is_not_a_user_of_the_shop_is_still_rejected(): void
    {
        $other = Shop::query()->create(['name' => 'Other Shop', 'code' => 'OTHER']);
        $stranger = User::factory()->create();
        $stranger->forceFill(['shop_id' => $other->getKey()])->save();

        $this->sync([$this->offlineSale(now()->subHour(), ['cashier_id' => $stranger->getKey()])], now()->toIso8601String())
            ->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.message', 'The cashier on this sale is not a user of this shop.');

        $this->assertSame(0, Sale::query()->count());
    }
}
