<?php

namespace Tests\Feature\Pos;

use App\Enums\TaxCategory;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\ProductFormData;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Services\ProductCatalogService;
use Livewire\Livewire;

class AdminProductsTest extends AdminTestCase
{
    public function test_owner_creates_a_helmet_with_variants_through_the_catalog_service(): void
    {
        $this->actingAs($this->owner);

        $category = Category::query()->where('name', 'Helmets')->firstOrFail();
        $axes = $category->productAttributes()->wherePivot('is_variant_axis', true)->with('options')->get();
        $helmetType = ProductAttribute::query()->where('name', 'Helmet Type')->with('options')->firstOrFail();
        $variantOptions = fn (int $n) => $axes->mapWithKeys(fn (ProductAttribute $a) => [$a->getKey() => $a->options->values()->get($n)->getKey()])->all();

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Test Admin Helmet',
                'category_id' => $category->getKey(),
                'product_attributes' => [$helmetType->getKey() => $helmetType->options->firstOrFail()->getKey()],
                'variants' => [
                    ['options' => $variantOptions(0), 'price' => '12500.50', 'cost' => '9000', 'reorder_level' => 2, 'bin_location' => 'A1', 'opening_stock' => 5],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::query()->where('name', 'Test Admin Helmet')->firstOrFail();
        $variant = $product->variants()->firstOrFail();

        $this->assertSame(1_250_050, $variant->priceOn());
        $this->assertSame(900_000, $variant->cost_price);
        $this->assertSame(5.0, $this->stockOf($variant));
        $this->assertNotNull($variant->barcode);
    }

    public function test_editing_a_product_updates_price_through_the_service(): void
    {
        $this->actingAs($this->owner);

        $product = Product::query()->where('name', 'Vega Off-Road Helmet')->firstOrFail();
        $variant = $product->variants()->orderBy('id')->firstOrFail();

        $variants = ProductFormData::fromProduct($product)['variants'];
        $variants[0]['price'] = '19999.00';

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['variants' => $variants])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(1_999_900, $variant->fresh()->priceOn());
    }

    public function test_generated_skus_keep_the_whole_product_name(): void
    {
        $product = app(ProductCatalogService::class)->create(['name' => 'STUDDS Ninja Elite Helmet'], [['price' => 1_000]], user: $this->owner);

        $this->assertSame('STUDDS-NINJA-ELITE-HELMET', $product->variants()->firstOrFail()->sku);
    }

    public function test_very_long_names_are_cut_at_a_whole_word_and_fit_the_sku_column(): void
    {
        $name = 'Studds Ninja Elite Super Decor Full Face Helmet With Extra Clear Visor And Spare Tinted Visor';

        $sku = app(ProductCatalogService::class)->create(['name' => $name], [['price' => 1_000]], user: $this->owner)
            ->variants()->firstOrFail()->sku;

        $this->assertSame('STUDDS-NINJA-ELITE-SUPER-DECOR-FULL-FACE-HELMET-WITH-EXTRA', $sku);
        $this->assertLessThanOrEqual(ProductCatalogService::SKU_BASE_LENGTH, strlen($sku));
    }

    public function test_duplicate_sku_is_reported_not_crashed(): void
    {
        $this->actingAs($this->owner);

        $existing = $this->variant('CPR8EA-9');
        $category = Category::query()->where('name', 'Helmets')->firstOrFail();

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Duplicate SKU product',
                'category_id' => null,
                'variants' => [['sku' => $existing->sku, 'price' => '100']],
            ])
            ->call('create');

        $this->assertFalse(Product::query()->where('name', 'Duplicate SKU product')->exists());
        $this->assertNotNull($category);
    }

    public function test_product_search_finds_by_sku_barcode_and_part_number(): void
    {
        $this->actingAs($this->owner);

        $plug = $this->variant('CPR8EA-9');
        $product = $plug->product;

        Livewire::test(ListProducts::class)
            ->searchTable($plug->sku)->assertCanSeeTableRecords([$product])
            ->searchTable($plug->barcode)->assertCanSeeTableRecords([$product])
            ->searchTable('CPR8EA')->assertCanSeeTableRecords([$product])
            ->searchTable('no-such-thing-xyz')->assertCanNotSeeTableRecords([$product]);
    }

    public function test_cashier_can_list_products_but_not_create_or_edit(): void
    {
        $this->actingAs($this->cashier);

        $product = $this->variant('CPR8EA-9')->product;

        Livewire::test(ListProducts::class)->assertSuccessful()->assertCanSeeTableRecords([$product]);
        $this->get(route('filament.admin.resources.products.create'))->assertForbidden();
        $this->get(route('filament.admin.resources.products.edit', $product))->assertForbidden();
    }

    public function test_without_the_pharmacy_module_the_form_has_no_pack_or_whole_unit_fields(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(CreateProduct::class)
            ->assertFormFieldIsHidden('whole_units_only')
            ->assertSee('Unit')
            ->assertDontSee('Base unit')
            ->assertDontSee('Add pack')
            ->assertDontSee('Sell loose');
    }

    public function test_without_the_pharmacy_module_the_form_has_no_medicine_or_tax_fields(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(CreateProduct::class)
            ->assertFormFieldIsHidden('is_medicine')
            ->assertFormFieldIsHidden('drug.generic_names')
            ->assertFormFieldIsHidden('tax_category')
            ->assertDontSee('This is a medicine')
            ->assertSee('Spare part details')
            ->assertSee('Brand')
            ->assertDontSee('Manufacturer');
    }

    public function test_without_the_pharmacy_module_the_list_has_no_medicine_columns_or_filters(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ListProducts::class)
            ->assertTableColumnHidden('drugDetail.schedule')
            ->assertTableColumnHidden('tax_category')
            ->assertTableFilterHidden('schedule')
            ->assertTableFilterHidden('dosage_form')
            ->assertTableFilterHidden('tax_category')
            ->assertTableFilterHidden('storage_condition')
            ->assertTableColumnHidden('drugDetail.registration_no')
            ->assertTableFilterVisible('is_active');
    }

    public function test_editing_a_retail_product_keeps_its_part_and_oem_numbers(): void
    {
        $this->actingAs($this->owner);

        $product = $this->variant('CPR8EA-9')->product;
        $oem = $product->oem_numbers;

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();
        $this->assertSame('CPR8EA-9', $product->part_number);
        $this->assertSame($oem, $product->oem_numbers);
        $this->assertFalse($product->isMedicine());
    }

    public function test_editing_a_retail_product_leaves_its_unit_and_flags_alone(): void
    {
        $this->actingAs($this->owner);

        $product = Product::query()->where('name', 'Vega Off-Road Helmet')->firstOrFail();

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertFormFieldIsDisabled('unit') // the demo stock has moved
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();
        $this->assertSame('pcs', $product->unit);
        $this->assertFalse($product->whole_units_only);
        $this->assertSame(TaxCategory::Standard, $product->tax_category);
        $this->assertSame(1, $product->variants()->firstOrFail()->units()->count());
    }

    public function test_cost_price_never_reaches_an_admin_user_without_cost_view(): void
    {
        $this->cashier->givePermissionTo('admin.access');
        $this->actingAs($this->cashier);

        $plug = $this->variant('CPR8EA-9');

        $html = $this->get(route('filament.admin.resources.products.index'))->assertSuccessful()->getContent();

        $this->assertStringNotContainsString('Cost price', $html);
        $this->assertFalse($this->cashier->can('cost.view'));
        $this->assertGreaterThan(0, $plug->cost_price);
    }
}
