<?php

namespace Tests\Feature\Pos;

use App\Models\Location;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Shop;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Database\Seeders\DemoShopSeeder;
use Database\Seeders\VehicleSeeder;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Role;

class PharmacyProfileSeedingTest extends PharmacyTestCase
{
    private const ON = ['pharmacy', 'multi_location', 'suppliers', 'purchasing', 'offline_sync', 'labels'];

    public function test_it_seeds_no_vehicle_data_and_no_moto_gear_shop(): void
    {
        $this->assertSame(0, VehicleMake::query()->count());
        $this->assertSame(0, VehicleModel::query()->count());
        $this->assertSame(0, Product::query()->count());
        $this->assertNull(Shop::query()->where('code', 'DEMO')->first());
    }

    public function test_the_vehicle_seeder_does_nothing_even_when_run_on_its_own(): void
    {
        $this->seed(VehicleSeeder::class);

        $this->assertSame(0, VehicleMake::query()->count());
    }

    public function test_the_retail_demo_does_not_run_under_the_pharmacy_profile(): void
    {
        $this->seed(DemoShopSeeder::class);

        $this->assertNull(Shop::query()->where('code', 'DEMO')->first());
    }

    public function test_the_clinic_has_two_selling_locations_and_a_quarantine_with_general_pharmacy_as_default(): void
    {
        $this->assertSame('pharmacy', $this->shop->business_type);
        $this->assertSame(
            ['DOC' => "Doctor's Medicines", 'GEN' => 'General Pharmacy', 'QUAR' => 'Quarantine / Expired'],
            Location::query()->orderBy('code')->pluck('name', 'code')->all(),
        );
        $this->assertSame(['DOC' => true, 'GEN' => true, 'QUAR' => false], Location::query()->orderBy('code')->pluck('is_sellable', 'code')->all());
        $this->assertSame(['GEN', 'DOC', 'QUAR'], Location::query()->ordered()->pluck('code')->all());
        $this->assertTrue($this->shop->defaultLocation()->is($this->general));
        $this->assertFalse($this->doctors->is_default);
        $this->assertSame($this->general->getKey(), $this->till->location_id);
        $this->assertSame('retail', PriceList::query()->where('is_default', true)->value('code'));
    }

    public function test_it_has_an_owner_and_a_cashier(): void
    {
        $this->assertTrue($this->owner->hasRole('owner'));
        $this->assertTrue($this->cashier->hasRole('cashier'));
        $this->assertTrue($this->owner->can('modules.manage'));
        $this->assertTrue($this->cashier->can('pos.checkout'));
        $this->assertFalse($this->cashier->can('cost.view'));
        $this->assertEqualsCanonicalizing(['owner', 'cashier'], Role::query()->pluck('name')->all());
    }

    public function test_the_clinic_starts_with_the_pharmacy_modules_on_and_vehicles_off(): void
    {
        foreach (array_keys(config('pos.modules')) as $module) {
            $this->assertSame(in_array($module, self::ON, true), $this->shop->hasModule($module), "{$module} on the clinic");
        }
    }

    public function test_the_till_has_no_vehicle_filter(): void
    {
        $this->actingAs($this->cashier)->getJson(route('pos.vehicles'))->assertNotFound();
        $this->actingAs($this->cashier)->get(route('pos.index'))
            ->assertInertia(fn (Assert $page) => $page->where('features.vehicleFitment', false));
    }

    /** Branch 1 "done when": runtime behaviour follows Pennant, not POS_PROFILE. */
    public function test_runtime_follows_pennant_not_the_profile(): void
    {
        config(['pos.profile' => 'retail']);
        Feature::flushCache();

        $this->assertTrue($this->shop->hasModule('pharmacy'));
        $this->assertFalse($this->shop->hasModule('vehicle_fitment'));
        $this->actingAs($this->cashier)->getJson(route('pos.vehicles'))->assertNotFound();

        Artisan::call('pos:module', ['action' => 'enable', 'module' => 'vehicle_fitment', '--shop' => 'CLINIC']);

        $this->actingAs($this->cashier)->getJson(route('pos.vehicles'))->assertOk()->assertJsonPath('makes', []);
    }
}
