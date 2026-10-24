<?php

namespace Tests\Feature\Pos;

use App\Enums\LabelField;
use App\Filament\Pages\PrintLabels;
use App\Filament\Resources\GoodsReceipts\Pages\ViewGoodsReceipt;
use App\Filament\Resources\LabelPrints\LabelPrintResource;
use App\Filament\Resources\LabelPrints\Pages\ListLabelPrints;
use App\Filament\Resources\LabelTemplates\LabelTemplateResource;
use App\Filament\Resources\LabelTemplates\Pages\CreateLabelTemplate;
use App\Filament\Resources\LabelTemplates\Pages\EditLabelTemplate;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\StockBatches\Pages\ListStockBatches;
use App\Models\LabelPrint;
use App\Models\LabelQueueItem;
use App\Models\LabelTemplate;
use App\Models\VariantUnit;
use App\Services\Data\LabelRequest;
use App\Services\Data\ReceiptLine;
use App\Support\Barcode;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithLabels;
use Tests\Feature\Pos\Concerns\WorksWithPurchasing;

/**
 * Labels in the admin panel (docs/PHARMACY.md §9, branch 7b): printing
 * from the product page, a delivery, the Batches screen and the product
 * list, the print queue, the print log, and the label templates with their
 * live preview (owner only).
 */
