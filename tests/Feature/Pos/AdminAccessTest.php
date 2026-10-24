<?php

namespace Tests\Feature\Pos;

use App\Models\Category;
use App\Models\Shop;
use App\Support\CurrentShop;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;

class AdminAccessTest extends AdminTestCase
{
    /** @return array<string, array{0: string}> */
    public static function indexPages(): array
    {
        return [
            'dashboard' => ['filament.admin.pages.dashboard'],
            'products' => ['filament.admin.resources.products.index'],
            'categories' => ['filament.admin.resources.categories.index'],
            'brands' => ['filament.admin.resources.brands.index'],
            'attributes' => ['filament.admin.resources.product-attributes.index'],
            'vehicles' => ['filament.admin.resources.vehicle-makes.index'],
            'stock levels' => ['filament.admin.resources.stock-levels.index'],
            'stock history' => ['filament.admin.resources.stock-movements.index'],
            'sales' => ['filament.admin.resources.sales.index'],
            'tills' => ['filament.admin.resources.devices.index'],
            'users' => ['filament.admin.resources.users.index'],
            'shop settings' => ['filament.admin.pages.shop-settings'],
        ];
    }

    #[DataProvider('indexPages')]
    public function test_owner_can_open_every_admin_screen(string $route): void
    {
        $this->actingAs($this->owner)->get(route($route))->assertSuccessful();
    }

    /** Cashiers work at the till; the admin panel is for users with admin.access. */
    #[DataProvider('indexPages')]
    public function test_cashier_cannot_open_any_admin_screen(string $route): void
    {
        $this->actingAs($this->cashier)->get(route($route))->assertForbidden();
    }

    /** @return array<string, array{0: string}> */
    public static function createPages(): array
    {
        return [
            'product' => ['filament.admin.resources.products.create'],
            'category' => ['filament.admin.resources.categories.create'],
            'user' => ['filament.admin.resources.users.create'],
        ];
    }

    #[DataProvider('createPages')]
    public function test_cashier_cannot_open_create_screens(string $route): void
    {
        $this->actingAs($this->cashier)->get(route($route))->assertForbidden();
    }

    public function test_guests_are_sent_to_the_admin_login(): void
    {
        $this->get(route('filament.admin.resources.products.index'))->assertRedirect();
    }

    public function test_disabled_users_cannot_open_the_admin_panel(): void
    {
        $this->owner->forceFill(['is_active' => false])->save();

        $this->actingAs($this->owner)->get(route('filament.admin.resources.products.index'))->assertForbidden();
    }

    public function test_users_without_a_shop_cannot_open_the_admin_panel(): void
    {
        $this->owner->forceFill(['shop_id' => null])->save();

        $this->actingAs($this->owner)->get(route('filament.admin.resources.products.index'))->assertForbidden();
    }

    public function test_an_owner_of_another_shop_cannot_reach_this_shops_records(): void
    {
        $other = Shop::query()->create(['name' => 'Other Shop', 'code' => 'OTHER']);
        $stranger = $this->owner->replicate(['email'])->forceFill([
            'email' => 'stranger@example.test',
            'shop_id' => $other->getKey(),
        ]);
        $stranger->save();
        $stranger->assignRole('owner');

        $product = $this->variant('CPR8EA-9')->product;
        $category = Category::query()->firstOrFail();

        $this->useShop(null);
        app()->forgetInstance(CurrentShop::class);

        $this->actingAs($stranger)
            ->get(route('filament.admin.resources.products.edit', $product))->assertNotFound();
        $this->actingAs($stranger)
            ->get(route('filament.admin.resources.categories.edit', $category))->assertNotFound();
    }

    public function test_every_panel_resource_is_protected_by_a_policy(): void
    {
        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $this->assertNotNull(
                Gate::getPolicyFor($resource::getModel()),
                "{$resource} has no policy, so Filament would leave it open to every panel user.",
            );
        }
    }
}
