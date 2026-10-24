<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Exceptions\PosException;
use App\Models\Device;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use Illuminate\Support\Str;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * Users' allowed locations at checkout (docs/PHARMACY.md §4.4): a cashier
 * sells only at the locations they may use, for the till's location and
 * every line's. Online that is refused; a sale already made offline is
 * recorded and flagged.
 */
class PharmacyCheckoutLocationsTest extends BatchTestCase
{
    use WorksWithCheckout;

    private ProductVariant $panadol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->panadol = $this->medicine();
        $this->receive($this->panadol, 'G1', '03/2027', 50);
        $this->receive($this->panadol, 'D1', '03/2027', 50, $this->doctors);
    }

    /** @param  list<CartLine>  $lines */
    private function sellAt(Device $till, User $cashier, array $lines, bool $offline = false): Sale
    {
        return app(CheckoutService::class)->checkout(new CheckoutData(
            device: $till,
            cashier: $cashier,
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
            $this->assertSame($message, $exception->getMessage());
        }
    }

    public function test_a_cashier_sells_only_at_their_own_counter(): void
    {
        $gen = $this->cashierAt($this->general);
        $doc = $this->cashierAt($this->doctors);

        $this->assertRefused(fn () => $this->sellAt($this->docTill(), $gen, [new CartLine($this->panadol->getKey(), 1)]), "You can't sell at Doctor's Medicines.");
        $this->assertRefused(fn () => $this->sellAt($this->genTill(), $doc, [new CartLine($this->panadol->getKey(), 1)]), "You can't sell at General Pharmacy.");

        $this->sellAt($this->genTill(), $gen, [new CartLine($this->panadol->getKey(), 1)]);
        $this->sellAt($this->docTill(), $doc, [new CartLine($this->panadol->getKey(), 2)]);

        // The owner sells anywhere; a cashier with no allowed locations only at the
        // default location (General Pharmacy).
        $this->sellAt($this->docTill(), $this->owner, [new CartLine($this->panadol->getKey(), 1)]);
        $this->assertRefused(fn () => $this->sellAt($this->docTill(), $this->cashierAt(), [new CartLine($this->panadol->getKey(), 1)]), "You can't sell at Doctor's Medicines.");
        $this->sellAt($this->genTill(), $this->cashierAt(), [new CartLine($this->panadol->getKey(), 1)]);

        $this->assertSame(['G1' => 48.0], $this->batchQuantities($this->panadol));
        $this->assertSame(['D1' => 47.0], $this->batchQuantities($this->panadol, $this->doctors));
    }

    public function test_a_line_from_a_location_the_cashier_may_not_use_is_refused(): void
    {
        $gen = $this->cashierAt($this->general);
        $both = $this->cashierAt($this->general, $this->doctors);
        $line = new CartLine($this->panadol->getKey(), 1, locationId: (int) $this->doctors->getKey());

        $this->assertRefused(fn () => $this->sellAt($this->genTill(), $gen, [new CartLine($this->panadol->getKey(), 1), $line]), "You can't sell at Doctor's Medicines.");
        $this->assertSame(0, Sale::query()->count());

        $sale = $this->sellAt($this->genTill(), $both, [new CartLine($this->panadol->getKey(), 1), $line]);
        $this->assertSame([(int) $this->general->getKey(), (int) $this->doctors->getKey()], $sale->items->map(fn ($item): int => (int) $item->location_id)->all());
    }

    public function test_the_checkout_and_quote_endpoints_enforce_allowed_locations(): void
    {
        $gen = $this->cashierAt($this->general);
        $payload = fn (Device $till, array $line = []): array => [
            'device' => $till->uuid,
            'uuid' => (string) Str::uuid(),
            'lines' => [['variant_id' => $this->panadol->getKey(), 'quantity' => 1, ...$line]],
            'payments' => [['method' => 'cash', 'amount' => '100']],
        ];

        // A till at another counter: refused whether the request or the service catches it.
        $this->actingAs($gen)->postJson(route('pos.checkout'), $payload($this->docTill()))->assertUnprocessable();
        $this->actingAs($gen)->postJson(route('pos.quote'), $payload($this->docTill()))->assertUnprocessable();

        // A line from another counter at the cashier's own till.
        $other = ['location_id' => $this->doctors->getKey()];
        $this->actingAs($gen)->postJson(route('pos.checkout'), $payload($this->genTill(), $other))
            ->assertUnprocessable()
            ->assertJsonPath('message', "You can't sell at Doctor's Medicines.");
        $this->actingAs($gen)->postJson(route('pos.quote'), $payload($this->genTill(), $other))
            ->assertUnprocessable()
            ->assertJsonPath('message', "You can't sell at Doctor's Medicines.");
        $this->assertSame(0, Sale::query()->count());

        $this->actingAs($gen)->postJson(route('pos.checkout'), $payload($this->genTill()))->assertOk();
        $this->actingAs($this->owner)->postJson(route('pos.checkout'), $payload($this->genTill(), $other))->assertOk();
        $this->assertSame(['D1' => 49.0], $this->batchQuantities($this->panadol, $this->doctors));
    }

    public function test_an_offline_sale_at_a_location_the_cashier_may_not_use_is_recorded_and_flagged_once(): void
    {
        $gen = $this->cashierAt($this->general);

        $sale = $this->sellAt($this->docTill(), $gen, [
            new CartLine($this->panadol->getKey(), 1, tillUnitPrice: 2_000),
            new CartLine($this->panadol->getKey(), 2, tillUnitPrice: 2_000),
        ], offline: true);

        $this->assertTrue($sale->needs_review);
        $this->assertSame([['type' => 'location', 'message' => "{$gen->name} is not allowed to sell at Doctor's Medicines."]], $sale->review_reasons);
        $this->assertSame(['D1' => 47.0], $this->batchQuantities($this->panadol, $this->doctors));

        // Allowed: nothing to flag.
        $this->assertFalse($this->sellAt($this->genTill(), $gen, [new CartLine($this->panadol->getKey(), 1, tillUnitPrice: 2_000)], offline: true)->needs_review);
    }
}
