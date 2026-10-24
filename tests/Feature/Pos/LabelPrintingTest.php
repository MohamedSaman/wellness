<?php

namespace Tests\Feature\Pos;

use App\Exceptions\PosException;
use App\Models\LabelPrint;
use App\Models\LabelQueueItem;
use App\Models\StockBatch;
use App\Models\VariantUnit;
use App\Services\Data\CartLine;
use App\Services\Data\LabelRequest;
use App\Services\Data\ReceiptLine;
use App\Services\ModuleService;
use App\Services\ReceivingItemService;
use App\Support\Barcode;
use Tests\Feature\Pos\Concerns\WorksWithLabels;
use Tests\Feature\Pos\Concerns\WorksWithPurchasing;

/**
 * Printing labels in the clinic (docs/PHARMACY.md §9, branch 7b): what a
 * delivery prints, the print log, the print page at the template's size,
 * the batch printed and the expiry not (by default), the print queue, who
 * may print, and every printed barcode scanning back to its item and pack
 * at the till and in purchasing.
 */
class LabelPrintingTest extends BatchTestCase
{
    use WorksWithLabels, WorksWithPurchasing;

    public function test_the_clinic_starts_with_labels_on_for_the_owner_and_not_the_cashier(): void
    {
        $this->assertTrue($this->shop->hasModule('labels'));
        $this->assertTrue($this->owner->can('labels.print'));
        $this->assertTrue($this->owner->can('labels.manage'));
        $this->assertFalse($this->cashier->can('labels.print'));
        $this->assertFalse($this->cashier->can('labels.manage'));

        $default = $this->labels()->defaultTemplate();
        $this->assertSame('Medicine label', $default->name);
        $this->assertSame('38 × 25 mm', $default->sizeLabel());
        $this->assertSame(['A4 sticker sheet', 'Medicine label'], $this->labels()->templates()->pluck('name')->sort()->values()->all());
    }

    public function test_a_delivery_prints_each_line_in_its_pack_as_many_as_received_with_its_batch(): void
    {
        $panadol = $this->medicine();
        $box = $this->boxOf($panadol);

        $grn = $this->postReceipt($this->supplier(), [
            new ReceiptLine($panadol->getKey(), 5, $box, freeQuantity: 1, unitCost: 10_000, batchNo: 'B1', expiry: '12/2027'),
            new ReceiptLine($panadol->getKey(), 24, unitCost: 1_000, batchNo: 'B2', expiry: '06/2028'),
        ]);

        $requests = $this->labels()->forReceipt($grn);

        $this->assertCount(2, $requests);
        $this->assertSame([$box, 6, 'B1', '2027-12-31'], [$requests[0]->unitId, $requests[0]->quantity, $requests[0]->batchNo, $requests[0]->expiry], '5 boxes + 1 free = 6 box labels');
        $this->assertSame([null, 24, 'B2', '2028-06-30'], [$requests[1]->unitId, $requests[1]->quantity, $requests[1]->batchNo, $requests[1]->expiry], '24 tablets = 24 labels of the tablet');
        $this->assertSame($this->batch($panadol, 'B1')->getKey(), $requests[0]->stockBatchId);

        $print = $this->printLabels($requests, source: 'delivery');
        $this->assertSame(30, $print->labels_count);
        $this->assertSame(['B1', 'B2'], array_column($print->items, 'batch_no'));
    }

    public function test_a_reversed_or_draft_delivery_prints_no_labels(): void
    {
        $panadol = $this->medicine();
        $draft = $this->draftReceipt($this->supplier(), [new ReceiptLine($panadol->getKey(), 10, batchNo: 'B1', expiry: '12/2027')]);

        $this->expectException(PosException::class);

        $this->labels()->forReceipt($draft);
    }

    public function test_a_print_is_logged_with_who_what_and_how_many(): void
    {
        $panadol = $this->medicine();
        $box = $this->boxOf($panadol);

        $print = $this->printLabels([new LabelRequest($panadol->getKey(), $box, 3), new LabelRequest($panadol->getKey(), null, 2)]);

        $this->assertSame($this->owner->getKey(), $print->user_id);
        $this->assertSame('Medicine label', $print->template_name);
        $this->assertSame(5, $print->labels_count);
        $this->assertSame('product', $print->source);
        $this->assertSame('Panadol 500 mg Tablets', $print->items[0]['name']);
        $this->assertSame('Box of 10', $print->items[0]['unit']);
        $this->assertSame(Barcode::inStoreEan13($box, 201), $print->items[0]['barcode']);
        $this->assertNull($print->items[1]['unit']);
        $this->assertSame($panadol->barcode, $print->items[1]['barcode']);
        $this->assertSame('Panadol 500 mg Tablets (Box of 10) × 3, Panadol 500 mg Tablets × 2', $print->summary());
    }

