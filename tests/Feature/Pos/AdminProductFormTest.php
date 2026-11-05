<?php

namespace Tests\Feature\Pos;

use App\Enums\AttributeType;
use App\Enums\DosageForm;
use App\Enums\DrugSchedule;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\ProductFormData;
use App\Filament\Resources\Products\ProductResource;
use App\Models\AttributeOption;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\Shop;
use App\Models\StockBatch;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Services\Data\BatchInput;
use App\Services\Data\DrugData;
use App\Services\ProductCatalogService;
use App\Support\CurrentShop;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Livewire\Features\SupportTesting\Testable;
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

        // Medicine, Selling & stock, More details, the variant's More and its location rules.
        $this->assertSame(5, substr_count($edit->html(), 'isCollapsed: true'));

        // Only the variant's More and its location rules.
        $this->assertSame(2, substr_count(Livewire::test(CreateProduct::class)->html(), 'isCollapsed: true'));
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

    public function test_selling_loose_per_location_is_one_line_of_what_each_location_does(): void
    {
        $product = $this->syrup([['price' => 400, 'allow_unit_sale' => false, 'packs' => [['name' => 'Bottle', 'factor' => 100, 'price' => 38_000]]]]);
        $form = ProductFormData::fromProduct($product);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertSee('Sell loose: No at all locations');

        $doctors = Location::query()->where('code', 'DOC')->sole();
        $form['variants'][0]['loose_at'] = [$doctors->getKey() => 'yes'];

        $component = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['variants' => $form['variants']])
            ->call('save')
            ->assertHasNoFormErrors();

        $component->assertSee('No at GEN, Yes at DOC');
    }

    public function test_an_item_with_no_packs_sells_loose_whatever_its_setting_says(): void
    {
        $product = $this->syrup([['price' => 400, 'allow_unit_sale' => false]]);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertSee('Sell loose: Yes at all locations')
            ->assertSee('Sell loose Yes · 0 packs');
    }

    public function test_a_saved_variant_or_pack_has_no_delete_button_and_a_new_one_keeps_it(): void
    {
        $category = $this->categoryWithAxis();
        $product = $this->syrup([[
            'price' => 400,
            'options' => [AttributeOption::query()->where('value', 'Small')->sole()],
            'packs' => [['name' => 'Bottle', 'factor' => 100, 'price' => 38_000]],
        ]], $category);

        $component = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()]);
        $saved = array_key_first($component->get('data.variants'));

        $delete = fn (string $item, string $component = 'variants') => TestAction::make('delete')
            ->schemaComponent($component, 'form')
            ->arguments(['item' => $item]);

        $component->assertActionHidden($delete($saved));

        $pack = array_key_first($component->get("data.variants.{$saved}.packs"));
        $component->assertActionHidden($delete($pack, "variants.{$saved}.more.packs"));

        Livewire::test(CreateProduct::class)
            ->fillForm(['category_id' => $category->getKey()])
            ->tap(function ($create) use ($delete): void {
                $create->assertActionVisible($delete(array_key_first($create->get('data.variants'))));
            });
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

    public function test_the_footer_counts_the_errors_of_a_failed_save(): void
    {
        Livewire::test(CreateProduct::class)
            ->assertSeeHtml('novalidate')
            ->assertDontSee('Jump to first error')
            ->fillForm($this->medicineForm(['price' => null], ['name' => null]))
            ->call('create')
            ->assertHasFormErrors(['name' => 'required', 'variants.0.price' => 'required'])
            ->assertSee('2 errors · Jump to first error');

        Livewire::test(CreateProduct::class)
            ->fillForm($this->medicineForm(product: ['name' => null]))
            ->call('create')
            ->assertSee('1 error · Jump to first error');
    }

    public function test_save_and_add_another_leaves_a_blank_form_on_create_only(): void
    {
        $component = Livewire::test(CreateProduct::class)
            ->assertSee('Save & add another')
            ->fillForm($this->medicineForm(['opening_stock' => 5, 'opening_batch_no' => 'B1', 'opening_expiry' => '12/2030']))
            ->call('createAnother')
            ->assertHasNoFormErrors()
            ->assertFormSet(['name' => null]);

        $row = array_values($component->get('data.variants'))[0];

        $this->assertEmpty($row['opening_batch_no'] ?? null);
        $this->assertEquals(0, $row['opening_stock']);
        $this->assertSame((int) $this->owner->workLocation($this->shop)->getKey(), (int) $row['opening_location_id']);

        $variant = $this->saved()->variants()->sole();
        $this->assertSame('B1', StockBatch::query()->where('product_variant_id', $variant->getKey())->sole()->batch_no);

        $component->fillForm($this->medicineForm(product: ['name' => 'Second']))->call('create')->assertHasNoFormErrors();
        $this->assertSame(1, Product::query()->where('name', 'Second')->count());

        Livewire::test(EditProduct::class, ['record' => $this->saved()->getRouteKey()])
            ->assertSee('Save')
            ->assertDontSee('Save & add another');
    }

    private function panadol(): Product
    {
        $product = app(ProductCatalogService::class)->create(
            ['name' => 'Panadol 500 mg Tablets', 'unit' => 'Tablet', 'whole_units_only' => true],
            [[
                'sku' => 'PAN-500',
                'barcode' => '4790000000999',
                'price' => 2_000,
                'cost' => 1_000,
                'reorder_level' => 7,
                'bin_location' => 'A3',
                'opening_stock' => 40,
                'opening_batch' => new BatchInput('PN1', '12/2030'),
                'allow_unit_sale' => false,
                'packs' => [
                    ['name' => 'Strip', 'factor' => 10, 'price' => 18_000, 'barcode' => '4790000000998'],
                    ['name' => 'Old box', 'factor' => 50, 'price' => 90_000, 'is_active' => false],
                ],
            ]],
            user: $this->owner,
            drug: new DrugData(['Paracetamol'], DosageForm::Tablet, DrugSchedule::Otc, brandName: 'Panadol', strengthValue: 500, strengthUnit: 'mg', registrationNo: 'NMRA-1'),
        );

        return $product->refresh();
    }

    private function duplicating(Product $product): Testable
    {
        return Livewire::withQueryParams(['duplicate' => $product->getRouteKey()])->test(CreateProduct::class);
    }

    public function test_a_duplicate_starts_from_everything_but_what_belongs_to_the_original(): void
    {
        $component = $this->duplicating($this->panadol())
            ->assertFormSet([
                'unit' => 'Tablet',
                'drug.brand_name' => 'Panadol',
                'drug.strength_value' => '500',
            ]);

        $row = array_values($component->get('data.variants'))[0];

        $this->assertNull($row['id']);
        $this->assertNull($row['sku']);
        $this->assertNull($row['barcode']);
        $this->assertNull($row['cost']);
        $this->assertNull($row['average_cost'] ?? null);
        $this->assertNull($row['last_cost'] ?? null);
        $this->assertEquals(0, $row['opening_stock']);
        $this->assertNull($row['opening_batch_no']);
        $this->assertNull($row['opening_expiry']);

        $this->assertEquals(20, $row['price']);
        $this->assertEquals(7, $row['reorder_level']);
        $this->assertSame('A3', $row['bin_location']);
        $this->assertFalse($row['allow_unit_sale']);

        // The discontinued box stays behind: it would come back on sale.
        $packs = array_values($row['packs']);
        $this->assertCount(1, $packs);
        $this->assertSame('Strip', $packs[0]['name']);
        $this->assertEquals(180, $packs[0]['price']);
        $this->assertNull($packs[0]['id']);
        $this->assertNull($packs[0]['barcode']);
    }

    public function test_a_medicine_copy_starts_without_what_describes_that_strength(): void
    {
        $original = $this->panadol();
        $original->update(['description' => 'Panadol 500 mg, film coated']);

        $this->duplicating($original)
            ->assertFormSet(['name' => null, 'description' => null, 'drug.registration_no' => null])
            ->assertNotified(
                Notification::make()
                    ->title('Copied from Panadol 500 mg Tablets')
                    ->body('Check name and strength. Name prints on the receipt.')
                    ->info(),
            );
    }

    public function test_a_medicine_copy_cant_be_saved_until_its_name_is_typed_again(): void
    {
        $products = Product::query()->count();
        $original = $this->panadol();

        $this->duplicating($original)
            ->fillForm(['drug.strength_value' => '1000'])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required']);

        $this->assertSame($products + 1, Product::query()->count());
    }

    public function test_a_copy_of_something_that_is_not_a_medicine_keeps_its_name(): void
    {
        $product = app(ProductCatalogService::class)->create(
            ['name' => 'Consultation', 'unit' => 'pcs', 'description' => 'Doctor\'s fee'],
            [['sku' => 'CONSULT', 'price' => 150_000]],
            user: $this->owner,
        );

        $this->duplicating($product)
            ->assertFormSet(['name' => 'Consultation', 'description' => 'Doctor\'s fee'])
            ->assertNotified(
                Notification::make()
                    ->title('Copied from Consultation')
                    ->body('SKU, barcodes, cost and opening stock start empty.')
                    ->info(),
            );
    }

    public function test_duplicating_a_medicine_and_changing_its_strength_saves_a_new_product(): void
    {
        $original = $this->panadol();
        $variant = $original->variants()->sole();
        $stock = StockLevel::query()->where('product_variant_id', $variant->getKey())->sole();
        $before = [$original->attributesToArray(), $variant->attributesToArray(), $original->drugDetail?->attributesToArray()];
        $movements = StockMovement::query()->count();
        $batches = StockBatch::query()->count();

        $this->duplicating($original)
            ->fillForm(['name' => 'Panadol 1000 mg Tablets', 'drug.strength_value' => '1000'])
            ->call('create')
            ->assertHasNoFormErrors();

        $copy = $this->saved('Panadol 1000 mg Tablets');
        $copyVariant = $copy->variants()->sole();

        $this->assertNotSame($original->getKey(), $copy->getKey());
        $this->assertEquals(1_000, $copy->drugDetail->strength_value);
        $this->assertSame('Paracetamol', $copy->generics->sole()->name);
        $this->assertNotSame('PAN-500', $copyVariant->sku);
        $this->assertNotSame('4790000000999', $copyVariant->barcode);
        $this->assertSame('A3', $copyVariant->bin_location);
        $this->assertEquals(0, $copyVariant->cost_price);
        $this->assertNull($copyVariant->units()->where('name', 'Strip')->sole()->barcode);
        $this->assertSame(0, $copyVariant->units()->where('name', 'Old box')->count());

        // No stock or batches came along, and the original is as it was.
        $this->assertSame($movements, StockMovement::query()->count());
        $this->assertSame($batches, StockBatch::query()->count());
        $this->assertSame(0, StockLevel::query()->where('product_variant_id', $copyVariant->getKey())->where('quantity', '>', 0)->count());
        $this->assertSame($before, [$original->refresh()->attributesToArray(), $variant->refresh()->attributesToArray(), $original->drugDetail?->refresh()->attributesToArray()]);
        $this->assertEquals($stock->quantity, $stock->refresh()->quantity);
    }

    public function test_a_duplicate_keeps_options_that_still_apply_and_clears_the_rest(): void
    {
        $category = $this->categoryWithAxis();
        $product = $this->syrup([
            ['price' => 400, 'options' => [AttributeOption::query()->where('value', 'Small')->sole()]],
            ['price' => 500, 'options' => [AttributeOption::query()->where('value', 'Large')->sole()]],
        ], $category);

        $component = $this->duplicating($product);
        $rows = array_values($component->get('data.variants'));

        // In any order: nothing sorts a product's variants, and MySQL may differ from SQLite.
        $this->assertEqualsCanonicalizing([$this->option($category, 'Small'), $this->option($category, 'Large')], array_column($rows, 'options'));

        // The attribute is no longer a variant axis of the category.
        $category->productAttributes()->updateExistingPivot(ProductAttribute::query()->sole()->getKey(), ['is_variant_axis' => false]);

        $rows = array_values($this->duplicating($product)->get('data.variants'));

        $this->assertSame([[], []], array_column($rows, 'options'));
    }

    public function test_changing_the_category_of_a_duplicate_leaves_none_of_the_old_options(): void
    {
        $category = $this->categoryWithAxis();
        $plain = Category::query()->create(['name' => 'Plain', 'slug' => 'plain']);
        $product = $this->syrup([['price' => 400, 'options' => [AttributeOption::query()->where('value', 'Small')->sole()]]], $category);

        $this->duplicating($product)
            ->fillForm(['name' => 'Moved', 'category_id' => $plain->getKey()])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(0, $this->saved('Moved')->variants()->sole()->attributeValues()->count());
    }

    public function test_save_and_add_another_after_a_duplicate_leaves_a_blank_form(): void
    {
        $this->duplicating($this->panadol())
            ->fillForm(['name' => 'Copy'])
            ->call('createAnother')
            ->assertHasNoFormErrors()
            ->assertFormSet(['name' => null]);
    }

    public function test_a_duplicate_of_another_shops_product_is_not_found(): void
    {
        $other = Shop::query()->create(['name' => 'Other', 'code' => 'OTHER', 'business_type' => 'retail', 'currency' => 'LKR', 'timezone' => 'Asia/Colombo']);
        $foreign = app(CurrentShop::class)->runAs($other, fn (): Product => Product::query()->create(['name' => 'Theirs', 'unit' => 'pcs']));

        $this->get(ProductResource::getUrl('create', ['duplicate' => 'no-such-product']))->assertNotFound();
        $this->get(ProductResource::getUrl('create', ['duplicate' => $foreign->getRouteKey()]))->assertNotFound();
        $this->get(ProductResource::getUrl('create'))->assertOk();
    }

    public function test_only_who_can_create_products_sees_duplicate(): void
    {
        $product = $this->panadol();

        Livewire::test(ListProducts::class)->assertTableActionVisible('duplicate', $product);
        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])->assertActionVisible('duplicate');

        $this->actingAs($this->cashier);

        Livewire::test(ListProducts::class)->assertTableActionHidden('duplicate', $product);
    }
}
