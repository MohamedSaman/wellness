<?php

namespace Database\Seeders;

use App\Enums\AdjustmentReason;
use App\Enums\DosageForm;
use App\Enums\DrugRoute;
use App\Enums\DrugSchedule;
use App\Enums\StorageCondition;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\User;
use App\Services\Data\BatchInput;
use App\Services\Data\DrugData;
use App\Services\ProductCatalogService;
use App\Services\StockService;
use App\Support\CurrentShop;
use Illuminate\Database\Seeder;

/**
 * LOCAL DEVELOPMENT ONLY: a few medicines with batches in the demo clinic,
 * so FEFO, expiry and batch screens have something to show at :8001
 * (DemoClinicSeeder runs it outside tests). Expiry dates are relative to
 * today: one batch close to expiry, one expired, one fridge item.
 */
class DemoClinicStockSeeder extends Seeder
{
    public function run(CurrentShop $currentShop, ProductCatalogService $catalog, StockService $stock): void
    {
        $shop = Shop::query()->where('code', 'CLINIC')->firstOrFail();

        $currentShop->runAs($shop, function () use ($shop, $catalog, $stock): void {
            $owner = User::query()->where('email', 'owner@clinic.test')->firstOrFail();
            $general = Location::query()->where('code', 'GEN')->firstOrFail();
            $doctors = Location::query()->where('code', 'DOC')->firstOrFail();
            $month = fn (int $months): string => now($shop->timezone)->addMonths($months)->format('m/Y');

            $panadol = $this->medicine($catalog, $owner, 'Panadol 500 mg Tablets', 'Tablet', 1_500, 900, ['Paracetamol'], DosageForm::Tablet, DrugSchedule::Otc, 'Panadol',
                packs: [['name' => 'Strip', 'factor' => 10, 'price' => 14_000], ['name' => 'Box', 'factor' => 100, 'price' => 130_000]]);
            // Two batches at General Pharmacy: FEFO sells the short one first.
            $stock->stockIn($panadol, $general, 60, $owner, 900, 'Demo: short expiry', batch: new BatchInput('PN2401', $month(4), acceptNearExpiry: true));
            $stock->stockIn($panadol, $general, 200, $owner, 950, 'Demo', batch: new BatchInput('PN2409', $month(20)));
            $stock->stockIn($panadol, $doctors, 50, $owner, 950, 'Demo', batch: new BatchInput('PN2409', $month(20)));

            $amoxil = $this->medicine($catalog, $owner, 'Amoxil 250 mg Capsules', 'Capsule', 2_500, 1_600, ['Amoxicillin'], DosageForm::Capsule, DrugSchedule::PrescriptionOnly, 'Amoxil',
                packs: [['name' => 'Strip', 'factor' => 10, 'price' => 24_000]]);
            $stock->stockIn($amoxil, $general, 100, $owner, 1_600, 'Demo', batch: new BatchInput('AM1188', $month(12)));
            // Found on the shelf already expired: recorded by a count, never sold, ready to write off.
            $stock->adjustBatchTo($amoxil, $general, new BatchInput('AM0921', now($shop->timezone)->subMonths(1)->format('m/Y')), 20, AdjustmentReason::CountCorrection, $owner, 'Demo: expired stock found on the shelf');

            $insulin = $this->medicine($catalog, $owner, 'Insulatard 100 IU/ml Vial', 'Vial', 450_000, 380_000, ['Insulin isophane'], DosageForm::Injection, DrugSchedule::PrescriptionOnly, 'Insulatard',
                storage: StorageCondition::Fridge, route: DrugRoute::Injection, minDays: 30);
            $stock->stockIn($insulin, $doctors, 10, $owner, 380_000, 'Demo', batch: new BatchInput('IN7730', $month(8)));

            $catalog->create(
                ['name' => 'Consultation', 'unit' => 'Visit', 'track_stock' => false],
                [['price' => 150_000]],
                user: $owner,
            );
        });
    }

    /**
     * @param  list<string>  $generics
     * @param  list<array<string, mixed>>  $packs
     */
    private function medicine(
        ProductCatalogService $catalog, User $owner, string $name, string $unit, int $price, int $cost, array $generics,
        DosageForm $form, DrugSchedule $schedule, string $brand, array $packs = [], ?StorageCondition $storage = null,
        ?DrugRoute $route = null, ?int $minDays = null,
    ): ProductVariant {
        return $catalog->create(
            ['name' => $name, 'unit' => $unit],
            [['price' => $price, 'cost' => $cost, 'packs' => $packs]],
            user: $owner,
            drug: new DrugData($generics, $form, $schedule, brandName: $brand, storageCondition: $storage, route: $route, minDaysToExpiry: $minDays),
        )->variants()->firstOrFail();
    }
}
