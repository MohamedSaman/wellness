<?php

namespace Tests\Feature\Pos;

use App\Models\Shop;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Role;

/**
 * Branch 18's setup (docs/PHARMACY.md): the cash_movements module starts on
 * for pharmacy shops with no migration (a shop that existed before it, like
 * the live clinic, too), and the new permissions reach the roles as
 * config/pos.php gives them.
 */
class CashMovementSetupTest extends PharmacyTestCase
{
    public function test_the_clinic_has_cash_movements_on(): void
    {
        $this->assertTrue($this->shop->hasModule('cash_movements'));
    }

    public function test_a_pharmacy_shop_from_before_the_module_starts_with_it_on(): void
    {
        // As on the live clinic: Pennant has never stored a value for the new module.
        Feature::for($this->shop)->forget('module:cash_movements');

        $this->assertTrue(Shop::query()->findOrFail($this->shop->getKey())->hasModule('cash_movements'));
    }

    public function test_the_new_permissions_reach_the_owner_and_the_cashier(): void
    {
        (new RolesAndPermissionsSeeder)->run();

        $this->owner->refresh();
        $this->cashier->refresh();

        foreach (['cash.movements', 'cash.movements.void', 'returns.no_receipt', 'doctors.manage', 'doctors.pay'] as $permission) {
            $this->assertTrue($this->owner->can($permission), $permission);
        }

        $this->assertTrue($this->cashier->can('cash.movements'));
        $this->assertTrue($this->cashier->can('doctors.pay'));
        $this->assertFalse($this->cashier->can('cash.movements.void'));
        $this->assertFalse($this->cashier->can('returns.no_receipt'));
        $this->assertFalse($this->cashier->can('doctors.manage'));
        $this->assertTrue(Role::findByName('cashier')->hasPermissionTo('doctors.pay'));
    }

    public function test_the_default_limits_are_the_cashiers_only(): void
    {
        $this->assertSame(['cashier' => ['paid_out' => 200_000, 'doctor_payment' => 2_500_000]], $this->shop->setting('cash_movement_limits'));
        $this->assertFalse($this->shop->setting('doctor_payment_reminder'));
    }
}
