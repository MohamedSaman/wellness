<?php

namespace Tests\Feature\Pos;

use App\Models\Sale;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;

/**
 * A sale an older retail till (before branch 8) left in its outbox: payload
 * v1, exactly as resources/js/lib/offline/db.ts wrote it then, uploaded
 * without device_time. It replays as it did before the branch: same prices,
 * totals and stock, nothing to review. A stray lines.*.price is ignored
 * offline; online, the same line is refused.
 */
class RetailLegacyOfflinePayloadTest extends PosTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeSecond();

        Feature::for($this->shop)->activate('module:offline_sync');
        $this->token = $this->till->issueSyncToken('Older till')->plainTextToken;
        $this->withToken($this->token)->postJson(route('pos.sync.invoice-block'))->assertCreated();
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function v1Sale(array $override = []): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'invoice_no' => 'T1-000001',
            'sold_at' => now()->subHour()->toIso8601String(),
            'cashier_id' => $this->cashier->getKey(),
            'lines' => [[
                'variant_id' => $this->variant('CPR8EA-9')->getKey(),
                'quantity' => 2,
                'unit_price' => 145_000,
                'line_discount' => 0,
                // Not part of v1: a stray key the server must not read offline.
                'price' => 1,
            ]],
            'sale_discount' => 0,
            'payments' => [['method' => 'cash', 'amount' => 300_000, 'reference' => null]],
            'grand_total' => 290_000,
            'tax_rate' => $this->shop->tax_enabled ? (float) $this->shop->tax_rate : 0,
            'prices_include_tax' => (bool) $this->shop->prices_include_tax,
            ...$override,
        ];
    }

    /** @param  list<array<string, mixed>>  $sales */
    private function upload(array $sales): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token)->postJson(route('pos.sync.sales'), ['sales' => $sales]);
    }

    public function test_a_v1_sale_with_a_stray_price_replays_exactly_as_before(): void
    {
        $plug = $this->variant('CPR8EA-9');
        $payload = $this->v1Sale();

        $this->upload([$payload])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'synced')
            ->assertJsonPath('results.0.invoice_no', 'T1-000001')
            ->assertJsonPath('results.0.needs_review', false);

        $sale = Sale::query()->with('items')->where('uuid', $payload['uuid'])->firstOrFail();
        $line = $sale->items->sole();

        $this->assertFalse($sale->needs_review);
        $this->assertNull($sale->review_reasons);
        $this->assertSame([290_000, 290_000, 300_000, 10_000], [$sale->subtotal, $sale->grand_total, $sale->paid_total, $sale->change_due]);
        $this->assertSame([145_000, 290_000, null], [$line->unit_price, $line->line_total, $line->list_price]);
        $this->assertSame((int) $this->location->getKey(), (int) $line->location_id);
        $this->assertNull($sale->clock_offset_seconds);
        $this->assertTrue($sale->reported_at->equalTo($sale->sold_at));
        $this->assertSame(38.0, $this->stockOf($plug));

        // Uploaded again: the same sale, never twice.
        $this->upload([$payload])->assertJsonPath('results.0.status', 'duplicate');
        $this->assertSame(1, Sale::query()->count());
        $this->assertSame(38.0, $this->stockOf($plug));
    }

    public function test_a_v1_sale_dated_ahead_without_a_device_time_is_recorded_and_flagged(): void
    {
        // Before branch 8 this was rejected ("The sale time is in the future"); now it is kept.
        $payload = $this->v1Sale(['sold_at' => now()->addMinutes(30)->toIso8601String()]);

        $this->upload([$payload])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'synced')
            ->assertJsonPath('results.0.needs_review', true);

        $sale = Sale::query()->where('uuid', $payload['uuid'])->firstOrFail();
        $this->assertSame(['clock'], array_column($sale->review_reasons ?? [], 'type'));
        $this->assertSame(290_000, $sale->grand_total);
        $this->assertSame(38.0, $this->stockOf($this->variant('CPR8EA-9')));
    }

    public function test_online_the_same_line_with_a_price_is_refused(): void
    {
        $plug = $this->variant('CPR8EA-9');

        $this->actingAs($this->cashier)->postJson(route('pos.checkout'), [
            'device' => $this->till->uuid,
            'uuid' => (string) Str::uuid(),
            'lines' => [['variant_id' => $plug->getKey(), 'quantity' => 2, 'line_discount' => 0, 'price' => 1]],
            'payments' => [['method' => 'cash', 'amount' => '3000']],
        ])->assertUnprocessable()->assertJsonPath('message', "{$plug->sku}'s price can't be changed at the till.");

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(40.0, $this->stockOf($plug));
    }
}
