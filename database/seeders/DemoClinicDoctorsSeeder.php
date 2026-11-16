<?php

namespace Database\Seeders;

use App\Models\Doctor;
use App\Models\Shop;
use App\Support\CurrentShop;
use Illuminate\Database\Seeder;

/**
 * LOCAL DEVELOPMENT ONLY: two doctors to pay from the till (docs/PHARMACY.md,
 * branch 18), and the demo clinic's "Remind about doctor payment at shift
 * close" switched on so Close shift shows it (the shop default stays off).
 * Runs after the other demo clinic seeders (DemoClinicSeeder calls it
 * outside tests); the e2e tests use both.
 */
class DemoClinicDoctorsSeeder extends Seeder
{
    public function run(CurrentShop $currentShop): void
    {
        $shop = Shop::query()->where('code', 'CLINIC')->firstOrFail();

        $currentShop->runAs($shop, function () use ($shop): void {
            foreach ([['Dr. S. Perera', '077 000 0001'], ['Dr. N. Silva', null]] as [$name, $phone]) {
                Doctor::query()->firstOrCreate(['name' => $name], ['phone' => $phone, 'is_active' => true]);
            }

            $shop->update(['settings' => [...($shop->settings ?? []), 'doctor_payment_reminder' => true]]);
        });
    }
}
