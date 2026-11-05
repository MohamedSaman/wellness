<?php

namespace Tests\Feature\Pos;

use App\Enums\AttributeType;
use App\Enums\DosageForm;
use App\Enums\DrugSchedule;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\ProductFormData;
use App\Models\AttributeOption;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\StockBatch;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Services\Data\BatchInput;
use App\Services\Data\DrugData;
use App\Services\ProductCatalogService;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The product form's layout on a pharmacy: what it hides, collapses and
 * summarises must never change what is saved. What only a browser can see is
 * in tests/e2e/product-form.spec.ts.
 */
class AdminProductFormTest extends BatchTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);
    }

    /** @return array<string, mixed> */
    private function medicineForm(array $variant = [], array $product = []): array
    {
        return [
            'is_medicine' => true,
            'drug' => ['generic_names' => ['Paracetamol'], 'dosage_form' => 'tablet', 'schedule' => 'otc'],
            'name' => 'Panadol 500 mg Tablets',
            'unit' => 'Tablet',
            'track_stock' => true,
            'track_batches' => true,
            ...$product,
            'variants' => [['price' => '20', 'cost' => '10', 'opening_stock' => 0, 'packs' => [], ...$variant]],
        ];
    }

    private function saved(string $name = 'Panadol 500 mg Tablets'): Product
    {
        return Product::query()->where('name', $name)->firstOrFail();
    }

    /** A category whose products have variants, so a form can hold several rows. */
    private function categoryWithAxis(): Category
    {
        $size = ProductAttribute::query()->create(['name' => 'Pack size', 'code' => 'pack_size', 'type' => AttributeType::Select]);
        $size->options()->create(['value' => 'Small', 'code' => 'S', 'sort_order' => 0]);
        $size->options()->create(['value' => 'Large', 'code' => 'L', 'sort_order' => 1]);

        $category = Category::query()->create(['name' => 'Sized', 'slug' => 'sized']);
        $category->productAttributes()->attach([$size->getKey() => ['is_variant_axis' => true, 'is_required' => true, 'sort_order' => 1]]);

        return $category;
    }

    /** @return array<int, int> attribute id => option id */
    private function option(Category $category, string $value): array
    {
        $option = AttributeOption::query()->where('value', $value)->sole();

        return [(int) $option->product_attribute_id => (int) $option->getKey()];
    }

    public function test_a_new_row_starts_with_its_location_though_the_field_shows_only_with_stock(): void
    {
        $component = Livewire::test(CreateProduct::class);
        $row = array_values($component->get('data.variants'))[0];

        $this->assertSame((int) $this->owner->workLocation($this->shop)->getKey(), (int) $row['opening_location_id']);
    }

    public function test_a_batch_typed_at_no_opening_stock_is_saved_as_no_stock_at_all(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm($this->medicineForm(['opening_batch_no' => 'B1', 'opening_expiry' => '12/2030']))
            ->call('create')
            ->assertHasNoFormErrors();

        $variant = $this->saved()->variants()->sole();

        $this->assertSame(0, StockMovement::query()->where('product_variant_id', $variant->getKey())->count());
        $this->assertSame(0, StockBatch::query()->where('product_variant_id', $variant->getKey())->count());
    }

    public function test_opening_stock_goes_to_the_chosen_location_in_its_batch(): void
    {
        $doctors = Location::query()->where('code', 'DOC')->sole();

        Livewire::test(CreateProduct::class)
            ->fillForm($this->medicineForm([
                'opening_stock' => 12,
                'opening_location_id' => $doctors->getKey(),
                'opening_batch_no' => 'B1',
                'opening_expiry' => '12/2030',
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $variant = $this->saved()->variants()->sole();
        $level = StockLevel::query()->where('product_variant_id', $variant->getKey())->sole();

        $this->assertSame((int) $doctors->getKey(), (int) $level->location_id);
        $this->assertEquals(12, $level->quantity);
        $this->assertSame('B1', StockBatch::query()->where('product_variant_id', $variant->getKey())->sole()->batch_no);
    }

    public function test_a_variant_added_on_edit_asks_for_its_opening_batch_too(): void
    {
        $category = $this->categoryWithAxis();
        $product = app(ProductCatalogService::class)->create(
            ['name' => 'Syrup', 'unit' => 'Bottle', 'category_id' => $category->getKey()],
            [['price' => 1_000, 'options' => [AttributeOption::query()->where('value', 'Small')->sole()]]],
            user: $this->owner,
            drug: new DrugData(['Paracetamol'], DosageForm::Syrup, DrugSchedule::Otc),
        );
        $variants = ProductFormData::fromProduct($product)['variants'];
        $added = ['price' => '30', 'options' => $this->option($category, 'Large'), 'opening_stock' => 4, 'packs' => []];

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['variants' => [...$variants, $added]])
            ->call('save')
            ->assertHasFormErrors(['variants.1.opening_batch_no' => 'required', 'variants.1.opening_expiry' => 'required']);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['variants' => [...$variants, [...$added, 'opening_batch_no' => 'L1', 'opening_expiry' => '12/2030']]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(2, $product->variants()->count());
        $this->assertSame('L1', StockBatch::query()->sole()->batch_no);
    }

    public function test_the_sections_are_open_on_create_and_summarised_on_edit(): void
    {
        $product = app(ProductCatalogService::class)->create(
            ['name' => 'Panadol', 'unit' => 'Tablet'],
            [['price' => 1_000]],
            user: $this->owner,
            drug: new DrugData(['Paracetamol'], DosageForm::Tablet, DrugSchedule::Otc, brandName: 'Panadol', strengthValue: 500, strengthUnit: 'mg'),
        );

        $edit = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertSee('Paracetamol · Panadol · 500 mg · '.DosageForm::Tablet->label().' · '.DrugSchedule::Otc->label())
            ->assertSee('Tablet · Stock tracked · Batches and expiry · Whole units only')
            ->assertSee('No description');

        // Medicine, Selling & stock, More details and the variant's More.
        $this->assertSame(4, substr_count($edit->html(), 'isCollapsed: true'));

        // Only the variant's More.
        $this->assertSame(1, substr_count(Livewire::test(CreateProduct::class)->html(), 'isCollapsed: true'));
    }

    public function test_the_locked_base_unit_says_why_on_the_page_and_other_help_is_behind_an_icon(): void
    {
        $product = app(ProductCatalogService::class)->create(
            ['name' => 'Panadol', 'unit' => 'Tablet'],
            [['price' => 1_000, 'opening_stock' => 5, 'opening_batch' => new BatchInput('B1', '12/2030')]],
            user: $this->owner,
            drug: new DrugData(['Paracetamol'], DosageForm::Tablet, DrugSchedule::Otc),
        );

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertSeeHtml('id="form.unit-helper-text"')
            ->assertSee('Locked: this product has stock movements');

        // Help text is not on the page as text any more, only behind its icon.
        Livewire::test(CreateProduct::class)
            ->assertDontSeeHtml('id="form.unit-helper-text"')
            ->assertSee('Stock is counted in this unit (Tablet, Bottle, Vial).');
    }

    /** @param  array<int, mixed>  $variants */
    private function syrup(array $variants, ?Category $category = null): Product
    {
        return app(ProductCatalogService::class)->create(
            ['name' => 'Syrup', 'unit' => 'ml', 'category_id' => $category?->getKey()],
            $variants,
            user: $this->owner,
            drug: new DrugData(['Chlorphenamine'], DosageForm::Syrup, DrugSchedule::Otc),
        );
    }

    public function test_a_variants_rarely_used_fields_are_one_line_until_opened(): void
    {
        Livewire::test(CreateProduct::class)->assertSee('More · Reorder 0 · Shelf - · Sell loose Yes · 0 packs');

        $product = $this->syrup([[
            'price' => 400,
            'reorder_level' => 5,
            'bin_location' => 'A3',
            'allow_unit_sale' => false,
            'packs' => [['name' => 'Bottle', 'factor' => 100, 'price' => 38_000], ['name' => 'Box', 'factor' => 1_200, 'price' => 400_000]],
        ]]);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertSee('More · Reorder 5 · Shelf A3 · Sell loose No · 2 packs');
    }

    public function test_a_product_with_three_variants_saves_and_edits_with_the_fields_under_more(): void
    {
        $category = $this->categoryWithAxis();
        $large = AttributeOption::query()->where('value', 'Large')->sole();
        $small = AttributeOption::query()->where('value', 'Small')->sole();
        $row = fn (AttributeOption $option, string $sku, array $more = []): array => [
            'options' => [$option->product_attribute_id => $option->getKey()],
            'sku' => $sku,
            'price' => '10',
            'packs' => [],
            ...$more,
        ];

        Livewire::test(CreateProduct::class)
            ->fillForm($this->medicineForm(product: ['category_id' => $category->getKey()]))
            ->fillForm(['variants' => [
                $row($small, 'V1', ['reorder_level' => 4, 'bin_location' => 'A1']),
                $row($large, 'V2', ['opening_stock' => 6, 'opening_batch_no' => 'B2', 'opening_expiry' => '12/2030']),
                $row($small, 'V3', ['allow_unit_sale' => false, 'packs' => [['name' => 'Box', 'factor' => 10, 'price' => '90', 'is_sellable' => true, 'is_purchasable' => true]]]),
            ]])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = $this->saved();
        $variants = $product->variants()->orderBy('sku')->get()->keyBy('sku');

        $this->assertSame(['V1', 'V2', 'V3'], $variants->keys()->all());
        $this->assertEquals(4, $variants['V1']->reorder_level);
        $this->assertSame('A1', $variants['V1']->bin_location);
        $this->assertSame('B2', StockBatch::query()->sole()->batch_no);
        $this->assertSame('Box', $variants['V3']->units()->where('is_base', false)->sole()->name);

        $form = ProductFormData::fromProduct($product);
        $form['variants'] = array_map(fn (array $variant): array => ['reorder_level' => 9, ...$variant, 'bin_location' => 'B'.$variant['sku']], $form['variants']);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['variants' => $form['variants']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(3, $product->variants()->count());
        $this->assertSame(['BV1', 'BV2', 'BV3'], $product->variants()->orderBy('sku')->pluck('bin_location')->all());
    }
}
