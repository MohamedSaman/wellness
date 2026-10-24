<?php

namespace Tests\Feature\Pos;

use App\Enums\AttributeType;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\RelationManagers\ProductAttributesRelationManager;
use App\Models\Category;
use App\Models\ProductAttribute;
use App\Models\Shop;
use App\Support\CurrentShop;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Livewire\Livewire;

/** Admin → Categories → Edit → Attributes: which attributes a category uses. */
class CategoryAttributesTest extends AdminTestCase
{
    private Category $category;

    private ProductAttribute $material;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::query()->where('name', 'Helmets')->firstOrFail();
        $this->material = ProductAttribute::query()->create(['name' => 'Shell material', 'code' => 'shell_material', 'type' => AttributeType::Select]);
        $this->actingAs($this->owner);
    }

    private function manager(): mixed
    {
        return Livewire::test(ProductAttributesRelationManager::class, [
            'ownerRecord' => $this->category,
            'pageClass' => EditCategory::class,
        ]);
    }

    public function test_an_attribute_is_attached_with_its_settings_and_detached_again(): void
    {
        $this->manager()
            ->callTableAction(AttachAction::class, data: [
                'recordId' => $this->material->getKey(),
                'is_variant_axis' => true,
                'is_required' => true,
                'sort_order' => 7,
            ])
            ->assertHasNoTableActionErrors();

        $pivot = $this->category->productAttributes()->whereKey($this->material->getKey())->firstOrFail()->pivot;
        $this->assertTrue((bool) $pivot->getAttribute('is_variant_axis'));
        $this->assertTrue((bool) $pivot->getAttribute('is_required'));
        $this->assertSame(7, (int) $pivot->getAttribute('sort_order'));
        $this->assertTrue($this->material->categories()->whereKey($this->category->getKey())->exists());

        $this->manager()
            ->assertCanSeeTableRecords([$this->material])
            ->callTableAction(DetachAction::class, $this->material)
            ->assertHasNoTableActionErrors();

        $this->assertFalse($this->category->productAttributes()->whereKey($this->material->getKey())->exists());
        $this->assertTrue(ProductAttribute::query()->whereKey($this->material->getKey())->exists(), 'Detaching must not delete the attribute.');
    }

    public function test_the_attach_list_offers_only_this_shops_attributes_not_yet_attached(): void
    {
        $attached = $this->category->productAttributes()->firstOrFail();
        $other = Shop::query()->create(['name' => 'Other Shop', 'code' => 'OTHER']);
        $foreign = app(CurrentShop::class)->runAs($other, fn (): ProductAttribute => ProductAttribute::query()
            ->create(['name' => 'Foreign shell', 'code' => 'shell_material', 'type' => AttributeType::Select]));

        $options = $this->manager()
            ->mountTableAction(AttachAction::class)
            ->instance()
            ->getMountedTableAction()
            ->getRecordSelect()
            ->getOptions();

        $this->assertArrayHasKey($this->material->getKey(), $options);
        $this->assertArrayNotHasKey($attached->getKey(), $options);
        $this->assertArrayNotHasKey($foreign->getKey(), $options);

        // Search goes by name or code.
        $search = $this->manager()
            ->mountTableAction(AttachAction::class)
            ->instance()
            ->getMountedTableAction()
            ->getRecordSelect()
            ->getSearchResults('shell_mat');

        $this->assertSame([$this->material->getKey() => 'Shell material'], $search);
    }

    public function test_another_shops_attribute_cannot_be_attached(): void
    {
        $other = Shop::query()->create(['name' => 'Other Shop', 'code' => 'OTHER']);
        $foreign = app(CurrentShop::class)->runAs($other, fn (): ProductAttribute => ProductAttribute::query()
            ->create(['name' => 'Foreign shell', 'code' => 'shell_material', 'type' => AttributeType::Select]));

        $this->manager()
            ->callTableAction(AttachAction::class, data: ['recordId' => $foreign->getKey(), 'sort_order' => 0])
            ->assertHasTableActionErrors(['recordId']);

        $this->assertSame(0, app(CurrentShop::class)->runAs($other, fn (): int => $foreign->categories()->count()));
    }
}
