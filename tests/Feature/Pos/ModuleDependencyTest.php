<?php

namespace Tests\Feature\Pos;

use App\Exceptions\PosException;
use App\Services\ModuleService;
use Illuminate\Support\Facades\Artisan;
use Laravel\Pennant\Feature;

class ModuleDependencyTest extends PosTestCase
{
    private const PHARMACY_NEEDS = ['suppliers', 'purchasing', 'multi_location'];

    private function modules(): ModuleService
    {
        return app(ModuleService::class);
    }

    public function test_pharmacy_is_off_by_default(): void
    {
        $this->assertFalse($this->shop->hasModule('pharmacy'));
    }

    public function test_enabling_pharmacy_switches_on_what_it_needs(): void
    {
        $alsoOn = $this->modules()->enable($this->shop, 'pharmacy');

        $this->assertTrue($this->shop->hasModule('pharmacy'));

        foreach (self::PHARMACY_NEEDS as $module) {
            $this->assertTrue($this->shop->hasModule($module), "{$module} should be on with pharmacy");
        }

        $this->assertEqualsCanonicalizing(self::PHARMACY_NEEDS, $alsoOn);
        $this->assertFalse($this->shop->hasModule('labels'), 'Unrelated modules stay as they were');
    }

    public function test_enable_reports_only_the_requirements_that_were_off(): void
    {
        $this->modules()->enable($this->shop, 'suppliers');

        $this->assertEqualsCanonicalizing(['purchasing', 'multi_location'], $this->modules()->enable($this->shop, 'pharmacy'));
    }

    public function test_a_requirement_cannot_be_switched_off_while_pharmacy_is_on(): void
    {
        $this->modules()->enable($this->shop, 'pharmacy');

        foreach (self::PHARMACY_NEEDS as $module) {
            try {
                $this->modules()->disable($this->shop, $module);
                $this->fail("Disabling {$module} under pharmacy should be refused");
            } catch (PosException $exception) {
                $this->assertStringContainsString('pharmacy', $exception->getMessage());
            }

            $this->assertTrue($this->shop->hasModule($module));
        }
    }

    public function test_requirements_can_be_switched_off_once_pharmacy_is_off(): void
    {
        $this->modules()->enable($this->shop, 'pharmacy');
        $this->modules()->disable($this->shop, 'pharmacy');
        $this->modules()->disable($this->shop, 'purchasing');

        $this->assertFalse($this->shop->hasModule('pharmacy'));
        $this->assertFalse($this->shop->hasModule('purchasing'));
        $this->assertTrue($this->shop->hasModule('suppliers'), 'Disabling pharmacy leaves its requirements on');
    }

    public function test_the_module_command_applies_the_same_rule(): void
    {
        $this->assertSame(0, Artisan::call('pos:module', ['action' => 'enable', 'module' => 'pharmacy', '--shop' => 'DEMO']));
        $this->assertStringContainsString('multi_location', Artisan::output());

        foreach (self::PHARMACY_NEEDS as $module) {
            $this->assertTrue($this->shop->hasModule($module));
        }

        $this->assertSame(1, Artisan::call('pos:module', ['action' => 'disable', 'module' => 'suppliers', '--shop' => 'DEMO']));
        // Purchasing needs suppliers too (every delivery is from a supplier).
        $this->assertStringContainsString('needed by purchasing, pharmacy', Artisan::output());
        $this->assertTrue($this->shop->hasModule('suppliers'));
    }

    public function test_requirements_are_followed_through_other_modules(): void
    {
        config(['pos.module_requires' => ['pharmacy' => ['purchasing'], 'purchasing' => ['suppliers']]]);

        $this->assertEqualsCanonicalizing(['purchasing', 'suppliers'], $this->modules()->requirementsOf('pharmacy'));

        $this->modules()->enable($this->shop, 'pharmacy');
        $this->assertTrue($this->shop->hasModule('suppliers'));

        $this->expectException(PosException::class);
        $this->modules()->disable($this->shop, 'suppliers');
    }

    public function test_set_up_stores_exactly_the_given_modules_and_their_requirements(): void
    {
        $this->modules()->enable($this->shop, 'labels');

        $this->modules()->setUp($this->shop, ['pharmacy']);

        foreach (array_keys(config('pos.modules')) as $module) {
            $expected = in_array($module, ['pharmacy', ...self::PHARMACY_NEEDS], true);
            $this->assertSame($expected, $this->shop->hasModule($module), "{$module} after set-up");
        }
    }

    public function test_unknown_modules_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->modules()->enable($this->shop, 'teleportation');
    }

    public function test_vehicle_fitment_starts_on_for_a_retail_shop_without_a_stored_value(): void
    {
        Feature::for($this->shop)->forget('module:vehicle_fitment');
        Feature::flushCache();

        $this->assertSame('retail', $this->shop->business_type);
        $this->assertTrue($this->shop->hasModule('vehicle_fitment'));
    }

    public function test_vehicle_fitment_starts_off_for_a_non_retail_shop_and_the_stored_value_wins_after(): void
    {
        Feature::for($this->shop)->forget('module:vehicle_fitment');
        Feature::flushCache();
        $this->shop->forceFill(['business_type' => 'pharmacy'])->save();

        $this->assertFalse($this->shop->hasModule('vehicle_fitment'));

        // Resolved once and stored: changing the business type later does not flip it.
        $this->shop->forceFill(['business_type' => 'retail'])->save();
        Feature::flushCache();
        $this->assertFalse($this->shop->hasModule('vehicle_fitment'));

        $this->modules()->enable($this->shop, 'vehicle_fitment');
        $this->assertTrue($this->shop->hasModule('vehicle_fitment'));
    }
}
