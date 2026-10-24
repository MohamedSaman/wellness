<?php

namespace Tests\Feature\Pos;

use App\Models\Device;
use App\Models\Location;
use App\Models\Shop;
use App\Models\User;
use App\Support\CurrentShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class PosInstallTest extends TestCase
{
    use RefreshDatabase;

    private function install(?string $profile, string $code): PendingCommand
    {
        $command = $this->artisan('pos:install', $profile === null ? [] : ['--profile' => $profile]);

        if ($profile === null) {
            $command->expectsQuestion('Install profile', 'pharmacy');
        }

        return $command
            ->expectsQuestion('Shop name', "Shop {$code}")
            ->expectsQuestion('Short shop code', $code)
            ->expectsQuestion('Currency', 'LKR')
            ->expectsQuestion('Timezone', 'Asia/Colombo')
            ->expectsQuestion('Owner name', 'Owner')
            ->expectsQuestion('Owner email', strtolower($code).'@example.test')
            ->expectsQuestion('Owner password (min 12 characters)', 'correct horse 42 battery');
    }

    private function installed(string $code): Shop
    {
        $shop = Shop::query()->where('code', $code)->firstOrFail();
        app(CurrentShop::class)->set($shop);

        return $shop;
    }

    public function test_a_pharmacy_install_gets_both_pools_the_pharmacy_modules_and_no_vehicles(): void
    {
        $this->install('pharmacy', 'CLIN')->assertSuccessful();

        $shop = $this->installed('CLIN');

        $this->assertSame('pharmacy', $shop->business_type);
        $this->assertSame(['DOC', 'GEN', 'QUAR'], Location::query()->orderBy('code')->pluck('code')->all());
        $this->assertFalse(Location::query()->where('code', 'QUAR')->firstOrFail()->is_sellable, 'Quarantine / Expired sells nothing.');
        $this->assertSame('GEN', $shop->defaultLocation()->code);
        $this->assertSame('GEN', Device::query()->where('code', 'T1')->firstOrFail()->location?->code);
        $this->assertTrue(User::query()->where('email', 'clin@example.test')->firstOrFail()->hasRole('owner'));

        // labels: in-store barcodes and labels for what has no manufacturer barcode (branch 7b).
        foreach (['pharmacy', 'multi_location', 'suppliers', 'purchasing', 'offline_sync', 'labels'] as $module) {
            $this->assertTrue($shop->hasModule($module), "{$module} should be on");
        }

        $this->assertFalse($shop->hasModule('vehicle_fitment'));

        // The profile's starting settings (client answers, 2026-10-20; prescriber and bank
        // transfer reference from branch 8; no expiry but the prescriber on receipts from
        // branch 9, 2026-10-21; the teal brand colour from feat/dashboard-charts-branding; an open shift to
        // sell, 2026-10-22); the rest are the shop defaults.
        $this->assertSameIgnoringKeyOrder([
            'near_expiry_warning_days' => 90,
            'receive_at_default_location' => true,
            'max_cashier_discount_percent' => 0,
            'rx_prescriber' => 'required',
            'bank_transfer_reference_required' => true,
            'receipt_show_expiry' => false,
            'receipt_show_prescriber' => true,
            'require_open_shift' => true,
            'brand_color' => '#0f766e',
        ], $shop->settings);
        $this->assertSame(0, $shop->setting('min_days_to_expiry_for_sale'));
    }

    public function test_a_retail_install_is_what_it_was_plus_vehicle_fitment(): void
    {
        $this->install('retail', 'MOTO')->assertSuccessful();

        $shop = $this->installed('MOTO');

        $this->assertSame('retail', $shop->business_type);
        $this->assertSame(['MAIN'], Location::query()->pluck('code')->all());
        $this->assertTrue($shop->hasModule('vehicle_fitment'));
        $this->assertFalse($shop->hasModule('pharmacy'));
        $this->assertFalse($shop->hasModule('offline_sync'));
        $this->assertNull($shop->settings);
        $this->assertSame([180, false, 10], [$shop->setting('near_expiry_warning_days'), $shop->setting('receive_at_default_location'), $shop->setting('max_cashier_discount_percent')]);
    }

    public function test_without_the_option_it_asks_for_the_profile(): void
    {
        $this->install(null, 'ASKD')->assertSuccessful();

        $this->assertTrue($this->installed('ASKD')->hasModule('pharmacy'));
    }

    public function test_an_unknown_profile_is_refused(): void
    {
        $this->artisan('pos:install', ['--profile' => 'bakery'])->assertFailed();

        $this->assertSame(0, Shop::query()->count());
    }
}
