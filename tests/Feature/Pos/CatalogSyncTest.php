<?php

namespace Tests\Feature\Pos;

use App\Models\Device;
use App\Models\Location;
use App\Models\PriceList;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Services\StockService;
use App\Support\CurrentShop;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

class CatalogSyncTest extends PosTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Feature::for($this->shop)->activate('module:offline_sync');
        $this->token = $this->till->issueSyncToken('Front counter browser')->plainTextToken;
    }

    /** @param  array<string, mixed>  $query */
    private function catalog(array $query = [], ?string $token = null): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token ?? $this->token)->getJson(route('pos.sync.catalog', $query));
    }

    public function test_a_full_sync_returns_every_sellable_item_with_price_and_stock_but_never_cost(): void
    {
        $plug = $this->variant('CPR8EA-9');
        $sellable = ProductVariant::query()->where('is_active', true)->count();

        $response = $this->catalog()
            ->assertOk()
            ->assertJsonPath('full', true)
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('till.code', 'T1')
            ->assertJsonPath('shop.currency', $this->shop->currency)
            ->assertJsonPath('shop.invoicePadding', 6)
            ->assertJsonCount($sellable, 'items')
            ->assertJsonPath('deleted', []);

        $item = collect($response->json('items'))->firstWhere('variant_id', $plug->getKey());

        $this->assertSame(145_000, $item['price']);
        $this->assertSame(40, $item['stock']);
        $this->assertSame($plug->barcode, $item['barcode']);
        $this->assertNotNull($response->json('vehicles'));
        $this->assertStringNotContainsString('cost', strtolower($response->getContent()));
    }

    public function test_a_sync_since_the_last_cursor_returns_only_changes_and_deletions(): void
    {
        // The cursor overlaps the previous sync by a minute, so start well after the demo data was seeded.
        $this->travel(5)->minutes();
        $cursor = $this->catalog()->json('cursor');
        $this->travel(5)->minutes();

        $plug = $this->variant('CPR8EA-9');
        $plug->prices()->update(['price' => 150_000]);
        $retired = $this->variant('BP-');
        $retired->update(['is_active' => false]);

        $response = $this->catalog(['since' => $cursor])
            ->assertOk()
            ->assertJsonPath('full', false)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.variant_id', $plug->getKey())
            ->assertJsonPath('items.0.price', 150_000)
            ->assertJsonPath('deleted', [$retired->getKey()])
            ->assertJsonPath('vehicles', null);

        $this->assertNotSame($cursor, $response->json('cursor'));
    }

    public function test_stock_changes_are_included_in_the_next_sync(): void
    {
        // The cursor overlaps the previous sync by a minute, so start well after the demo data was seeded.
        $this->travel(5)->minutes();
        $cursor = $this->catalog()->json('cursor');
        $this->travel(5)->minutes();

        $plug = $this->variant('CPR8EA-9');
        app(StockService::class)->stockIn($plug, $this->location, 5, $this->owner);

        $this->catalog(['since' => $cursor])
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.stock', 45);
    }

    public function test_the_catalog_needs_a_valid_device_token(): void
    {
        $this->catalog([], 'nope')->assertUnauthorized();

        $this->till->tokens()->delete();
        $this->catalog()->assertUnauthorized();
    }

    public function test_a_till_only_ever_receives_its_own_shops_catalog(): void
    {
        $other = Shop::query()->create(['name' => 'Other Shop', 'code' => 'OTHER']);
        Feature::for($other)->activate('module:offline_sync');

        $otherToken = app(CurrentShop::class)->runAs($other, function (): string {
            $location = Location::query()->create(['name' => 'Main', 'code' => 'MAIN', 'is_default' => true]);
            PriceList::query()->create(['name' => 'Retail', 'code' => 'RETAIL', 'is_default' => true]);

            return Device::query()->create(['location_id' => $location->getKey(), 'name' => 'Counter', 'code' => 'X1'])
                ->issueSyncToken('other')->plainTextToken;
        });

        $this->catalog([], $otherToken)
            ->assertOk()
            ->assertJsonPath('till.code', 'X1')
            ->assertJsonPath('shop.name', 'Other Shop')
            ->assertJsonCount(0, 'items');
    }

    public function test_an_owner_registers_this_browser_as_a_till_and_gets_a_working_token(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson(route('pos.till.register'), ['device' => $this->till->uuid])
            ->assertCreated()
            ->assertJsonPath('till.code', 'T1');

        $this->catalog([], $response->json('token'))->assertOk();
        $this->assertSame(2, $this->till->tokens()->count());
    }

    public function test_a_cashier_cannot_register_a_till(): void
    {
        $this->actingAs($this->cashier)
            ->postJson(route('pos.till.register'), ['device' => $this->till->uuid])
            ->assertForbidden();

        $this->assertSame(1, $this->till->tokens()->count());
    }

    public function test_the_checkout_page_tells_the_till_whether_offline_sales_are_on_and_who_may_set_them_up(): void
    {
        $this->actingAs($this->owner)->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page
            ->where('offline.enabled', true)
            ->where('offline.canRegister', true)
            ->where('cashier.id', $this->owner->getKey()));

        Feature::for($this->shop)->deactivate('module:offline_sync');

        $this->actingAs($this->cashier)->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page
            ->where('offline.enabled', false)
            ->where('offline.canRegister', false));
    }
}
