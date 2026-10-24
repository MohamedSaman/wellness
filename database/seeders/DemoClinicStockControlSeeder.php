<?php

namespace Database\Seeders;

use App\Enums\AdjustmentReason;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\User;
use App\Services\Data\BatchInput;
use App\Services\Data\RequestLine;
use App\Services\StockRequestService;
use App\Services\StockService;
use App\Support\CurrentShop;
use Illuminate\Database\Seeder;

/**
 * LOCAL DEVELOPMENT ONLY: stock control in the demo clinic (docs/PHARMACY.md
 * §9, branch 10), so the Expiry, Stock requests, Reorder and Stock counts
 * screens have something to show at :8001. Runs last (DemoClinicSeeder
 * calls it outside tests).
 *
 *   - (the profile's Quarantine / Expired location, QUAR, takes expiring
 *     batches moved off the shelves);
 *   - an expired batch at Doctor's Medicines and a short-dated one at
 *     General Pharmacy (within the 90-day warning);
 *   - reorder and par levels: Panadol and Insulatard low at Doctor's
 *     Medicines, Amoxil low at General Pharmacy (last bought from Hemas);
 *   - a stock request from the OPD's cashier waiting at General Pharmacy.
 */
class DemoClinicStockControlSeeder extends Seeder
{
    public function run(CurrentShop $currentShop, StockService $stock, StockRequestService $requests): void
    {
        $shop = Shop::query()->where('code', 'CLINIC')->firstOrFail();

        $currentShop->runAs($shop, function () use ($shop, $stock, $requests): void {
            $owner = User::query()->where('email', 'owner@clinic.test')->firstOrFail();
            $opd = User::query()->where('email', 'opd@clinic.test')->firstOrFail();
            $general = Location::query()->where('code', 'GEN')->firstOrFail();
            $doctors = Location::query()->where('code', 'DOC')->firstOrFail();
            $month = fn (int $months): string => now($shop->timezone)->addMonths($months)->format('m/Y');
            $item = fn (string $name): ProductVariant => Product::query()->where('name', $name)->firstOrFail()->variants()->firstOrFail();

            $panadol = $item('Panadol 500 mg Tablets');
            $amoxil = $item('Amoxil 250 mg Capsules');
            $insulin = $item('Insulatard 100 IU/ml Vial');
            $syrup = $item('Chlorphenamine Syrup 2 mg/5 ml');

            // Found expired on the OPD's shelf; and a delivery at General Pharmacy that expires soon.
            $stock->adjustBatchTo($panadol, $doctors, new BatchInput('PN2208', $month(-1)), 12, AdjustmentReason::CountCorrection, $owner, 'Demo: expired stock found on the shelf');
            $stock->stockIn($amoxil, $general, 30, $owner, 1_650, 'Demo: short expiry', batch: new BatchInput('AM1201', $month(2), acceptNearExpiry: true));

            $stock->setReorderLevel($panadol, $doctors, 100);
            $stock->setParLevel($panadol, $doctors, 300);
            $stock->setReorderLevel($insulin, $doctors, 10);
            $stock->setParLevel($insulin, $doctors, 20);
            $stock->setReorderLevel($amoxil, $general, 400);
            $stock->setParLevel($amoxil, $general, 600);

            $requests->create($general, $doctors, [
                new RequestLine($panadol->getKey(), 3, (int) $panadol->units()->where('name', 'Strip')->value('id')),
                new RequestLine($syrup->getKey(), 2, (int) $syrup->units()->where('name', 'Bottle')->value('id')),
            ], $opd, 'For the evening clinic');
        });
    }
}
