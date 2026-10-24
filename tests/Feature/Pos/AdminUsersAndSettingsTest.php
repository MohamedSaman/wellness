<?php

namespace Tests\Feature\Pos;

use App\Filament\Pages\ShopSettings;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Shop;
use App\Models\User;
use Livewire\Livewire;

class AdminUsersAndSettingsTest extends AdminTestCase
{
    public function test_owner_creates_a_cashier_in_their_own_shop(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'New Cashier',
                'email' => 'new.cashier@pos.test',
                'password' => 'a-strong-password',
                'role' => 'cashier',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::query()->where('email', 'new.cashier@pos.test')->firstOrFail();

        $this->assertSame($this->shop->getKey(), $user->shop_id);
        $this->assertTrue($user->hasRole('cashier'));
        $this->assertTrue($user->can('pos.checkout'));
        $this->assertFalse($user->can('cost.view'));
        $this->assertTrue($user->is_active);
    }

    public function test_only_the_roles_from_config_can_be_assigned(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Sneaky',
                'email' => 'sneaky@pos.test',
                'password' => 'a-strong-password',
                'role' => 'super-admin',
            ])
            ->call('create')
            ->assertHasFormErrors(['role']);
    }

    public function test_owner_disables_and_enables_a_cashier(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ListUsers::class)->callTableAction('toggleActive', $this->cashier);
        $this->assertFalse($this->cashier->fresh()->is_active);

        Livewire::test(ListUsers::class)->callTableAction('toggleActive', $this->cashier->fresh());
        $this->assertTrue($this->cashier->fresh()->is_active);
    }

    public function test_a_disabled_cashier_is_signed_out_on_the_next_request(): void
    {
        $this->cashier->forceFill(['is_active' => false])->save();

        $this->actingAs($this->cashier)->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_owner_cannot_disable_or_demote_themselves(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ListUsers::class)->assertTableActionHidden('toggleActive', $this->owner);

        Livewire::test(EditUser::class, ['record' => $this->owner->getKey()])
            ->fillForm(['name' => 'Renamed Owner', 'role' => 'cashier', 'is_active' => false])
            ->call('save');

        $owner = $this->owner->fresh();
        $this->assertSame('Renamed Owner', $owner->name);
        $this->assertTrue($owner->hasRole('owner'));
        $this->assertTrue($owner->is_active);
    }

    public function test_owner_can_change_a_cashiers_role(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(EditUser::class, ['record' => $this->cashier->getKey()])
            ->fillForm(['role' => 'owner'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($this->cashier->fresh()->hasRole('owner'));
        $this->assertFalse($this->cashier->fresh()->hasRole('cashier'));
    }

    public function test_users_of_other_shops_are_not_listed_or_editable(): void
    {
        $other = Shop::query()->create(['name' => 'Other Shop', 'code' => 'OTHER']);
        $outsider = User::factory()->create(['shop_id' => $other->getKey()]);

        $this->actingAs($this->owner);

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$this->owner, $this->cashier])
            ->assertCanNotSeeTableRecords([$outsider]);

        $this->get(route('filament.admin.resources.users.edit', $outsider))->assertNotFound();
    }

    public function test_owner_updates_shop_settings(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ShopSettings::class)
            ->fillForm([
                'name' => 'Renamed Moto Gear',
                'address' => '12 Galle Road',
                'phone' => '+94 11 234 5678',
                'currency' => 'usd',
                'tax_enabled' => true,
                'tax_rate' => 8,
                'prices_include_tax' => false,
                'max_cashier_discount_percent' => 15,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $shop = $this->shop->fresh();

        $this->assertSame('Renamed Moto Gear', $shop->name);
        $this->assertSame('12 Galle Road', $shop->address);
        $this->assertSame('+94 11 234 5678', $shop->phone);
        // Header lines and footer are on Receipt settings (branch 9): untouched here.
        $this->assertSame('Thank you! Exchanges within 7 days with receipt.', $shop->receipt_footer);
        $this->assertSame('USD', $shop->currency);
        $this->assertTrue($shop->tax_enabled);
        $this->assertSame(15.0, (float) $shop->setting('max_cashier_discount_percent'));
        $this->assertSame(2, (int) $shop->currency_decimals);
    }

    public function test_settings_reject_invalid_values(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ShopSettings::class)
            ->fillForm(['name' => '', 'currency' => 'EURO', 'max_cashier_discount_percent' => 150])
            ->call('save')
            ->assertHasFormErrors(['name', 'currency', 'max_cashier_discount_percent']);
    }

    public function test_cashier_cannot_use_users_or_settings(): void
    {
        $this->actingAs($this->cashier);

        $this->get(route('filament.admin.resources.users.index'))->assertForbidden();
        $this->get(route('filament.admin.pages.shop-settings'))->assertForbidden();
    }
}
