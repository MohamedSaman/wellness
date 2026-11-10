<?php

namespace Database\Seeders;

use App\Enums\TaxCategory;
use App\Models\Location;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\ProductCatalogService;
use App\Services\StockService;
use App\Support\CurrentShop;
use Illuminate\Database\Seeder;

/**
 * Prices changed at the till on the demo clinic (docs/PHARMACY.md, branch
 * 14). Demo data only: run by DemoClinicSeeder outside the test suite (local
 * installs and the browser tests), never by a migration, so a live clinic's
 * catalog is never touched.
 *
 *   - the Consultation's fee may be typed up to 5,000.00;
 *   - "Wound dressing" (stock, not a medicine) at 500.00, typed between
 *     200.00 and 1,500.00, in stock at both locations;
 *   - "CBS (full blood count)" (a lab test, no stock) at 1,200.00, up to
 *     3,000.00, the price box opening as soon as it is added.
 */
class DemoClinicTillPricesSeeder extends Seeder
{
    public function run(CurrentShop $currentShop, ProductCatalogService $catalog, StockService $stock): void
    {
        $shop = Shop::query()->where('code', 'CLINIC')->firstOrFail();

        $currentShop->runAs($shop, function () use ($catalog, $stock): void {
            $owner = User::query()->where('email', 'owner@clinic.test')->firstOrFail();

            $consultation = Product::query()->where('name', 'Consultation')->firstOrFail();
            $fee = $consultation->variants()->firstOrFail();
            $catalog->update($consultation, ['allow_price_override' => true], [[
                'id' => $fee->getKey(),
                'reorder_level' => (float) $fee->reorder_level,
                'bin_location' => $fee->bin_location,
                'till_price_max' => 500_000,
            ]], [], null, $owner);

            $dressing = $catalog->create(
                ['name' => 'Wound dressing', 'unit' => 'pcs', 'track_stock' => true, 'track_batches' => false, 'whole_units_only' => true, 'tax_category' => TaxCategory::Standard, 'allow_price_override' => true],
                [['price' => 50_000, 'cost' => 12_000, 'till_price_min' => 20_000, 'till_price_max' => 150_000]],
                user: $owner,
            )->variants()->firstOrFail();

            foreach (Location::query()->whereIn('code', ['GEN', 'DOC'])->get() as $location) {
                $stock->stockIn($dressing, $location, 100, $owner, 12_000, 'Demo');
            }

            $catalog->create(
                ['name' => 'CBS (full blood count)', 'unit' => 'Test', 'track_stock' => false, 'tax_category' => TaxCategory::Standard, 'allow_price_override' => true, 'ask_price_at_till' => true],
                [['price' => 120_000, 'till_price_max' => 300_000]],
                user: $owner,
            );
        });
    }
}
