<?php

namespace Tests\Feature\Pos;

use App\Enums\LabelField;
use App\Filament\Pages\PrintLabels;
use App\Filament\Resources\LabelPrints\LabelPrintResource;
use App\Filament\Resources\LabelTemplates\LabelTemplateResource;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\LabelTemplate;
use App\Models\Product;
use App\Services\Data\LabelRequest;
use App\Support\Barcode;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithLabels;

/**
 * Labels in the spare-parts shop (docs/PHARMACY.md §9, branch 7b): off by
 * default, and then nothing changes; switched on, items get shelf and item
 * labels with the name, SKU, part number, price and barcode, and never the
 * vehicles they fit.
 */
class RetailLabelsTest extends AdminTestCase
{
    use WorksWithLabels;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->owner);
    }

    private function plug(): Product
    {
        return Product::query()->where('name', 'NGK Spark Plug')->firstOrFail();
    }

    public function test_with_labels_off_nothing_changes(): void
    {
        $this->assertFalse($this->shop->hasModule('labels'));
        $plug = $this->plug();

        Livewire::test(EditProduct::class, ['record' => $plug->getRouteKey()])->assertActionHidden('printLabels');
        Livewire::test(ListProducts::class)
            ->assertTableBulkActionHidden('printLabels')
            ->assertTableBulkActionHidden('giveBarcodes');

        $this->get(PrintLabels::getUrl())->assertForbidden();
        $this->get(LabelTemplateResource::getUrl('index'))->assertForbidden();
        $this->get(LabelPrintResource::getUrl('index'))->assertForbidden();

        $labels = collect(Filament::getNavigation())->flatMap(fn ($group) => $group->getItems())->map->getLabel();
        $this->assertNotContains('Print labels', $labels);
        $this->assertNotContains('Label templates', $labels);

        // Items still get their in-store barcode, as before.
        $this->assertSame(Barcode::inStoreEan13($plug->variants()->firstOrFail()->getKey()), $plug->variants()->firstOrFail()->barcode);
        $this->assertSame(0, LabelTemplate::query()->count());
    }

    public function test_switched_on_a_spare_parts_shop_prints_name_sku_part_number_price_and_barcode(): void
    {
        $this->enableLabels();

        $template = $this->labels()->defaultTemplate();
        $this->assertSame('Item label', $template->name);
        $this->assertSame('50 × 25 mm', $template->sizeLabel());

        foreach ([LabelField::Name, LabelField::Sku, LabelField::PartNumber, LabelField::Price, LabelField::Barcode, LabelField::BarcodeText] as $on) {
            $this->assertTrue($template->prints($on), "{$on->value} on");
        }

        foreach ([LabelField::Strength, LabelField::Batch, LabelField::Expiry, LabelField::ShopName] as $off) {
            $this->assertFalse($template->prints($off), "{$off->value} off");
        }

        $this->assertSame([], $this->labels()->problems($template));
        $this->assertSame([], $this->labels()->problems($this->labels()->templates()->firstWhere('name', 'A4 sticker sheet')));

        $plug = $this->plug()->variants()->firstOrFail();
        $print = $this->printLabels([new LabelRequest($plug->getKey(), null, 2)]);

        $html = (string) $this->printPage($print)
            ->assertOk()
            ->assertSee('@page { size: 50mm 25mm; margin: 0; }', false)
            ->assertSee('NGK Spark Plug')
            ->assertSee("SKU {$plug->sku} · Part no. CPR8EA-9")
            ->assertSee('LKR 1,450.00')
            ->assertDontSee('Pulsar')
            ->assertDontSee('Discover')
            ->getContent();

        $printed = $this->barcodesOn($html);
        $this->assertCount(2, $printed);
        $this->assertSame((string) $plug->barcode, $this->decodeEan13($printed[0]['svg']));

        $this->actingAs($this->cashier)
            ->getJson(route('pos.search', ['device' => $this->till->uuid, 'q' => $plug->barcode]))
            ->assertJsonPath('exact.variant_id', $plug->getKey());
    }

    public function test_a_variant_is_labelled_with_its_size_and_colour(): void
    {
        $this->enableLabels();
        $helmet = Product::query()->where('name', 'Vega Off-Road Helmet')->firstOrFail()->variants()->orderBy('id')->firstOrFail();

        $print = $this->printLabels([new LabelRequest($helmet->getKey(), null, 1)]);

        $this->assertSame("Vega Off-Road Helmet – {$helmet->name}", $print->items[0]['name']);
        $this->printPage($print)->assertSee('Vega Off-Road Helmet')->assertSee('LKR 18,500.00');
    }

    public function test_the_retail_product_page_and_list_offer_labels_once_switched_on(): void
    {
        $this->enableLabels();

        Livewire::test(EditProduct::class, ['record' => $this->plug()->getRouteKey()])->assertActionVisible('printLabels');
        Livewire::test(ListProducts::class)->assertTableBulkActionVisible('printLabels');
        $this->get(PrintLabels::getUrl())->assertOk();
        $this->get(LabelTemplateResource::getUrl('index'))->assertOk()->assertSee('Item label');
    }
}
