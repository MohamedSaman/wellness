<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Exceptions\PosException;
use App\Models\Device;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use App\Services\OfflineSaleSyncService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * Users with no allowed locations in a shop with multi_location
 * (docs/PHARMACY.md §4.4): a cashier works at the shop's default location
 * only (General Pharmacy); the owner, or anyone given locations.all, at
 * every location. Allowed locations, once set, limit everyone (the owner too).
 */
class DefaultLocationAccessTest extends BatchTestCase
{
    use WorksWithCheckout;

    private const REFUSED = "You can't use till T2: it is at Doctor's Medicines.";

    private ProductVariant $consultation;

    protected function setUp(): void
    {
        parent::setUp();

        // A service (no stock): the sales below test who may sell where, not stock.
        $this->consultation = $this->consultation(150_000);
        $this->docTill(opd: false);
    }

    /** @return array<string, mixed> */
    private function checkoutPayload(Device $till): array
    {
        return [
            'device' => $till->uuid,
            'uuid' => (string) Str::uuid(),
            'lines' => [['variant_id' => $this->consultation->getKey(), 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => '1500']],
        ];
    }

    private function sellAt(Device $till, User $user): Sale
    {
        return app(CheckoutService::class)->checkout(new CheckoutData(
            device: $till,
            cashier: $user,
            lines: [new CartLine((int) $this->consultation->getKey(), 1)],
            payments: [new PaymentInput(PaymentMethod::Cash, 150_000)],
        ));
    }

    private function tillCodes(User $user): TestResponse
    {
        return $this->actingAs($user)->get(route('pos.index'));
    }

    public function test_a_cashier_with_no_allowed_locations_uses_the_default_locations_till(): void
    {
        $this->assertSame([], $this->cashier->allowedLocationIds());

        $this->tillCodes($this->cashier)->assertInertia(fn (Assert $page) => $page
            ->has('tills', 1)
            ->where('tills.0.code', 'T1'));

        $this->actingAs($this->cashier)
            ->post(route('pos.till.store'), ['device' => $this->genTill()->uuid])
            ->assertRedirect(route('pos.index'));
        $this->actingAs($this->cashier)
            ->postJson(route('pos.shift.open'), ['device' => $this->genTill()->uuid, 'opening_cash' => '0'])
            ->assertCreated();
        $this->actingAs($this->cashier)
            ->postJson(route('pos.checkout'), $this->checkoutPayload($this->genTill()))
            ->assertOk();

        $this->assertSame(1, Sale::query()->where('device_id', $this->genTill()->getKey())->count());
    }

    public function test_a_cashier_with_no_allowed_locations_cannot_use_another_counter(): void
    {
        $doc = $this->docTill(opd: false);

        $this->actingAs($this->cashier)
            ->postJson(route('pos.till.store'), ['device' => $doc->uuid])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['device' => self::REFUSED]);
        $this->actingAs($this->cashier)
            ->postJson(route('pos.shift.open'), ['device' => $doc->uuid, 'opening_cash' => '0'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['device' => self::REFUSED]);
        $this->actingAs($this->cashier)
            ->postJson(route('pos.checkout'), $this->checkoutPayload($doc))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['device' => self::REFUSED]);

        // The service refuses it too, whoever calls it.
        try {
            $this->sellAt($doc, $this->cashier);
            $this->fail('A cashier with no allowed locations sold at Doctor\'s Medicines.');
        } catch (PosException $exception) {
            $this->assertSame("You can't sell at Doctor's Medicines.", $exception->getMessage());
        }

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(0, Shift::query()->count());
    }

    public function test_an_offline_sale_at_another_counter_is_recorded_and_flagged(): void
    {
        $doc = $this->docTill(opd: false);
        app(OfflineSaleSyncService::class)->reserveInvoiceBlock($doc);
        $payload = [
            'uuid' => (string) Str::uuid(),
            'invoice_no' => 'T2-000001',
            'sold_at' => now()->subMinutes(20)->toIso8601String(),
            'cashier_id' => $this->cashier->getKey(),
            'lines' => [['variant_id' => $this->consultation->getKey(), 'quantity' => 1, 'unit_price' => 150_000, 'line_discount' => 0]],
            'payments' => [['method' => 'cash', 'amount' => 150_000]],
            'grand_total' => 150_000,
        ];

        $this->withToken($doc->issueSyncToken('Counter browser')->plainTextToken)
            ->postJson(route('pos.sync.sales'), ['sales' => [$payload], 'device_time' => now()->toIso8601String()])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'synced')
            ->assertJsonPath('results.0.needs_review', true);

        $sale = Sale::query()->where('uuid', $payload['uuid'])->firstOrFail();
        $this->assertSame(150_000, $sale->grand_total);
        $this->assertSame([['type' => 'location', 'message' => "{$this->cashier->name} is not allowed to sell at Doctor's Medicines."]], $sale->review_reasons);
    }

    public function test_the_owner_with_no_allowed_locations_uses_every_counter(): void
    {
        $this->tillCodes($this->owner)->assertInertia(fn (Assert $page) => $page
            ->has('tills', 2)
            ->where('tills.0.code', 'T1')
            ->where('tills.1.code', 'T2'));

        $this->actingAs($this->owner)->postJson(route('pos.checkout'), $this->checkoutPayload($this->docTill(opd: false)))->assertOk();
        $this->actingAs($this->owner)->postJson(route('pos.checkout'), $this->checkoutPayload($this->genTill()))->assertOk();
        $this->assertSame(2, Sale::query()->count());
    }

    public function test_the_owner_with_allowed_locations_is_limited_to_them(): void
    {
        $this->allowAt($this->owner, $this->doctors);

        $this->tillCodes($this->owner)->assertInertia(fn (Assert $page) => $page
            ->has('tills', 1)
            ->where('tills.0.code', 'T2'));

        $this->actingAs($this->owner)
            ->postJson(route('pos.checkout'), $this->checkoutPayload($this->genTill()))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['device' => "You can't use till T1: it is at General Pharmacy."]);
        $this->actingAs($this->owner)->postJson(route('pos.checkout'), $this->checkoutPayload($this->docTill(opd: false)))->assertOk();
    }

    public function test_a_cashier_given_locations_all_uses_every_counter(): void
    {
        $cashier = $this->cashierAt();
        $cashier->givePermissionTo('locations.all');

        $this->tillCodes($cashier)->assertInertia(fn (Assert $page) => $page->has('tills', 2));
        $this->actingAs($cashier)->postJson(route('pos.checkout'), $this->checkoutPayload($this->docTill(opd: false)))->assertOk();
        $this->assertSame(1, Sale::query()->count());
    }
}