    public function test_the_print_page_is_the_templates_exact_size_one_label_a_page(): void
    {
        $panadol = $this->medicine();
        $print = $this->printLabels([new LabelRequest($panadol->getKey(), $this->boxOf($panadol), 3)]);

        $html = $this->printPage($print)
            ->assertOk()
            ->assertSee('@page { size: 38mm 25mm; margin: 0; }', false)
            ->assertSee('Print 3 labels')
            ->assertSee('Panadol 500 mg Tablets')
            ->assertSee('Box of 10')
            ->assertSee('LKR 180.00')
            ->assertSee('Demo Clinic')
            ->getContent();

        $this->assertSame(3, substr_count((string) $html, 'data-test="label"'));
        $this->assertSame(3, substr_count((string) $html, 'class="page"'));
    }

    public function test_a_sheet_prints_its_labels_across_and_down_starting_where_asked(): void
    {
        $panadol = $this->medicine();
        $sheet = $this->labels()->templates()->firstWhere('name', 'A4 sticker sheet');
        $print = $this->printLabels([new LabelRequest($panadol->getKey(), null, 70)], template: $sheet);

        $html = (string) $this->printPage($print, query: ['start' => 3])
            ->assertOk()
            ->assertSee('@page { size: 210mm 297mm; margin: 0; }', false)
            ->getContent();

        // 65 a sheet: 2 used on the first sheet, so 63 + 7.
        $this->assertSame(2, substr_count($html, 'class="page"'));
        $this->assertSame(70, substr_count($html, 'data-test="label"'));
        // Label 3 of the first sheet: third across, first row (4.75 + 2 × (38.1 + 2.5)).
        $this->assertStringContainsString('left: 85.95mm; top: 10.7mm;', $html);
    }

    public function test_the_batch_prints_and_the_expiry_does_not_unless_the_template_says_so(): void
    {
        $panadol = $this->medicine();
        $batch = $this->receive($panadol, 'B1', '12/2027', 10);

        $print = $this->printLabels([new LabelRequest($panadol->getKey(), null, 1, $batch->getKey())]);

        $this->printPage($print)->assertSee('B B1')->assertDontSee('Exp 12/2027');

        $template = $this->labels()->defaultTemplate();
        $this->labels()->saveTemplate($template, [...$template->toArray(), 'kind' => 'roll', 'fields' => [...$template->fields, 'expiry' => true]], $this->owner);

        $this->printPage($print)->assertSee('B B1 · Exp 12/2027');
    }

    public function test_every_printed_barcode_scans_back_to_its_item_and_pack_at_the_till_and_in_purchasing(): void
    {
        $panadol = $this->medicine();
        $box = $this->boxOf($panadol);
        $amoxil = $this->medicine('Amoxil 500 mg Capsules', [['name' => 'Box', 'factor' => 30, 'price' => 30_000, 'barcode' => '4790000000020']]);
        $this->receive($panadol, 'B1', '12/2027', 100);
        $this->receive($amoxil, 'A1', '12/2027', 90);

        $print = $this->printLabels([
            new LabelRequest($panadol->getKey(), null, 1),
            new LabelRequest($panadol->getKey(), $box, 1),
            new LabelRequest($amoxil->getKey(), $this->boxOf($amoxil), 1),
        ]);

        $printed = $this->barcodesOn((string) $this->printPage($print)->getContent());
        $this->assertCount(3, $printed);

        $expected = [
            [$panadol->getKey(), null],
            [$panadol->getKey(), $box],
            [$amoxil->getKey(), $this->boxOf($amoxil)],
        ];

        foreach ($printed as $i => $barcode) {
            [$variantId, $unitId] = $expected[$i];

            // What a scanner reads from the bars is the barcode on file.
            $this->assertSame(Barcode::EAN13, $barcode['symbology']);
            $scanned = $this->decodeEan13($barcode['svg']);
            $this->assertSame($barcode['code'], $scanned);

            // The till adds that item in that unit.
            $this->actingAs($this->cashier)
                ->getJson(route('pos.search', ['device' => $this->till->uuid, 'q' => $scanned]))
                ->assertOk()
                ->assertJsonPath('exact.variant_id', $variantId)
                ->assertJsonPath('exact.scanned_unit_id', $unitId);

            // An order or delivery line finds the item, and the pack.
            $this->assertSame([$unitId === null ? (string) $variantId : "{$variantId}:{$unitId}"], array_map(strval(...), array_keys(app(ReceivingItemService::class)->search($scanned))));
        }
    }

