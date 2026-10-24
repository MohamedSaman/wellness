<?php

namespace Tests\Feature\Pos;

use App\Models\AuditLog;
use App\Support\Money;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

class RolesAndModulesTest extends PosTestCase
{
    public function test_owner_has_every_permission(): void
    {
        foreach (array_keys(config('pos.permissions')) as $permission) {
            $this->assertTrue($this->owner->can($permission), "Owner is missing {$permission}");
        }
    }

    public function test_cashier_has_only_checkout_permissions(): void
    {
        $this->assertTrue($this->cashier->can('pos.checkout'));
        $this->assertTrue($this->cashier->can('stock.view'));

        foreach (['cost.view', 'prices.manage', 'stock.adjust', 'sales.void', 'users.manage', 'reports.profit'] as $permission) {
            $this->assertFalse($this->cashier->can($permission), "Cashier should not have {$permission}");
        }
    }

    public function test_cost_price_is_hidden_from_json(): void
    {
        $this->assertArrayNotHasKey('cost_price', $this->variant('CPR8EA-9')->toArray());
    }

    public function test_modules_are_off_until_enabled_for_a_shop(): void
    {
        $this->assertFalse($this->shop->hasModule('purchasing'));

        Artisan::call('pos:module', ['action' => 'enable', 'module' => 'purchasing', '--shop' => 'DEMO']);

        $this->assertTrue($this->shop->hasModule('purchasing'));
        // Only purchasing and what it needs (suppliers): nothing else.
        $this->assertFalse($this->shop->hasModule('labels'));
    }

    public function test_module_routes_return_404_while_the_module_is_off(): void
    {
        Route::middleware(['web', 'module:purchasing'])->get('/_test/purchase-orders', fn () => 'ok');

        $this->actingAs($this->owner)->get('/_test/purchase-orders')->assertNotFound();

        Artisan::call('pos:module', ['action' => 'enable', 'module' => 'purchasing', '--shop' => 'DEMO']);

        $this->actingAs($this->owner)->get('/_test/purchase-orders')->assertOk();
    }

    public function test_price_changes_are_audited(): void
    {
        $price = $this->variant('CPR8EA-9')->prices()->firstOrFail();

        $this->actingAs($this->owner);
        $price->update(['price' => Money::toMinor(1500)]);

        $log = AuditLog::query()->where('event', 'updated')->latest('id')->firstOrFail();

        $this->assertSame($this->owner->getKey(), (int) $log->user_id);
        $this->assertSame(Money::toMinor(1450), $log->old_values['price']);
        $this->assertSame(Money::toMinor(1500), $log->new_values['price']);
    }

    public function test_security_headers_are_sent(): void
    {
        $this->get('/')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
