<?php

namespace Database\Seeders;

use App\Support\InstallProfile;
use Illuminate\Database\Seeder;

/**
 * Seeds for the install profile POS_PROFILE selects (config/pos.php):
 * roles and permissions always, then the profile's reference data, then its
 * demo shop on developer machines and in tests.
 *   retail:   VehicleSeeder, DemoShopSeeder (moto-gear shop)
 *   pharmacy: no reference data yet, DemoClinicSeeder
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $profile = InstallProfile::current();

        $this->call(RolesAndPermissionsSeeder::class);
        $this->call($profile->referenceSeeders());

        // Demo data only on developer machines and in tests.
        $demo = $profile->demoSeeder();

        if ($demo !== null && app()->environment(['local', 'testing'])) {
            $this->call($demo);
        }
    }
}
