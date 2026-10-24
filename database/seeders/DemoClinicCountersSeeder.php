<?php

namespace Database\Seeders;

use App\Enums\DosageForm;
use App\Enums\DrugRoute;
use App\Enums\DrugSchedule;
use App\Models\Device;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\User;
use App\Services\Data\BatchInput;
use App\Services\Data\DrugData;
use App\Services\LocationService;
use App\Services\ProductCatalogService;
use App\Services\StockService;
use App\Support\Barcode;
use App\Support\CurrentShop;
use Database\Seeders\Concerns\SeedsDemoShop;
use Illuminate\Database\Seeder;

/**
 * LOCAL DEVELOPMENT ONLY: the clinic's two counters (docs/PHARMACY.md §9,
 * branch 8), so pharmacy checkout can be tried at :8001. Runs after
 * DemoClinicStockSeeder (DemoClinicSeeder calls it outside tests).
 *
 *   - till T2 "OPD counter" at Doctor's Medicines, ringing up OPD bills;
 *   - opd@clinic.test / password (cashier) may use Doctor's Medicines only,
 *     cashier@clinic.test General Pharmacy only; the owner every location;
 *   - the doctor as default prescriber, the Consultation (typed fee) as the
 *     OPD bill's fee line;
 *   - two syrups counted in ml with a Bottle pack: loose ml sell at Doctor's
 *     Medicines only, batches at both locations;
 *   - a scannable in-store barcode on Panadol's Box, to try a pack scan.
 */
class DemoClinicCountersSeeder extends Seeder
{
    use SeedsDemoShop;

    public function run(CurrentShop $currentShop, ProductCatalogService $catalog, StockService $stock, LocationService $locations): void
    {
        $shop = Shop::query()->where('code', 'CLINIC')->firstOrFail();

        $currentShop->runAs($shop, function () use ($shop, $catalog, $stock, $locations): void {
            $owner = User::query()->where('email', 'owner@clinic.test')->firstOrFail();
            $cashier = User::query()->where('email', 'cashier@clinic.test')->firstOrFail();
            $general = Location::query()->where('code', 'GEN')->firstOrFail();
            $doctors = Location::query()->where('code', 'DOC')->firstOrFail();
            $month = fn (int $months): string => now($shop->timezone)->addMonths($months)->format('m/Y');

            Device::query()->create(['location_id' => $doctors->getKey(), 'name' => 'OPD counter', 'code' => 'T2', 'opd_billing' => true]);

            $opd = $this->user($shop, 'OPD Cashier', 'opd@clinic.test', 'cashier');
            $locations->setAllowedLocations($opd, [(int) $doctors->getKey()], $owner);
            $locations->setAllowedLocations($cashier, [(int) $general->getKey()], $owner);

            // The consultation fee is typed at the till (it varies by visit).
            $consultation = Product::query()->where('name', 'Consultation')->firstOrFail();
            $catalog->update($consultation, ['allow_price_override' => true], [], [], null, $owner);

            $shop->update(['settings' => [
                ...($shop->settings ?? []),
                'default_prescriber' => 'Dr. S. Perera',
                'opd_consultation_variant_id' => (int) $consultation->variants()->firstOrFail()->getKey(),
            ]]);

            $paracetamol = $this->syrup($catalog, $owner, $doctors, 'Paracetamol Syrup 120 mg/5 ml', 'Paracetamol', 120, 'mg/5 ml', 60, 500, 25_000, 300);
            $stock->stockIn($paracetamol, $general, 1_200, $owner, 300, 'Demo', batch: new BatchInput('PS3301', $month(14)));
            $stock->stockIn($paracetamol, $doctors, 600, $owner, 300, 'Demo', batch: new BatchInput('PS3301', $month(14)));

            $chlorphenamine = $this->syrup($catalog, $owner, $doctors, 'Chlorphenamine Syrup 2 mg/5 ml', 'Chlorphenamine', 2, 'mg/5 ml', 100, 400, 35_000, 250);
            $stock->stockIn($chlorphenamine, $general, 1_000, $owner, 250, 'Demo', batch: new BatchInput('CH5512', $month(18)));
            $stock->stockIn($chlorphenamine, $doctors, 500, $owner, 250, 'Demo', batch: new BatchInput('CH5512', $month(18)));

            // An in-store barcode on Panadol's Box (prefix 201: packs, apart from the variants' 200).
            $panadol = Product::query()->where('name', 'Panadol 500 mg Tablets')->firstOrFail();
            $variant = $panadol->variants()->firstOrFail();
            $box = $variant->units()->where('name', 'Box')->firstOrFail();
            $catalog->update($panadol, [], [[
                'id' => $variant->getKey(),
                'bin_location' => $variant->bin_location,
                'packs' => [[
                    'id' => $box->getKey(),
                    'name' => $box->name,
                    'factor' => $box->factor,
                    'barcode' => Barcode::inStoreEan13((int) $box->getKey(), 201),
                    'is_sellable' => $box->is_sellable,
                    'is_purchasable' => $box->is_purchasable,
                ]],
            ]], [], null, $owner);
        });
    }

    /** A syrup counted in ml with a Bottle pack, each priced; loose ml sell at Doctor's Medicines only. */
    private function syrup(
        ProductCatalogService $catalog, User $owner, Location $doctors, string $name, string $generic,
        float $strength, string $strengthUnit, int $bottleMl, int $mlPrice, int $bottlePrice, int $mlCost,
    ): ProductVariant {
        return $catalog->create(
            ['name' => $name, 'unit' => 'ml'],
            [[
                'price' => $mlPrice,
                'cost' => $mlCost,
                'allow_unit_sale' => false,
                'loose_at' => [(int) $doctors->getKey() => true],
                'packs' => [['name' => 'Bottle', 'factor' => $bottleMl, 'price' => $bottlePrice]],
            ]],
            user: $owner,
            drug: new DrugData([$generic], DosageForm::Syrup, DrugSchedule::Otc, strengthValue: $strength, strengthUnit: $strengthUnit, route: DrugRoute::Oral),
        )->variants()->firstOrFail();
    }
}
