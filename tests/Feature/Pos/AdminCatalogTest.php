<?php

namespace Tests\Feature\Pos;

use App\Exceptions\PosException;
use App\Filament\Resources\Brands\Pages\ManageBrands;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\ProductAttributes\Pages\ManageProductAttributes;
use App\Filament\Resources\Products\ProductFormData;
use App\Filament\Resources\VehicleMakes\Pages\ManageVehicleMakes;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ProductAttribute;
use App\Models\VehicleMake;
use App\Services\ProductCatalogService;
use Livewire\Livewire;

class AdminCatalogTest extends AdminTestCase
{
    public function test_owner_creates_a_brand_with_a_slug(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ManageBrands::class)
            ->callAction('create', data: ['name' => 'Royal Enfield'])
            ->assertHasNoActionErrors();

        $this->assertSame('royal-enfield', Brand::query()->where('name', 'Royal Enfield')->firstOrFail()->slug);
    }

    public function test_brand_names_are_unique_within_the_shop(): void
    {
        $this->actingAs($this->owner);
        $existing = Brand::query()->firstOrFail();

        Livewire::test(ManageBrands::class)
            ->callAction('create', data: ['name' => $existing->name])
            ->assertHasActionErrors(['name' => 'unique']);
    }

    public function test_cashier_cannot_create_catalog_records(): void
    {
        $this->actingAs($this->cashier);

        Livewire::test(ManageBrands::class)->assertActionHidden('create');
    }

    public function test_owner_creates_a_category(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(CreateCategory::class)
            ->fillForm(['name' => 'Tyres', 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('tyres', Category::query()->where('name', 'Tyres')->firstOrFail()->slug);
    }

    public function test_owner_creates_an_attribute_with_options(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ManageProductAttributes::class)
            ->callAction('create', data: [
                'name' => 'Visor',
                'code' => 'visor',
                'type' => 'select',
                'options' => [['value' => 'Clear', 'code' => 'CLR'], ['value' => 'Tinted', 'code' => 'TNT']],
            ])
            ->assertHasNoActionErrors();

        $attribute = ProductAttribute::query()->where('code', 'visor')->firstOrFail();

        $this->assertSame(['Clear', 'Tinted'], $attribute->options->pluck('value')->all());
    }

    public function test_owner_creates_a_vehicle_make_with_models(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ManageVehicleMakes::class)
            ->callAction('create', data: [
                'name' => 'Zontes',
                'models' => [['name' => 'Gixxer 155', 'type' => 'motorcycle', 'year_from' => 2019]],
            ])
            ->assertHasNoActionErrors();

        $make = VehicleMake::query()->where('name', 'Zontes')->firstOrFail();

        $this->assertSame(['Gixxer 155'], $make->models->pluck('name')->all());
    }

    public function test_cost_is_left_out_of_the_form_for_users_who_cannot_see_it(): void
    {
        $product = $this->variant('CPR8EA-9')->product;

        $this->actingAs($this->owner);
        $row = ProductFormData::fromProduct($product)['variants'][0];
        $this->assertArrayHasKey('average_cost', $row);
        $this->assertArrayHasKey('last_cost', $row);

        $this->actingAs($this->cashier);
        $row = ProductFormData::fromProduct($product)['variants'][0];
        $this->assertArrayNotHasKey('cost', $row);
        $this->assertArrayNotHasKey('average_cost', $row);
        $this->assertArrayNotHasKey('last_cost', $row);
    }

    public function test_catalog_service_ignores_price_and_cost_from_users_without_those_permissions(): void
    {
        $manager = $this->cashier;
        $manager->givePermissionTo('products.manage');

        $product = $this->variant('CPR8EA-9')->product;
        $variant = $product->variants()->firstOrFail();
        $price = $variant->priceOn();
        $cost = $variant->cost_price;

        app(ProductCatalogService::class)->update(
            $product,
            ['name' => 'Renamed plug'],
            [['id' => $variant->getKey(), 'price' => 1, 'cost' => 1]],
            [],
            [],
            $manager,
        );

        $variant->refresh();

        $this->assertSame('Renamed plug', $product->fresh()->name);
        $this->assertSame($price, $variant->priceOn());
        $this->assertSame($cost, $variant->cost_price);
    }

    public function test_catalog_service_refuses_users_without_product_permission(): void
    {
        $product = $this->variant('CPR8EA-9')->product;

        $this->expectException(PosException::class);

        app(ProductCatalogService::class)->update($product, ['name' => 'Nope'], [], [], [], $this->cashier);
    }
}
