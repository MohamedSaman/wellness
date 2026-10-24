<?php

namespace Tests\Feature\Pos;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\ProductFormData;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VariantUnit;
use App\Services\Data\BatchInput;
use App\Services\StockService;
use Filament\Facades\Filament;
use Livewire\Livewire;

/** The product form's base unit and pack levels on a pharmacy (demo clinic). */
class AdminProductUnitsTest extends PharmacyTestCase
{
    /** Drug details every medicine needs; units here are typed by hand. */
    private const DRUG = ['generic_names' => ['Paracetamol'], 'dosage_form' => 'tablet', 'schedule' => 'otc'];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);
    }

    private function createPanadol(): ProductVariant
    {
        Livewire::test(CreateProduct::class)
            ->fillForm([
                'drug' => self::DRUG,
                'name' => 'Panadol 500 mg Tablets',
                'unit' => 'Tablet',
                'whole_units_only' => true,
                'variants' => [[
                    'price' => '15.00',
                    'cost' => '10',
                    'opening_stock' => 300,
                    'opening_batch_no' => 'PN1',
                    'opening_expiry' => '12/2028',
                    'allow_unit_sale' => true,
                    'packs' => [
                        ['name' => 'Strip', 'factor' => 10, 'price' => '140', 'barcode' => '4790000000101', 'is_sellable' => true, 'is_purchasable' => true],
                        ['name' => 'Box', 'factor' => 30, 'price' => '400', 'is_sellable' => true, 'is_purchasable' => true],
                    ],
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        return Product::query()->where('name', 'Panadol 500 mg Tablets')->firstOrFail()->variants()->firstOrFail();
    }

    /** @return array<string, int|null> unit name => price */
    private function prices(ProductVariant $variant): array
    {
        return $variant->units()->with('prices')->get()
            ->mapWithKeys(fn (VariantUnit $unit) => [$unit->name => $unit->prices->first()?->price])
            ->all();
    }

    public function test_owner_creates_a_medicine_with_a_loose_tablet_price_and_two_packs(): void
    {
        $variant = $this->createPanadol();

        $this->assertTrue($variant->product->whole_units_only);
        $this->assertSame(['Tablet' => 1_500, 'Strip' => 14_000, 'Box' => 40_000], $this->prices($variant));
        $this->assertSame([1.0, 10.0, 30.0], $variant->units()->pluck('factor')->map(fn ($f) => (float) $f)->all());
        $this->assertSame('4790000000101', $variant->units()->where('name', 'Strip')->value('barcode'));
        $this->assertSame(300.0, $variant->stockAt($this->general));
    }

    public function test_packs_can_be_repriced_added_and_deactivated_from_the_edit_form(): void
    {
        $variant = $this->createPanadol();
        $product = $variant->product;

        $form = ProductFormData::fromProduct($product);
        $this->assertSame(['Strip', 'Box'], array_column($form['variants'][0]['packs'], 'name'));

        $form['variants'][0]['packs'][0]['price'] = '150';
        $form['variants'][0]['packs'][1]['is_active'] = false;
        $form['variants'][0]['packs'][] = ['name' => 'Jar', 'factor' => 500, 'price' => '6000', 'is_sellable' => true, 'is_purchasable' => true];
        $form['variants'][0]['allow_unit_sale'] = false;

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['variants' => $form['variants']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['Tablet' => 1_500, 'Strip' => 15_000, 'Box' => 40_000, 'Jar' => 600_000], $this->prices($variant));
        $this->assertFalse($variant->units()->where('name', 'Box')->value('is_active'));
        $this->assertFalse($variant->baseUnit()->value('is_sellable'));
    }

    public function test_the_base_unit_field_locks_once_stock_has_moved(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm(['drug' => self::DRUG, 'name' => 'Amoxil 250 mg', 'unit' => 'Tablet', 'variants' => [['price' => '20']]])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::query()->where('name', 'Amoxil 250 mg')->firstOrFail();

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertFormFieldIsEnabled('unit')
            ->fillForm(['unit' => 'Capsule'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Capsule', $product->fresh()->unit);

        app(StockService::class)->stockIn($product->variants()->firstOrFail(), $this->general, 50, $this->owner, batch: new BatchInput('AM1', '12/2028'));

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertFormFieldIsDisabled('unit')
            ->assertSee('Locked: this product has stock movements');
    }

    public function test_the_pack_size_field_names_the_base_unit_in_a_hint_not_a_suffix(): void
    {
        // A suffix left no room to type in the narrow pack row.
        Livewire::test(CreateProduct::class)
            ->fillForm(['unit' => 'Tablet', 'variants' => [['packs' => [['name' => 'Strip']]]]])
            ->assertSee('Tablet per pack')
            ->assertDontSeeHtml('fi-input-wrp-suffix');
    }

    public function test_the_browser_accepts_a_whole_pack_size(): void
    {
        // min must be a whole number when step is 1, or the browser only accepts 10.001.
        $whole = Livewire::test(CreateProduct::class)
            ->fillForm(['unit' => 'Tablet', 'whole_units_only' => true, 'variants' => [['packs' => [['name' => 'Strip']]]]]);

        $whole->assertSeeHtml('min="1"')->assertSeeHtml('step="1"')->assertDontSeeHtml('min="0.001"');

        // Without whole units, a pack can hold 20.5 litres.
        Livewire::test(CreateProduct::class)
            ->fillForm(['unit' => 'litre', 'whole_units_only' => false, 'variants' => [['packs' => [['name' => 'Drum']]]]])
            ->assertSeeHtml('min="0.001"')
            ->assertDontSeeHtml('step="1"');
    }

    public function test_a_part_tablet_pack_is_a_form_error(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm([
                'drug' => self::DRUG,
                'name' => 'Bad pack',
                'unit' => 'Tablet',
                'whole_units_only' => true,
                'variants' => [['price' => '1', 'packs' => [['name' => 'Strip', 'factor' => '10.5', 'price' => '10']]]],
            ])
            ->call('create')
            ->assertHasFormErrors(['variants.0.packs.0.factor' => 'integer']);

        $this->assertFalse(Product::query()->where('name', 'Bad pack')->exists());
    }
}
