<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Models\Device;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use App\Services\LocationService;
use App\Services\ShiftService;
use App\Support\TillSelection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * A user limited to some locations can only use the tills there
 * (docs/PHARMACY.md §9, branch 8): a till elsewhere can't be chosen, a
 * remembered one is forgotten, and every till endpoint refuses it the same
 * way. The owner uses every till; a cashier with no allowed locations only
 * the default location's.
 */
class TillAccessByLocationTest extends PharmacyTestCase
{
    use WorksWithCheckout;

    private const REFUSED = "You can't use till T2: it is at Doctor's Medicines.";

    public function test_a_cashier_of_general_pharmacy_cannot_choose_the_doctors_medicines_till(): void
    {
        $doc = $this->docTill();
        $cashier = $this->cashierAt($this->general);

        $this->actingAs($cashier)
            ->postJson(route('pos.till.store'), ['device' => $doc->uuid])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['device' => self::REFUSED])
            ->assertCookieMissing(TillSelection::COOKIE);

        $this->actingAs($cashier)
            ->post(route('pos.till.store'), ['device' => $this->genTill()->uuid])
            ->assertRedirect(route('pos.index'))
            ->assertCookie(TillSelection::COOKIE);
    }

    public function test_a_cashier_of_general_pharmacy_cannot_open_a_shift_on_the_doctors_medicines_till(): void
    {
        $doc = $this->docTill();
        $cashier = $this->cashierAt($this->general);

        $this->actingAs($cashier)
            ->postJson(route('pos.shift.open'), ['device' => $doc->uuid, 'opening_cash' => '5000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['device' => self::REFUSED]);

        $this->assertSame(0, Shift::query()->count());
    }

    public function test_every_till_endpoint_refuses_a_till_the_user_may_not_use(): void
    {
        $doc = $this->docTill();
        $sale = $this->saleOn($doc);
        $cashier = $this->cashierAt($this->general);
        $as = $this->actingAs($cashier);

        $as->getJson(route('pos.shift.expected', ['device' => $doc->uuid]))
            ->assertUnprocessable()->assertJsonValidationErrors(['device' => self::REFUSED]);
        $as->postJson(route('pos.shift.close'), ['device' => $doc->uuid, 'counted_cash' => '0'])
            ->assertUnprocessable()->assertJsonValidationErrors(['device' => self::REFUSED]);
        $as->getJson(route('pos.recent-sales', ['device' => $doc->uuid]))
            ->assertUnprocessable()->assertJsonValidationErrors(['device' => self::REFUSED]);
        $as->postJson(route('pos.receipt.reprint', $sale), ['device' => $doc->uuid])
            ->assertUnprocessable()->assertJsonValidationErrors(['device' => self::REFUSED]);
        $as->getJson(route('pos.search', ['device' => $doc->uuid, 'q' => 'Panadol']))
            ->assertUnprocessable()->assertJsonValidationErrors(['device' => self::REFUSED]);
        $as->postJson(route('pos.checkout'), [
            'device' => $doc->uuid,
            'lines' => [['variant_id' => $sale->items()->firstOrFail()->product_variant_id, 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => '1500']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['device' => self::REFUSED]);

        $this->assertSame(1, Sale::query()->count());
        $this->assertTrue(Shift::query()->whereNull('closed_at')->exists(), 'The shift is still open.');
    }

    public function test_a_remembered_till_the_user_may_not_use_is_forgotten(): void
    {
        $doc = $this->docTill();
        $cashier = $this->cashierAt($this->general);

        $this->actingAs($cashier)
            ->withCookie(TillSelection::COOKIE, $doc->uuid)
            ->get(route('pos.index'))
            ->assertInertia(fn (Assert $page) => $page->component('pos/Index')->where('till', null))
            ->assertCookieExpired(TillSelection::COOKIE);

        // The same browser still opens on a till the user may use.
        $this->actingAs($cashier)
            ->withCookie(TillSelection::COOKIE, $this->genTill()->uuid)
            ->get(route('pos.index'))
            ->assertInertia(fn (Assert $page) => $page->where('till.code', 'T1'));
    }

    public function test_a_cashier_of_doctors_medicines_uses_its_till_and_the_owner_uses_every_till(): void
    {
        $doc = $this->docTill();
        $docCashier = $this->cashierAt($this->doctors);

        $this->actingAs($docCashier)
            ->postJson(route('pos.till.store'), ['device' => $doc->uuid])
            ->assertRedirect(route('pos.index'));
        $this->actingAs($docCashier)
            ->postJson(route('pos.shift.open'), ['device' => $doc->uuid, 'opening_cash' => '5000'])
            ->assertCreated();
        $this->actingAs($docCashier)
            ->postJson(route('pos.till.store'), ['device' => $this->genTill()->uuid])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['device' => "You can't use till T1: it is at General Pharmacy."]);

        foreach ([$doc, $this->genTill()] as $till) {
            $this->actingAs($this->owner)
                ->post(route('pos.till.store'), ['device' => $till->uuid])
                ->assertRedirect(route('pos.index'))
                ->assertCookie(TillSelection::COOKIE);
        }

        // A cashier with no allowed locations set uses the default location's tills only.
        $atDefault = $this->cashierAt();
        $this->actingAs($atDefault)
            ->postJson(route('pos.shift.open'), ['device' => $this->genTill()->uuid, 'opening_cash' => '0'])
            ->assertCreated();
        $this->actingAs($atDefault)
            ->postJson(route('pos.till.store'), ['device' => $doc->uuid])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['device' => self::REFUSED]);
    }

    public function test_registering_a_till_for_offline_sales_needs_its_location_too(): void
    {
        $doc = $this->docTill();
        app(LocationService::class)->setAllowedLocations($this->owner, [(int) $this->general->getKey()], $this->owner);

        $this->actingAs($this->owner)
            ->postJson(route('pos.till.register'), ['device' => $doc->uuid])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['device' => self::REFUSED]);
        $this->assertSame(0, $doc->tokens()->count());

        $this->actingAs($this->owner)
            ->postJson(route('pos.till.register'), ['device' => $this->genTill()->uuid])
            ->assertCreated()
            ->assertJsonPath('till.code', 'T1');
    }

    /** An online sale of a service on $till by the owner, in an open shift. */
    private function saleOn(Device $till): Sale
    {
        $consultation = $this->consultation(1_500);
        /** @var User $owner */
        $owner = $this->owner;
        app(ShiftService::class)->open($till, $owner, 0);

        return app(CheckoutService::class)->checkout(new CheckoutData(
            device: $till,
            cashier: $owner,
            lines: [new CartLine((int) $consultation->getKey(), 1)],
            payments: [new PaymentInput(PaymentMethod::Cash, 1_500)],
        ));
    }
}
