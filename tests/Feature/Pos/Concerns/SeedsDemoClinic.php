<?php

namespace Tests\Feature\Pos\Concerns;

use App\Models\Device;
use App\Models\Location;
use App\Models\Shop;
use App\Models\User;
use App\Support\CurrentShop;
use Database\Seeders\DatabaseSeeder;

/**
 * Seeds the pharmacy install profile (demo clinic: General Pharmacy and
 * Doctor's Medicines, owner, cashier, till T1) and makes the clinic the
 * current shop. Used by PharmacyTestCase and the MySQL group.
 */
trait SeedsDemoClinic
{
    protected Shop $shop;

    protected User $owner;

    protected User $cashier;

    protected Device $till;

    protected Location $general;

    protected Location $doctors;

    protected function seedDemoClinic(): void
    {
        config(['pos.profile' => 'pharmacy']);
        $this->seed(DatabaseSeeder::class);

        $this->shop = Shop::query()->where('code', 'CLINIC')->firstOrFail();
        // The pharmacy profile sells only with an open shift (require_open_shift,
        // decided 2026-10-22; ClinicShiftRequiredTest). The tests about anything else
        // ring sales up without one, so they turn it off; the e2e tests keep it.
        $this->shop->update(['settings' => [...($this->shop->settings ?? []), 'require_open_shift' => false]]);
        $this->useShop($this->shop);

        $this->owner = User::query()->where('email', 'owner@clinic.test')->firstOrFail();
        $this->cashier = User::query()->where('email', 'cashier@clinic.test')->firstOrFail();
        $this->till = Device::query()->where('code', 'T1')->firstOrFail();
        $this->general = Location::query()->where('code', 'GEN')->firstOrFail();
        $this->doctors = Location::query()->where('code', 'DOC')->firstOrFail();
    }

    protected function useShop(?Shop $shop): void
    {
        app(CurrentShop::class)->set($shop);
    }
}