class AdminLabelsTest extends BatchTestCase
{
    use WorksWithLabels, WorksWithPurchasing;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);
    }

    public function test_the_product_page_prints_its_units_and_packs(): void
    {
        $panadol = $this->medicine();
        $batch = $this->receive($panadol, 'B1', '12/2027', 30);
        $box = $this->boxOf($panadol);

        $page = Livewire::test(EditProduct::class, ['record' => $panadol->product->getRouteKey()])
            ->assertActionVisible('printLabels')
            ->mountAction('printLabels');

        $lines = array_values($page->instance()->mountedActions[0]['data']['lines']);
        $this->assertSame([[null, 1], [$box, 0]], array_map(fn (array $line): array => [$line['unit_id'], $line['quantity']], $lines), 'One label of what a scan adds; none of the box until asked');

        $page->fillForm(fn (array $state): array => [
            ...$state,
            'lines' => array_map(fn (array $line): array => [...$line, 'quantity' => $line['unit_id'] === $box ? 4 : 0, 'stock_batch_id' => $batch->getKey()], $state['lines']),
        ])->callMountedAction()->assertHasNoActionErrors();

        $print = LabelPrint::query()->sole();
        $this->assertSame(4, $print->labels_count);
        $this->assertSame([$box, 'B1', '2027-12-31'], [$print->items[0]['unit_id'], $print->items[0]['batch_no'], $print->items[0]['expiry']]);
        $this->assertSame(Barcode::inStoreEan13($box, 201), VariantUnit::query()->whereKey($box)->value('barcode'), 'The box got its barcode to print');
    }

    public function test_a_delivery_offers_its_received_quantities_and_can_add_them_to_the_queue(): void
    {
        $panadol = $this->medicine();
        $box = $this->boxOf($panadol);
        $grn = $this->postReceipt($this->supplier(), [
            new ReceiptLine($panadol->getKey(), 5, $box, freeQuantity: 1, unitCost: 10_000, batchNo: 'B1', expiry: '12/2027'),
        ]);

        $page = Livewire::test(ViewGoodsReceipt::class, ['record' => $grn->getRouteKey()])
            ->assertActionVisible('printLabels')
            ->mountAction('printLabels');

        $line = array_values($page->instance()->mountedActions[0]['data']['lines'])[0];
        $this->assertSame([$box, 6, 'B1'], [$line['unit_id'], $line['quantity'], $line['batch_no']]);

        $page->callMountedAction(['queue' => true])->assertHasNoActionErrors();

        $queued = LabelQueueItem::query()->sole();
        $this->assertSame([6, 'delivery', $grn->grn_number, 'B1'], [$queued->quantity, $queued->source, $queued->source_label, $queued->batch_no]);
        $this->assertSame(0, LabelPrint::query()->count(), 'Queued, not printed');
    }

    public function test_a_draft_delivery_has_no_labels_to_print(): void
    {
        $panadol = $this->medicine();
        $draft = $this->draftReceipt($this->supplier(), [new ReceiptLine($panadol->getKey(), 10, batchNo: 'B1', expiry: '12/2027')]);

        Livewire::test(ViewGoodsReceipt::class, ['record' => $draft->getRouteKey()])->assertActionHidden('printLabels');
    }

    public function test_selected_batches_print_with_their_batch(): void
    {
        $panadol = $this->medicine();
        $b1 = $this->receive($panadol, 'B1', '12/2027', 25);
        $b2 = $this->receive($panadol, 'B2', '06/2028', 10);

        Livewire::test(ListStockBatches::class)
            ->callTableBulkAction('printLabels', [$b1, $b2], data: ['label_template_id' => $this->labels()->defaultTemplate()->getKey(), 'unit' => 'pack', 'copies' => 'on_hand'])
            ->assertHasNoTableBulkActionErrors();

        $print = LabelPrint::query()->sole();
        $this->assertSame('batches', $print->source);
        $this->assertSame([['B1', 2], ['B2', 1]], array_map(fn (array $item): array => [$item['batch_no'], $item['quantity']], $print->items));
    }

    public function test_the_product_list_finds_items_without_a_manufacturer_barcode_and_labels_them(): void
    {
        $inStore = $this->medicine();
        $manufacturer = $this->medicine('Cetirizine 10 mg Tablets', [['name' => 'Box', 'factor' => 10, 'price' => 9_000, 'barcode' => '4790000000020']]);
        $manufacturer->update(['barcode' => '4790000000013']);
        $boxless = $this->medicine('Amoxil 500 mg Capsules', [['name' => 'Box', 'factor' => 30, 'price' => 30_000]]);
        $boxless->update(['barcode' => '4790000000037']);

        Livewire::test(ListProducts::class)
            ->filterTable('without_manufacturer_barcode')
            ->assertCanSeeTableRecords([$inStore->product, $boxless->product])
            ->assertCanNotSeeTableRecords([$manufacturer->product])
            ->callTableBulkAction('giveBarcodes', [$boxless->product])
            ->callTableBulkAction('printLabels', [$inStore->product, $boxless->product], data: ['label_template_id' => $this->labels()->defaultTemplate()->getKey(), 'units' => 'all', 'quantity' => 2], arguments: ['queue' => true])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame(Barcode::inStoreEan13($this->boxOf($boxless), 201), VariantUnit::query()->whereKey($this->boxOf($boxless))->value('barcode'));
        $this->assertSame(8, $this->labels()->queuedLabels($this->owner), 'Each item and pack of two products, two of each');
    }

    public function test_the_print_queue_page_prints_everything_waiting_once(): void
    {
        $panadol = $this->medicine();
        $this->labels()->addToQueue($this->owner, [new LabelRequest($panadol->getKey(), $this->boxOf($panadol), 3)], 'product', 'Panadol');
        $item = LabelQueueItem::query()->sole();

        $this->get(PrintLabels::getUrl())->assertOk()->assertSee('Panadol 500 mg Tablets')->assertSee('Box of 10');
        $this->assertSame('3', PrintLabels::getNavigationBadge());

        Livewire::test(PrintLabels::class)
            ->assertCanSeeTableRecords([$item])
            ->call('updateTableColumnState', 'quantity', (string) $item->getKey(), 5)
            ->callTableAction('print', data: ['label_template_id' => $this->labels()->defaultTemplate()->getKey()])
            ->assertHasNoTableActionErrors();

        $print = LabelPrint::query()->sole();
        $this->assertSame([5, 'queue'], [$print->labels_count, $print->source]);
        $this->assertSame(0, LabelQueueItem::query()->count());
    }

    public function test_the_print_log_shows_a_users_own_prints_and_the_owner_everyones(): void
    {
        $panadol = $this->medicine();
        $this->cashier->givePermissionTo(['admin.access', 'labels.print']);
        $owners = $this->printLabels([new LabelRequest($panadol->getKey(), null, 2)]);
        $cashiers = $this->printLabels([new LabelRequest($panadol->getKey(), null, 1)], $this->cashier);

        Livewire::test(ListLabelPrints::class)->assertCanSeeTableRecords([$owners, $cashiers]);

        $this->actingAs($this->cashier);
        Livewire::test(ListLabelPrints::class)->assertCanSeeTableRecords([$cashiers])->assertCanNotSeeTableRecords([$owners]);
        $this->get(PrintLabels::getUrl())->assertOk();
        $this->get(LabelTemplateResource::getUrl('index'))->assertForbidden();
    }

    public function test_a_cashier_without_labels_print_sees_no_label_actions(): void
    {
        $panadol = $this->medicine();
        $this->cashier->givePermissionTo(['admin.access', 'products.manage']);
        $this->actingAs($this->cashier);

        Livewire::test(EditProduct::class, ['record' => $panadol->product->getRouteKey()])->assertActionHidden('printLabels');
        $this->get(PrintLabels::getUrl())->assertForbidden();
        $this->get(LabelPrintResource::getUrl('index'))->assertForbidden();
    }

    public function test_the_owner_sets_up_a_template_with_a_live_preview(): void
    {
        $this->get(LabelTemplateResource::getUrl('index'))->assertOk()->assertSee('Medicine label')->assertSee('A4 sticker sheet');

        Livewire::test(CreateLabelTemplate::class)
            ->assertSee('data-test="label-preview"', false)
            ->fillForm(['name' => 'Shelf 50 × 30', 'preset' => 'roll_50x30'])
            ->assertSchemaStateSet(['label_width' => 50.0, 'label_height' => 30.0, 'kind' => 'roll', 'dpi' => 203])
            ->assertSee('Bars 0.375 mm (3 dots at 203 dpi)')
            ->call('create')
            ->assertHasNoFormErrors();

        $template = LabelTemplate::query()->where('name', 'Shelf 50 × 30')->sole();
        $this->assertSame('roll_50x30', $template->preset);
        $this->assertFalse($template->prints(LabelField::Expiry), 'Expiry off by default');
        $this->assertSameIgnoringKeyOrder(LabelField::defaults(true), $template->fields);
    }

    public function test_a_template_too_small_to_scan_is_not_saved_and_the_preview_says_why(): void
    {
        $template = $this->labels()->defaultTemplate();

        Livewire::test(EditLabelTemplate::class, ['record' => $template->getKey()])
            ->fillForm(['label_width' => 25])
            ->assertSchemaStateSet(['preset' => 'custom'])
            ->assertSee('too long for a 25 mm wide label')
            ->call('save')
            ->assertNotified('The template can\'t be saved');

        $this->assertSame('38.00', $template->refresh()->label_width);
    }

    public function test_only_one_template_is_the_default_and_the_last_one_stays(): void
    {
        $sheet = $this->labels()->templates()->firstWhere('name', 'A4 sticker sheet');

        Livewire::test(EditLabelTemplate::class, ['record' => $sheet->getKey()])
            ->fillForm(['is_default' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['A4 sticker sheet'], LabelTemplate::query()->where('is_default', true)->pluck('name')->all());

        Livewire::test(EditLabelTemplate::class, ['record' => $sheet->getKey()])->callAction('delete');
        $this->assertSame(['Medicine label'], LabelTemplate::query()->where('is_default', true)->pluck('name')->all(), 'Another template becomes the default');

        $last = LabelTemplate::query()->sole();
        Livewire::test(EditLabelTemplate::class, ['record' => $last->getKey()])->callAction('delete')->assertNotified('Keep at least one label template.');
        $this->assertSame(1, LabelTemplate::query()->count());
    }
}