    public function test_the_print_queue_collects_labels_per_user_and_prints_them_in_one_go(): void
    {
        $panadol = $this->medicine();
        $box = $this->boxOf($panadol);
        $this->cashier->givePermissionTo('labels.print');

        $this->assertSame(4, $this->labels()->addToQueue($this->owner, [new LabelRequest($panadol->getKey(), $box, 4)], 'product', 'Panadol'));
        $this->labels()->addToQueue($this->owner, [new LabelRequest($panadol->getKey(), $box, 2), new LabelRequest($panadol->getKey(), null, 1)], 'products');
        $this->labels()->addToQueue($this->cashier, [new LabelRequest($panadol->getKey(), $box, 5)], 'product');

        $this->assertSame(7, $this->labels()->queuedLabels($this->owner));
        $this->assertSame(6, (int) LabelQueueItem::query()->where('user_id', $this->owner->getKey())->where('variant_unit_id', $box)->value('quantity'), 'The same label is added up');

        $print = $this->labels()->printQueue($this->owner, $this->labels()->defaultTemplate());

        $this->assertSame(7, $print->labels_count);
        $this->assertSame('queue', $print->source);
        $this->assertSame('Panadol', $print->source_label);
        $this->assertSame(0, $this->labels()->queuedLabels($this->owner));
        $this->assertSame(5, $this->labels()->queuedLabels($this->cashier), 'Another user\'s queue is theirs');
    }

    public function test_batches_print_in_their_pack_one_each_or_one_per_pack_on_hand(): void
    {
        $panadol = $this->medicine();
        $b1 = $this->receive($panadol, 'B1', '12/2027', 25);

        $boxes = $this->labels()->forBatches([$b1], 'pack', perUnitOnHand: true);
        $this->assertSame([$this->boxOf($panadol), 2, 'B1', '2027-12-31'], [$boxes[0]->unitId, $boxes[0]->quantity, $boxes[0]->batchNo, $boxes[0]->expiry], '25 tablets = 2 full boxes of 10');

        $tablets = $this->labels()->forBatches([$b1], 'base', perUnitOnHand: true);
        $this->assertSame([null, 25], [$tablets[0]->unitId, $tablets[0]->quantity]);

        // Sold beyond its batches offline: the unallocated batch has no number to print.
        $amoxil = $this->medicine('Amoxil 500 mg Capsules');
        $a1 = $this->receive($amoxil, 'A1', '06/2028', 5);
        $this->sell([new CartLine($amoxil->getKey(), 6)], offline: true);
        $unallocated = $this->unallocated($amoxil);
        $this->assertNotNull($unallocated);

        $one = $this->labels()->forBatches(StockBatch::query()->whereKey([$a1->getKey(), $unallocated->getKey()])->get()->all(), 'pack');
        $this->assertCount(1, $one);
        $this->assertSame([1, 'A1'], [$one[0]->quantity, $one[0]->batchNo]);
    }

    public function test_printing_needs_labels_print_and_a_print_opens_for_its_printer_and_the_owner_only(): void
    {
        $panadol = $this->medicine();
        $request = [new LabelRequest($panadol->getKey(), null, 1)];

        try {
            $this->printLabels($request, $this->cashier);
            $this->fail('A cashier without labels.print cannot print');
        } catch (PosException $exception) {
            $this->assertSame('You are not allowed to print labels.', $exception->getMessage());
        }

        $ownersPrint = $this->printLabels($request);
        $this->cashier->givePermissionTo('labels.print');
        $cashiersPrint = $this->printLabels($request, $this->cashier);

        $this->printPage($cashiersPrint, $this->cashier)->assertOk();
        $this->printPage($ownersPrint, $this->cashier)->assertForbidden();
        $this->printPage($cashiersPrint)->assertOk();

        try {
            $template = $this->labels()->defaultTemplate();
            $this->labels()->saveTemplate($template, [...$template->toArray(), 'kind' => 'roll'], $this->cashier);
            $this->fail('Label templates are for the owner');
        } catch (PosException $exception) {
            $this->assertSame('Only the owner can set up label templates.', $exception->getMessage());
        }
    }

    public function test_with_the_module_off_nothing_prints_and_prints_are_not_found(): void
    {
        $panadol = $this->medicine();
        $print = $this->printLabels([new LabelRequest($panadol->getKey(), null, 1)]);

        app(ModuleService::class)->disable($this->shop, 'labels');

        $this->printPage($print)->assertNotFound();
        $this->expectExceptionMessage('Label printing is not switched on for this shop.');
        $this->printLabels([new LabelRequest($panadol->getKey(), null, 1)]);
    }

    public function test_a_pack_that_is_not_active_or_another_items_pack_is_refused(): void
    {
        $panadol = $this->medicine();
        $amoxil = $this->medicine('Amoxil 500 mg Capsules');
        VariantUnit::query()->whereKey($this->boxOf($panadol))->update(['is_active' => false]);

        foreach ([new LabelRequest($panadol->getKey(), $this->boxOf($panadol), 1), new LabelRequest($panadol->getKey(), $this->boxOf($amoxil), 1)] as $request) {
            try {
                $this->printLabels([$request]);
                $this->fail('Refused');
            } catch (PosException $exception) {
                $this->assertStringContainsString('is not active', $exception->getMessage());
            }
        }

        $this->assertSame(0, LabelPrint::query()->count());
    }
}
