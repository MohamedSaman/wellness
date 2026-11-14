<?php

namespace Tests\Feature\Pos;

use App\Models\StockLevel;
use App\Support\TillSelection;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Till search by location in the retail shop (one location, no
 * multi_location): no location picker, a `scope` from the till is ignored,
 * an item out of stock is still listed and the payload has no stock per
 * location. Search behaves exactly as before.
 */
class RetailTillSearchLocationTest extends PosTestCase
{
    public function test_the_retail_till_has_no_location_search(): void
    {
        $this->actingAs($this->cashier)
            ->withCookie(TillSelection::COOKIE, $this->till->uuid)
            ->get(route('pos.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('features.locationSearch', false)->where('searchLocations', []));
    }

    public function test_a_scope_is_ignored_and_out_of_stock_items_are_still_listed(): void
    {
        $plug = $this->variant('CPR8EA-9');
        StockLevel::query()->where('product_variant_id', $plug->getKey())->update(['quantity' => 0]);

        foreach ([[], ['scope' => 'here'], ['scope' => 'all'], ['scope' => '999999']] as $scope) {
            $response = $this->actingAs($this->cashier)
                ->getJson(route('pos.search', ['device' => $this->till->uuid, 'q' => $plug->sku, ...$scope]))
                ->assertOk()
                ->assertJsonPath('exact.variant_id', $plug->getKey())
                ->assertJsonPath('results.0.variant_id', $plug->getKey())
                ->assertJsonPath('results.0.stock', 0)
                ->assertJsonPath('results.0.held_back', null)
                ->assertJsonMissingPath('results.0.stock_by_location')
                ->assertJsonMissingPath('exact.stock_by_location');

            $this->assertCount(1, $response->json('results'));
        }
    }
}
