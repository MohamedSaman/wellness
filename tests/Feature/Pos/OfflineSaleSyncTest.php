<?php

namespace Tests\Feature\Pos;

use App\Models\Device;
use App\Models\Location;
use App\Models\PriceList;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\Shop;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\ReportService;
use App\Support\CurrentShop;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

class OfflineSaleSyncTest extends PosTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Sale times, shift windows and the "not in the future" check all read the clock:
        // freeze it on a whole second (the database stores whole seconds).
        $this->freezeSecond();

        Feature::for($this->shop)->activate('module:offline_sync');
        $this->token = $this->till->issueSyncToken('Front counter browser')->plainTextToken;
    }

    /** @return array<string, mixed> */
    private function offlineSale(array $override = []): array
    {
        $plug = $this->variant('CPR8EA-9');

        return [
            'uuid' => (string) Str::uuid(),
            'invoice_no' => 'T1-000001',
            'sold_at' => now()->subHour()->toIso8601String(),
            'cashier_id' => $this->cashier->getKey(),
            'lines' => [['variant_id' => $plug->getKey(), 'quantity' => 2, 'unit_price' => 145_000, 'line_discount' => 0]],
            'sale_discount' => 0,
            'payments' => [['method' => 'cash', 'amount' => 300_000]],
            'grand_total' => 290_000,
            ...$override,
        ];
    }

    /** @param  list<array<string, mixed>>  $sales */
    private function sync(array $sales, ?string $token = null): TestResponse
    {
        // Each real request gets a fresh guard; in a test the first till would be remembered.
        $this->app['auth']->forgetGuards();

        return $this->withToken($token ?? $this->token)->postJson(route('pos.sync.sales'), ['sales' => $sales]);
    }

    private function reserveBlock(): TestResponse
    {
        return $this->withToken($this->token)->postJson(route('pos.sync.invoice-block'));
    }

    /** A second shop with its own till, price list and device token. */
    private function otherShopToken(): string
    {
        $other = Shop::query()->create(['name' => 'Other Shop', 'code' => 'OTHER']);

        return app(CurrentShop::class)->runAs($other, function (): string {
            $location = Location::query()->create(['name' => 'Main', 'code' => 'MAIN', 'is_default' => true]);
            PriceList::query()->create(['name' => 'Retail', 'code' => 'RETAIL', 'is_default' => true]);
            $till = Device::query()->create(['location_id' => $location->getKey(), 'name' => 'Counter', 'code' => 'T1']);

            return $till->issueSyncToken('other')->plainTextToken;
        });
    }

    public function test_an_offline_sale_is_recorded_with_the_tills_invoice_number_time_and_cashier(): void
    {
        $this->reserveBlock()->assertCreated();
        $soldAt = now()->subHour();
        $sale = $this->offlineSale(['sold_at' => $soldAt->toIso8601String()]);

        $this->assertMatchesRegularExpression('/(Z|[+-]\d{2}:\d{2})$/', $sale['sold_at'], 'sold_at must carry an explicit offset');

        $this->sync([$sale])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'synced')
            ->assertJsonPath('results.0.invoice_no', 'T1-000001')
            ->assertJsonPath('results.0.needs_review', false);

        $recorded = Sale::query()->where('uuid', $sale['uuid'])->firstOrFail();

        $this->assertSame('T1-000001', $recorded->invoice_no);
        $this->assertSame($this->cashier->getKey(), $recorded->user_id);
        $this->assertTrue($recorded->sold_at->equalTo($soldAt), "Stored {$recorded->sold_at}, sent {$sale['sold_at']}");
        $this->assertNotNull($recorded->synced_at);
        $this->assertSame(290_000, $recorded->grand_total);
        $this->assertSame(10_000, $recorded->change_due);
        $this->assertSame(38.0, $this->stockOf($this->variant('CPR8EA-9')));
    }

    public function test_uploading_the_same_sale_again_returns_it_as_a_duplicate_and_records_it_once(): void
    {
        $this->reserveBlock();
        $sale = $this->offlineSale();

        $this->sync([$sale])->assertJsonPath('results.0.status', 'synced');
        $this->sync([$sale, $sale])
            ->assertJsonPath('results.0.status', 'duplicate')
            ->assertJsonPath('results.1.status', 'duplicate')
            ->assertJsonPath('results.0.invoice_no', 'T1-000001');

        $this->assertSame(1, Sale::query()->count());
        $this->assertSame(38.0, $this->stockOf($this->variant('CPR8EA-9')));
    }

    public function test_requests_without_a_valid_device_token_are_refused(): void
    {
        $this->postJson(route('pos.sync.sales'), ['sales' => [$this->offlineSale()]])->assertUnauthorized();
        $this->sync([$this->offlineSale()], 'not-a-real-token')->assertUnauthorized();
        $this->sync([$this->offlineSale()], '999|'.Str::random(40))->assertUnauthorized();

        $this->assertSame(0, Sale::query()->count());
    }

    public function test_a_signed_in_browser_session_is_not_a_device_token(): void
    {
        $this->actingAs($this->owner)
            ->postJson(route('pos.sync.sales'), ['sales' => [$this->offlineSale()]])
            ->assertUnauthorized();
    }

    public function test_a_revoked_token_can_no_longer_sync(): void
    {
        $this->till->tokens()->delete();

        $this->sync([$this->offlineSale()])->assertUnauthorized();
        $this->assertSame(0, Sale::query()->count());
    }

    public function test_a_disabled_till_cannot_sync(): void
    {
        $this->till->update(['is_active' => false]);

        $this->sync([$this->offlineSale()])->assertForbidden();
    }

    public function test_sync_is_hidden_while_the_offline_module_is_off(): void
    {
        Feature::for($this->shop)->deactivate('module:offline_sync');

        $this->sync([$this->offlineSale()])->assertNotFound();
    }

    public function test_a_till_of_another_shop_cannot_sell_this_shops_items(): void
    {
        $otherToken = $this->otherShopToken();
        Feature::for(Shop::query()->where('code', 'OTHER')->firstOrFail())->activate('module:offline_sync');

        // Other shop's till, this shop's cashier and item ids.
        $this->sync([$this->offlineSale()], $otherToken)
            ->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.message', 'The cashier on this sale is not a user of this shop.');

        $this->assertSame(0, Sale::query()->withoutGlobalScope('shop')->count());
        $this->assertSame(40.0, $this->stockOf($this->variant('CPR8EA-9')));
    }

    public function test_a_sale_id_already_used_in_another_shop_is_rejected(): void
    {
        $otherToken = $this->otherShopToken();
        $otherShop = Shop::query()->where('code', 'OTHER')->firstOrFail();
        Feature::for($otherShop)->activate('module:offline_sync');
        $this->reserveBlock();

        $sale = $this->offlineSale();
        $this->sync([$sale])->assertJsonPath('results.0.status', 'synced');

        $otherCashier = User::factory()->create(['email' => 'other@pos.test']);
        $otherCashier->forceFill(['shop_id' => $otherShop->getKey()])->save();

        $this->sync([[...$sale, 'cashier_id' => $otherCashier->getKey()]], $otherToken)
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.message', 'This sale id belongs to another till.');
    }

    public function test_offline_sales_may_take_stock_below_zero(): void
    {
        $this->reserveBlock();

        $this->sync([$this->offlineSale([
            'lines' => [['variant_id' => $this->variant('CPR8EA-9')->getKey(), 'quantity' => 50, 'unit_price' => 145_000]],
            'payments' => [['method' => 'cash', 'amount' => 7_250_000]],
            'grand_total' => 7_250_000,
        ])])->assertJsonPath('results.0.status', 'synced');

        $this->assertSame(-10.0, $this->stockOf($this->variant('CPR8EA-9')));
    }

    public function test_one_invalid_sale_does_not_stop_the_rest_of_the_batch(): void
    {
        $this->reserveBlock();

        $this->sync([
            $this->offlineSale(['lines' => []]),
            $this->offlineSale(['invoice_no' => 'T1-000002']),
        ])
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.message', 'The lines field is required.')
            ->assertJsonPath('results.1.status', 'synced');

        $this->assertSame(1, Sale::query()->count());
    }

    public function test_a_till_reserves_blocks_of_invoice_numbers_that_online_sales_skip(): void
    {
        config(['pos.offline.invoice_block_size' => 100]);

        $this->reserveBlock()->assertCreated()
            ->assertJsonPath('block.code', 'T1')
            ->assertJsonPath('block.first', 1)
            ->assertJsonPath('block.last', 100);
        $this->reserveBlock()
            ->assertJsonPath('block.first', 101)
            ->assertJsonPath('block.last', 200);

        $online = app(CheckoutService::class)->checkout($this->cart([new CartLine($this->variant('CPR8EA-9')->getKey(), 1)]));
        $this->assertSame('T1-000201', $online->invoice_no);

        $this->sync([$this->offlineSale(['invoice_no' => 'T1-000150'])])
            ->assertJsonPath('results.0.invoice_no', 'T1-000150')
            ->assertJsonPath('results.0.needs_review', false);
    }

    public function test_an_invoice_number_already_used_is_renumbered_and_flagged_instead_of_lost(): void
    {
        $this->reserveBlock();
        $this->sync([$this->offlineSale()]);

        $second = $this->offlineSale(); // same T1-000001, different sale
        $response = $this->sync([$second])
            ->assertJsonPath('results.0.status', 'synced')
            ->assertJsonPath('results.0.needs_review', true);

        $recorded = Sale::query()->where('uuid', $second['uuid'])->firstOrFail();

        $this->assertNotSame('T1-000001', $response->json('results.0.invoice_no'));
        $this->assertSame($response->json('results.0.invoice_no'), $recorded->invoice_no);
        $this->assertSame('invoice', $recorded->review_reasons[0]['type']);
        $this->assertSame('T1-000001', $recorded->review_reasons[0]['till_invoice_no']);
    }

    public function test_an_invoice_number_outside_the_tills_blocks_is_kept_but_flagged(): void
    {
        $this->sync([$this->offlineSale(['invoice_no' => 'T1-000777'])])
            ->assertJsonPath('results.0.invoice_no', 'T1-000777')
            ->assertJsonPath('results.0.needs_review', true);

        // Online numbering moves past it, so it is never issued twice.
        $online = app(CheckoutService::class)->checkout($this->cart([new CartLine($this->variant('CPR8EA-9')->getKey(), 1)]));
        $this->assertSame('T1-000778', $online->invoice_no);
    }

    public function test_a_price_changed_since_the_tills_catalog_keeps_what_the_customer_paid_and_is_flagged(): void
    {
        $this->reserveBlock();
        $plug = $this->variant('CPR8EA-9');
        $plug->prices()->update(['price' => 160_000]);

        $sale = $this->offlineSale();
        $this->sync([$sale])
            ->assertJsonPath('results.0.status', 'synced')
            ->assertJsonPath('results.0.needs_review', true);

        $recorded = Sale::query()->where('uuid', $sale['uuid'])->with('items')->firstOrFail();

        $this->assertSame(145_000, $recorded->items->first()->unit_price);
        $this->assertSame(290_000, $recorded->grand_total);
        $this->assertTrue($recorded->needs_review);
        // MySQL's JSON columns may return the keys in another order; the values are what matter.
        $reason = array_diff_key($recorded->review_reasons[0], ['message' => true]);
        ksort($reason);
        $this->assertSame([
            'server_price' => 160_000,
            'sku' => $plug->sku,
            'till_price' => 145_000,
            'type' => 'price',
        ], $reason);
        $this->assertSame(1, Sale::query()->awaitingReview()->count());
    }

    public function test_an_offline_sale_joins_the_shift_that_was_open_when_it_was_sold(): void
    {
        $this->reserveBlock();
        $now = now();
        $earlier = Shift::query()->create([
            'device_id' => $this->till->getKey(), 'user_id' => $this->cashier->getKey(),
            'opened_at' => $now->copy()->subHours(3), 'closed_at' => $now->copy()->subMinutes(30), 'opening_cash' => 0,
        ]);
        Shift::query()->create([
            'device_id' => $this->till->getKey(), 'user_id' => $this->cashier->getKey(),
            'opened_at' => $now->copy()->subMinutes(20), 'opening_cash' => 0,
        ]);

        // Sold an hour ago: inside the earlier shift, before the current one opened.
        $sale = $this->offlineSale(['sold_at' => $now->copy()->subHour()->toIso8601String()]);
        $this->sync([$sale])->assertJsonPath('results.0.needs_review', true);

        $recorded = Sale::query()->where('uuid', $sale['uuid'])->firstOrFail();
        $this->assertSame($earlier->getKey(), $recorded->shift_id);
        $this->assertSame('shift', $recorded->review_reasons[0]['type']);
    }

    public function test_an_offline_sale_keeps_the_tax_rule_the_till_charged_and_flags_a_changed_shop_setting(): void
    {
        $this->reserveBlock();
        $this->shop->update(['tax_enabled' => true, 'tax_rate' => 10, 'prices_include_tax' => false]);
        $plug = $this->variant('CPR8EA-9');
        $oil = $this->variant('MOTUL');

        // The till still had 8% added tax from its last catalog sync.
        $sale = $this->offlineSale([
            'lines' => [
                ['variant_id' => $plug->getKey(), 'quantity' => 2, 'unit_price' => 145_000],
                ['variant_id' => $oil->getKey(), 'quantity' => 1, 'unit_price' => 320_000],
            ],
            'tax_rate' => 8,
            'prices_include_tax' => false,
            'payments' => [['method' => 'cash', 'amount' => 700_000]],
            'grand_total' => 658_800,
        ]);

        $this->sync([$sale])->assertJsonPath('results.0.status', 'synced');

        $recorded = Sale::query()->where('uuid', $sale['uuid'])->firstOrFail();

        $this->assertSame(658_800, $recorded->grand_total);
        $this->assertSame(48_800, $recorded->tax_total);
        $this->assertSame('8.00', $recorded->tax_rate);
        $this->assertSame(['tax'], array_column($recorded->review_reasons, 'type'));
    }

    public function test_a_colombo_sale_time_is_stored_in_utc_and_shown_in_shop_time_without_an_hour_shift(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-16 06:00:00', 'UTC')); // 11:30 in Colombo
        $this->shop->update(['timezone' => 'Asia/Colombo']);
        $this->reserveBlock();

        $soldAt = '2026-09-16T09:45:30+05:30'; // what a till in Colombo sends
        $sale = $this->offlineSale(['sold_at' => $soldAt]);

        $this->sync([$sale])->assertJsonPath('results.0.status', 'synced');

        // Stored in UTC: 09:45:30 at +05:30 is 04:15:30 UTC.
        $this->assertSame('2026-09-16 04:15:30', DB::table('sales')->where('uuid', $sale['uuid'])->value('sold_at'));

        $recorded = Sale::query()->where('uuid', $sale['uuid'])->firstOrFail();
        $this->assertTrue($recorded->sold_at->equalTo(CarbonImmutable::parse($soldAt)));

        // Shown back in the shop's timezone exactly as it was sold.
        $this->actingAs($this->cashier)->get(route('pos.receipt', $recorded))
            ->assertInertia(fn (Assert $page) => $page->where('sale.soldAt', '2026-09-16 09:45'));
        $this->assertSame(1, app(ReportService::class)->summary(ReportPeriod::today($this->shop->fresh()))['sales_count']);
    }

    public function test_a_sale_time_without_a_timezone_offset_is_rejected(): void
    {
        $this->reserveBlock();

        $this->sync([$this->offlineSale(['sold_at' => '2026-09-16 09:45:30'])])
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.0.message', 'The sale time must include a timezone offset, e.g. 2026-09-16T09:45:30+05:30.');

        $this->assertSame(0, Sale::query()->count());
    }
}
