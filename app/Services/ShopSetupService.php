<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Location;
use App\Models\PriceList;
use App\Models\Shop;
use App\Support\CurrentShop;
use App\Support\InstallProfile;

/**
 * What every new shop starts with, from its install profile: the profile's
 * shop settings, its locations, the Retail price list, the first till (T1)
 * at the default location, and the profile's modules in Pennant. Used by
 * pos:install and the demo seeders; never at runtime.
 */
class ShopSetupService
{
    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly ModuleService $modules,
    ) {}

    /** @return Device the first till */
    public function provision(Shop $shop, InstallProfile $profile): Device
    {
        return $this->currentShop->runAs($shop, function () use ($shop, $profile): Device {
            if ($profile->settings() !== []) {
                // Anything the shop was created with wins over the profile's starting values.
                $shop->update(['settings' => [...$profile->settings(), ...($shop->settings ?? [])]]);
            }

            $default = null;

            foreach ($profile->locations() as $i => $location) {
                $model = Location::query()->create([
                    'name' => $location['name'],
                    'code' => $location['code'],
                    'is_default' => $location['default'],
                    'is_sellable' => $location['sellable'],
                    'sort_order' => $i + 1, // pickers and reports list them in the profile's order
                ]);

                if ($location['default']) {
                    $default = $model;
                }
            }

            if ($default === null) {
                throw new \LogicException("Install profile [{$profile->name}] has no locations.");
            }

            PriceList::query()->create(['name' => 'Retail', 'code' => 'retail', 'is_default' => true]);

            $this->modules->setUp($shop, $profile->modules());

            return Device::query()->create(['location_id' => $default->getKey(), 'name' => 'Front counter', 'code' => 'T1']);
        });
    }
}
