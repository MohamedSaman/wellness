<?php

namespace Tests\Feature\Pos;

use App\Models\Location;
use App\Models\Shop;
use App\Models\VehicleMake;
use Database\Seeders\DemoClinicSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Role;

class RetailProfileSeedingTest extends PosTestCase
{
    public function test_the_suite_runs_on_the_retail_profile(): void
    {
        $this->assertSame('retail', config('pos.profile'));
    }

    public function test_it_seeds_vehicles_and_the_moto_gear_demo_shop(): void
    {
        $this->assertGreaterThan(0, VehicleMake::query()->count());
        $this->assertSame('retail', $this->shop->business_type);
        $this->assertSame(['MAIN'], Location::query()->pluck('code')->all());
        $this->assertTrue($this->location->is_default);
        $this->assertSame($this->location->getKey(), $this->till->location_id);
        $this->assertNull(Shop::query()->where('code', 'CLINIC')->first());
        $this->assertNull($this->shop->settings, 'The retail profile sets no settings of its own.');
    }

    public function test_the_demo_shop_starts_with_only_vehicle_fitment_on(): void
    {
        foreach (array_keys(config('pos.modules')) as $module) {
            $this->assertSame($module === 'vehicle_fitment', $this->shop->hasModule($module), "{$module} on the retail demo");
        }

        // Every module is stored in Pennant, not left to its default resolver.
        $this->assertSame(count(config('pos.modules')), DB::table('features')->where('name', 'like', 'module:%')->count());
    }

    public function test_it_creates_only_the_retail_roles(): void
    {
        $this->assertEqualsCanonicalizing(['owner', 'cashier'], Role::query()->pluck('name')->all());
    }

    public function test_the_pharmacy_demo_does_not_run_under_the_retail_profile(): void
    {
        $this->seed(DemoClinicSeeder::class);

        $this->assertNull(Shop::query()->where('code', 'CLINIC')->first());
    }

    public function test_a_role_is_created_only_when_the_profile_lists_it(): void
    {
        config(['pos.roles.auditor' => ['reports.view']]);

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->assertNull(Role::query()->where('name', 'auditor')->first());

        config(['pos.profiles.retail.roles' => ['owner', 'cashier', 'auditor']]);

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->assertTrue(Role::findByName('auditor')->hasPermissionTo('reports.view'));
    }

    public function test_an_existing_role_the_profile_does_not_list_still_gets_permission_changes(): void
    {
        config([
            'pos.profiles.retail.roles' => ['owner'],
            'pos.roles.cashier' => [...config('pos.roles.cashier'), 'reports.view'],
        ]);

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertTrue($this->cashier->fresh()?->can('reports.view'));
    }

    /** Branch 1 "done when": runtime behaviour follows Pennant, not POS_PROFILE. */
    public function test_changing_the_profile_on_a_running_install_changes_nothing(): void
    {
        config(['pos.profile' => 'pharmacy']);
        Feature::flushCache();

        $this->assertTrue($this->shop->hasModule('vehicle_fitment'));
        $this->assertFalse($this->shop->hasModule('pharmacy'));
        $this->actingAs($this->cashier)->getJson(route('pos.vehicles'))->assertOk();
    }
}
