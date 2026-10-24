<?php

namespace Tests\Feature\Pos\Concerns;

use App\Enums\DosageForm;
use App\Enums\DrugSchedule;
use App\Models\Device;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Data\DrugData;
use App\Services\LocationService;
use App\Services\ProductCatalogService;

/**
 * Helpers for pharmacy checkout tests (docs/PHARMACY.md §9, branch 8): the
 * clinic's two counters, cashiers restricted to locations (a cashier with
 * none works at the default location only, General Pharmacy), a syrup sold
 * loose at Doctor's Medicines only, the consultation fee and shop settings.
 * Use with PharmacyTestCase or BatchTestCase (+ WorksWithBatches); needs
 * $shop, $owner, $till, $general and $doctors from the test case.
 */
trait WorksWithCheckout
{
    /** Till T2 "OPD counter" at Doctor's Medicines, created on first use; $opd = it rings up OPD bills. */
    protected function docTill(bool $opd = true): Device
    {
        $till = Device::query()->where('code', 'T2')->first()
            ?? Device::query()->create(['location_id' => $this->doctors->getKey(), 'name' => 'OPD counter', 'code' => 'T2']);

        $till->update(['opd_billing' => $opd]);

        return $till;
    }

    /** The seeded till T1 at General Pharmacy. */
    protected function genTill(): Device
    {
        return $this->till;
    }

    /**
     * A new cashier who may use only these locations. None given: no allowed
     * locations, so the shop's default location only (General Pharmacy).
     */
    protected function cashierAt(Location ...$locations): User
    {
        $user = new User;
        $user->forceFill([
            'shop_id' => $this->shop->getKey(),
            'name' => 'Cashier at '.implode(', ', array_map(fn (Location $location): string => $location->code, $locations)),
            'email' => 'cashier-'.uniqid().'@clinic.test',
            'password' => 'password',
            'email_verified_at' => now(),
        ])->save();
        $user->assignRole('cashier');

        return $this->allowAt($user, ...$locations);
    }

    /** Set $user's allowed locations (none: clears them), as the owner would. */
    protected function allowAt(User $user, Location ...$locations): User
    {
        app(LocationService::class)->setAllowedLocations($user, array_values(array_map(fn (Location $location): int => (int) $location->getKey(), $locations)), $this->owner);

        return $user;
    }

    /**
     * A syrup counted in ml (whole units, batch-tracked, no stock yet) with a
     * Bottle pack: loose ml sell at Doctor's Medicines only, the Bottle
     * everywhere. Prices in minor units per ml and per bottle.
     */
    protected function syrup(string $name = 'Paracetamol Syrup', int $mlPrice = 500, int $bottlePrice = 25_000, int $bottleMl = 60): ProductVariant
    {
        return app(ProductCatalogService::class)->create(
            ['name' => $name, 'unit' => 'ml'],
            [[
                'price' => $mlPrice,
                'cost' => 300,
                'allow_unit_sale' => false,
                'loose_at' => [(int) $this->doctors->getKey() => true],
                'packs' => [['name' => 'Bottle', 'factor' => $bottleMl, 'price' => $bottlePrice]],
            ]],
            user: $this->owner,
            drug: new DrugData(['Paracetamol'], DosageForm::Syrup, DrugSchedule::Otc),
        )->variants()->firstOrFail();
    }

    /** The Consultation service (no stock, price typed at the till), set as the shop's OPD fee line. */
    protected function consultation(int $price = 150_000): ProductVariant
    {
        $variant = app(ProductCatalogService::class)->create(
            ['name' => 'Consultation', 'unit' => 'Visit', 'track_stock' => false, 'allow_price_override' => true],
            [['price' => $price]],
            user: $this->owner,
        )->variants()->firstOrFail();

        $this->setShopSetting('opd_consultation_variant_id', (int) $variant->getKey());

        return $variant;
    }

    /** Save one shop setting (over pos.shop_defaults), as Shop settings would. */
    protected function setShopSetting(string $key, mixed $value): void
    {
        $this->shop->update(['settings' => [...($this->shop->settings ?? []), $key => $value]]);
    }
}
