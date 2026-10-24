<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Exceptions\PosException;
use App\Models\Device;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\VariantUnit;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use Illuminate\Support\Str;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * Selling loose per location (docs/PHARMACY.md §4.2, client answers
 * 2026-10-20): a syrup's ml sell at Doctor's Medicines and not at General
 * Pharmacy, where it goes by the bottle. Online checked at the line's
 * location; a quote without a till keeps the item-wide rule; offline the
 * sale is recorded and flagged.
 */
class PharmacyCheckoutUnitsTest extends BatchTestCase
{
    use WorksWithCheckout;

    private ProductVariant $syrup;

    private VariantUnit $bottle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->syrup = $this->syrup();
        $this->bottle = $this->syrup->units()->where('name', 'Bottle')->firstOrFail();
        $this->receive($this->syrup, 'G1', '03/2027', 600);
        $this->receive($this->syrup, 'D1', '03/2027', 600, $this->doctors);
    }

    /** @param  list<CartLine>  $lines */
    private function sellAt(Device $till, array $lines, bool $offline = false): Sale
    {
        return app(CheckoutService::class)->checkout(new CheckoutData(
            device: $till,
            cashier: $this->owner,
            lines: $lines,
            payments: [new PaymentInput(PaymentMethod::Cash, 1_000_000)],
            uuid: (string) Str::uuid(),
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
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    public function test_syrup_sells_loose_at_doctors_medicines_and_by_the_bottle_everywhere(): void
    {
        $sale = $this->sellAt($this->docTill(), [new CartLine($this->syrup->getKey(), 45)]);
        $this->assertSame(22_500, $sale->grand_total);
        $this->assertSame('ml', $sale->items->sole()->unit_name);

        $this->assertRefused(fn () => $this->sellAt($this->genTill(), [new CartLine($this->syrup->getKey(), 45)]), "{$this->syrup->sku} is not sold by the ml at General Pharmacy.");

        $this->sellAt($this->genTill(), [new CartLine($this->syrup->getKey(), 2, unitId: $this->bottle->getKey())]);
        $this->sellAt($this->docTill(), [new CartLine($this->syrup->getKey(), 1, unitId: $this->bottle->getKey())]);

        // A line from Doctor's Medicines on the General Pharmacy till sells loose there.
        $this->sellAt($this->genTill(), [new CartLine($this->syrup->getKey(), 5, locationId: (int) $this->doctors->getKey())]);

        $this->assertSame(['G1' => 480.0], $this->batchQuantities($this->syrup));
        $this->assertSame(['D1' => 600.0 - 45 - 60 - 5], $this->batchQuantities($this->syrup, $this->doctors));
    }

    public function test_part_units_stay_refused_online(): void
    {
        $this->assertRefused(fn () => $this->sellAt($this->docTill(), [new CartLine($this->syrup->getKey(), 2.5)]), '2.5 ml is not allowed');
        $this->assertRefused(fn () => $this->sellAt($this->genTill(), [new CartLine($this->syrup->getKey(), 0.5, unitId: $this->bottle->getKey())]), '0.5 Bottle is not allowed');
        $this->assertSame(0, Sale::query()->count());
    }

    public function test_the_endpoints_check_the_unit_at_the_tills_location(): void
    {
        $payload = fn (Device $till, array $line): array => [
            'device' => $till->uuid,
            'uuid' => (string) Str::uuid(),
            'lines' => [['variant_id' => $this->syrup->getKey(), ...$line]],
            'payments' => [['method' => 'cash', 'amount' => '1000']],
        ];
        $ml = ['quantity' => 30];
        // The unit at each till's location, not who may use it: the cashier may use both.
        $this->allowAt($this->cashier, $this->general, $this->doctors);

        $this->actingAs($this->cashier)->postJson(route('pos.quote'), $payload($this->docTill(), $ml))
            ->assertOk()
            ->assertJsonPath('totals.grand_total', 15_000)
            ->assertJsonPath('lines.0.unit_name', 'ml')
            ->assertJsonPath('lines.0.base_quantity', 30);
        $this->actingAs($this->cashier)->postJson(route('pos.checkout'), $payload($this->docTill(), $ml))->assertOk();

        foreach (['pos.quote', 'pos.checkout'] as $route) {
            $this->actingAs($this->cashier)->postJson(route($route), $payload($this->genTill(), $ml))
                ->assertUnprocessable()
                ->assertJsonPath('message', "{$this->syrup->sku} is not sold by the ml at General Pharmacy.");
        }

        $bottle = ['quantity' => 1, 'unit_id' => $this->bottle->getKey()];
        $this->actingAs($this->cashier)->postJson(route('pos.checkout'), $payload($this->genTill(), $bottle))->assertOk();
        $this->actingAs($this->cashier)->postJson(route('pos.quote'), $payload($this->genTill(), ['quantity' => 0.5, 'unit_id' => $this->bottle->getKey()]))
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, '0.5 Bottle is not allowed'));

        // Without a till the quote checks the unit as sold anywhere (as before): ml is not, the bottle is.
        $withoutTill = fn (array $line): array => ['lines' => [['variant_id' => $this->syrup->getKey(), ...$line]]];
        $this->actingAs($this->cashier)->postJson(route('pos.quote'), $withoutTill($ml))
            ->assertUnprocessable()
            ->assertJsonPath('message', "{$this->syrup->sku} is not sold by the ml.");
        $this->actingAs($this->cashier)->postJson(route('pos.quote'), $withoutTill($bottle))
            ->assertOk()
            ->assertJsonPath('totals.grand_total', 25_000)
            ->assertJsonPath('lines.0.batches', []);
    }

    public function test_ml_sold_offline_where_it_is_not_sold_loose_is_recorded_and_flagged(): void
    {
        $sale = $this->sellAt($this->genTill(), [
            new CartLine($this->syrup->getKey(), 20, tillUnitPrice: 500, unitId: (int) $this->syrup->units()->where('is_base', true)->value('id')),
            new CartLine($this->syrup->getKey(), 1, tillUnitPrice: 25_000, unitId: $this->bottle->getKey()),
        ], offline: true);

        $this->assertTrue($sale->needs_review);
        $this->assertSame([[
            'type' => 'unit',
            'sku' => $this->syrup->sku,
            'message' => "{$this->syrup->sku} was sold by the ml at General Pharmacy, where it is not sold loose.",
        ]], $sale->review_reasons);
        $this->assertSame(['G1' => 520.0], $this->batchQuantities($this->syrup));

        // A sale from an older till names no unit: replayed in the base unit as before, without the flag.
        $old = $this->sellAt($this->genTill(), [new CartLine($this->syrup->getKey(), 10, tillUnitPrice: 500)], offline: true);
        $this->assertFalse($old->needs_review);
        $this->assertSame(['G1' => 510.0], $this->batchQuantities($this->syrup));
    }
}
