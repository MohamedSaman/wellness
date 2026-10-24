<?php

namespace App\Services;

use App\Exceptions\PosException;
use App\Models\Shop;
use InvalidArgumentException;
use Laravel\Pennant\Feature;

/**
 * Switches modules on and off per shop (Pennant "module:{key}") and keeps the
 * rules in config('pos.module_requires'): a module's requirements are on
 * whenever it is on. Every change of module state goes through here
 * (pos:module, pos:install, seeders); reading is just $shop->hasModule().
 */
class ModuleService
{
    /** @return array<string, string> key => label */
    public function all(): array
    {
        return config('pos.modules', []);
    }

    public function exists(string $module): bool
    {
        return array_key_exists($module, $this->all());
    }

    /**
     * Every module this one needs, directly or through another module.
     *
     * @return list<string>
     */
    public function requirementsOf(string $module): array
    {
        $found = [];
        $pending = [$module];

        while ($pending !== []) {
            foreach (config('pos.module_requires.'.array_shift($pending), []) as $required) {
                if ($required !== $module && ! in_array($required, $found, true)) {
                    $found[] = $required;
                    $pending[] = $required;
                }
            }
        }

        return $found;
    }

    /**
     * Modules switched on for the shop that need this one.
     *
     * @return list<string>
     */
    public function activeDependentsOf(Shop $shop, string $module): array
    {
        return array_values(array_filter(
            array_keys($this->all()),
            fn (string $other): bool => in_array($module, $this->requirementsOf($other), true) && $shop->hasModule($other),
        ));
    }

    /**
     * Switch a module on, with everything it needs.
     *
     * @return list<string> the requirements that were off and are now on
     */
    public function enable(Shop $shop, string $module): array
    {
        $this->assertExists($module);

        $switchedOn = array_values(array_filter($this->requirementsOf($module), fn (string $required): bool => ! $shop->hasModule($required)));

        foreach ([...$this->requirementsOf($module), $module] as $key) {
            Feature::for($shop)->activate("module:{$key}");
        }

        return $switchedOn;
    }

    /** Switch a module off. Refused while a module that needs it is on. */
    public function disable(Shop $shop, string $module): void
    {
        $this->assertExists($module);

        $dependents = $this->activeDependentsOf($shop, $module);

        if ($dependents !== []) {
            throw new PosException("Module [{$module}] is needed by ".implode(', ', $dependents).'. Switch that off first.');
        }

        Feature::for($shop)->deactivate("module:{$module}");
    }

    /**
     * A new shop's starting state: these modules (and their requirements) on,
     * every other module off, all stored in Pennant.
     *
     * @param  list<string>  $modules
     */
    public function setUp(Shop $shop, array $modules): void
    {
        $on = [];

        foreach ($modules as $module) {
            $this->assertExists($module);
            $on = [...$on, ...$this->requirementsOf($module), $module];
        }

        foreach (array_keys($this->all()) as $key) {
            in_array($key, $on, true)
                ? Feature::for($shop)->activate("module:{$key}")
                : Feature::for($shop)->deactivate("module:{$key}");
        }
    }

    private function assertExists(string $module): void
    {
        if (! $this->exists($module)) {
            throw new InvalidArgumentException("Unknown module [{$module}]. Available: ".implode(', ', array_keys($this->all())).'.');
        }
    }
}
