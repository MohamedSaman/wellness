<?php

namespace Tests\Feature\Pos;

use App\Exceptions\PosException;
use App\Models\Device;
use App\Models\Location;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\LocationService;
use App\Services\ModuleService;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Users with no allowed locations in a retail shop (docs/PHARMACY.md §4.4):
 * without multi_location nothing changes (every location, as before); a
 * shop that turns multi_location on and opens a second location keeps a
 * cashier with no allowed locations at the default location, while the
 * owner uses both.
 */
class RetailDefaultLocationTest extends PosTestCase
{
    public function test_without_multi_location_a_cashier_is_not_limited(): void
    {
        $this->assertFalse($this->shop->hasModule('multi_location'));
        $this->assertSame([], $this->cashier->allowedLocationIds());
        $this->assertNull($this->cashier->usableLocationIds());
        $this->assertTrue($this->cashier->mayUseLocation($this->location));

        // Even a location created behind the module's back (no screen does that) is not refused.
        $other = Location::query()->create(['name' => 'Back room', 'code' => 'BACK']);
        $this->assertTrue($this->cashier->mayUseLocation($other));

        $plug = $this->variant('CPR8EA-9');
        $sale = app(CheckoutService::class)->checkout($this->cart([new CartLine($plug->getKey(), 1)]));
        $this->assertSame($this->location->getKey(), $sale->items->sole()->location_id);
    }

    public function test_with_multi_location_and_a_second_location_a_cashier_with_none_set_stays_at_the_default(): void
    {
        app(ModuleService::class)->enable($this->shop, 'multi_location');
        $branch = app(LocationService::class)->create(['name' => 'Branch', 'code' => 'BRANCH'], $this->owner);
        $branchTill = Device::query()->create(['location_id' => $branch->getKey(), 'name' => 'Branch counter', 'code' => 'T2']);

        $this->assertSame([(int) $this->location->getKey()], $this->cashier->usableLocationIds());
        $this->assertNull($this->owner->usableLocationIds());

        $this->actingAs($this->cashier)->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page
            ->has('tills', 1)
            ->where('tills.0.code', 'T1'));
        $this->actingAs($this->cashier)
            ->postJson(route('pos.till.store'), ['device' => $branchTill->uuid])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['device' => "You can't use till T2: it is at Branch."]);
        $this->actingAs($this->owner)->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page->has('tills', 2));

        $plug = $this->variant('CPR8EA-9');

        try {
            app(CheckoutService::class)->checkout($this->cart([new CartLine($plug->getKey(), 1, locationId: (int) $branch->getKey())]));
            $this->fail('A cashier with no allowed locations sold from the branch.');
        } catch (PosException $exception) {
            $this->assertSame("You can't sell at Branch.", $exception->getMessage());
        }

        // At the default location the cashier sells as before.
        $this->assertSame(1, app(CheckoutService::class)->checkout($this->cart([new CartLine($plug->getKey(), 1)]))->items->count());
    }
}
