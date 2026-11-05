<?php

namespace Tests\Feature\Pos;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\ProductFormData;
use App\Models\Product;
use Filament\Facades\Filament;
use Livewire\Livewire;

/** The product form's layout in a retail shop: one form, with nothing of the pharmacy in it. */
class AdminRetailProductFormTest extends PosTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);
    }

    private function helmet(): Product
    {
        return Product::query()->whereHas('variants', fn ($variants) => $variants->whereHas('attributeValues'))
            ->withCount('variants')
            ->orderByDesc('variants_count')
            ->firstOrFail();
    }

    public function test_the_layout_brings_nothing_of_the_pharmacy_with_it(): void
    {
        Livewire::test(CreateProduct::class)
            ->assertSee('Selling & stock')
            ->assertSee('More · Reorder 0 · Shelf -')
            ->assertSee('Save & add another')
            ->assertSee('Spare part details')
            ->assertDontSee('Sell loose')
            ->assertDontSee('Add pack')
            ->assertDontSee('Customise per location')
            ->assertDontSee('Medicine');
    }

    public function test_a_retail_product_keeps_its_variants_and_part_numbers_through_edit(): void
    {
        $product = $this->helmet();
        $form = ProductFormData::fromProduct($product);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['variants' => $form['variants']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($form['variants'], ProductFormData::fromProduct($product->refresh())['variants']);
    }

    public function test_a_retail_product_is_duplicated_with_its_sizes_and_colours(): void
    {
        $product = $this->helmet();

        $component = Livewire::withQueryParams(['duplicate' => $product->getRouteKey()])->test(CreateProduct::class);
        $rows = array_values($component->get('data.variants'));

        $this->assertCount($product->variants()->where('is_active', true)->count(), $rows);
        $this->assertNotEmpty(array_filter($rows[0]['options']));

        $component->fillForm(['name' => 'Copied helmet'])->call('create')->assertHasNoFormErrors();

        $copy = Product::query()->where('name', 'Copied helmet')->sole();
        $this->assertSame($product->variants()->where('is_active', true)->count(), $copy->variants()->count());
        $this->assertSame($product->category_id, $copy->category_id);
    }
}
