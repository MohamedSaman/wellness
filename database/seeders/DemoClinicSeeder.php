<?php

namespace Database\Seeders;

use App\Models\Shop;
use App\Services\ShopSetupService;
use App\Support\CurrentShop;
use Database\Seeders\Concerns\SeedsDemoShop;
use Illuminate\Database\Seeder;

/**
 * LOCAL DEVELOPMENT ONLY: a demo clinic with the two stock pools of the
 * first pharmacy client (General Pharmacy, Doctor's Medicines), an owner
 * and a cashier, and the pharmacy profile's modules on. No vehicle data.
 * Outside tests it also adds a few medicines with batches (DemoClinicStockSeeder)
 * and purchasing documents for them (DemoClinicPurchasingSeeder), and the
 * clinic's two counters (DemoClinicCountersSeeder: an OPD till at Doctor's
 * Medicines, allowed locations, syrups sold loose there) and stock control
 * (DemoClinicStockControlSeeder: a quarantine location, expiring stock,
 * reorder and par levels, a stock request waiting).
 * The pharmacy profile's demo; skipped under any other profile.
 *
 *   owner@clinic.test   / password
 *   cashier@clinic.test / password
 *   opd@clinic.test     / password (local demo only: the OPD counter)
 */
class DemoClinicSeeder extends Seeder
{
    use SeedsDemoShop;

    public function run(CurrentShop $currentShop, ShopSetupService $setup): void
    {
        $profile = $this->demoProfile();

        if ($profile === null) {
            return;
        }

        $shop = Shop::query()->create([
            'name' => 'Demo Clinic',
            'code' => 'CLINIC',
            'business_type' => $profile->businessType(),
            'address' => '45 Hospital Road, Kandy',
            'phone' => '+94 81 000 0000',
            'currency' => 'LKR',
            'timezone' => 'Asia/Colombo',
            'receipt_footer' => 'Get well soon. Medicines are not returnable once dispensed.',
        ]);

        $setup->provision($shop, $profile);

        $currentShop->runAs($shop, function () use ($shop): void {
            $this->user($shop, 'Clinic Owner', 'owner@clinic.test', 'owner');
            $this->user($shop, 'Clinic Cashier', 'cashier@clinic.test', 'cashier');
        });

        // A few medicines with batches to try FEFO and expiry on, and purchasing on top of
        // them (suppliers, an order, goods received, a return); tests build their own.
        if (! app()->runningUnitTests()) {
            $this->call(DemoClinicStockSeeder::class);
            $this->call(DemoClinicPurchasingSeeder::class);
            $this->call(DemoClinicCountersSeeder::class);
            $this->call(DemoClinicStockControlSeeder::class);
        }
    }
}
