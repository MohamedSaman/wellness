<?php

namespace Tests\Feature\Pos;

use App\Models\DrugDetail;
use App\Models\VariantPrice;
use App\Models\VariantUnit;
use App\Services\BatchService;
use App\Services\ReceiptService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithReceipts;

/**
 * A reprint is the sale as it was sold (docs/PHARMACY.md §9, branch 9): the
 * name, strength, units, prices, batches and expiries on the sale's own
 * lines, never the product, its prices or its batches as they are now.
 */
class ReceiptSnapshotTest extends BatchTestCase
{
    use WorksWithCheckout, WorksWithReceipts;

    public function test_a_reprint_after_the_products_and_batches_were_edited_prints_what_was_sold(): void
    {
        $this->stockTheClinic();
        $sale = $this->billAtGeneral();
        $this->setShopSetting('receipt_show_expiry', true);
        $receipts = app(ReceiptService::class);
        $reprint = $receipts->recordReprint($sale, $this->owner, null);
        $before = $this->printed('sale', $receipts->sale($sale, $reprint));

        // Everything a receipt shows about the items changes afterwards.
        $this->panadol->product->update(['name' => 'Panadol Extra Tablets']);
        $this->panadol->update(['sku' => 'PAN-NEW']);
        DrugDetail::query()->where('product_id', $this->panadol->product_id)->update(['strength_value' => 650]);
        VariantUnit::query()->whereKey($this->unitNamed($this->panadol, 'Box of 30'))->update(['name' => 'Carton of 30']);
        VariantPrice::query()->where('product_variant_id', $this->panadol->getKey())->update(['price' => 99_900]);
        DB::table('products')->where('id', $this->amoxil->product_id)->update(['unit' => 'Cap']);
        app(BatchService::class)->correct($this->batch($this->amoxil, 'AX0921'), 'AX0921B', '12/2026', 'Typo on the GRN', $this->owner);
        app(BatchService::class)->correct($this->batch($this->panadol, 'PN2611'), 'PN2611', '04/2027', 'Typo on the GRN', $this->owner);

        $after = $this->printed('sale', $receipts->sale($sale->fresh() ?? $sale, $reprint));

        $this->assertSame($before, $after);
        $this->assertStringContainsString("Panadol Tablets 500 mg\n  1 Box of 30 x 540.00", $after);
        $this->assertStringContainsString('Batch PN2611 Exp 03/2027', $after);
        $this->assertStringContainsString('Batch AX0921 Exp 11/2026 x 5 Capsule', $after);
        $this->assertStringNotContainsString('Extra', $after);
        $this->assertStringNotContainsString('650', $after);
        $this->assertStringNotContainsString('999', $after);
    }

    public function test_the_reprint_page_marks_the_reprint_and_says_who_and_when(): void
    {
        $this->stockTheClinic();
        $sale = $this->billAtGeneral();
        $this->travel(2)->hours();
        $entry = app(ReceiptService::class)->recordReprint($sale, $this->cashier, $this->till);

        $this->actingAs($this->cashier)
            ->get(route('pos.receipt.reprinted', ['sale' => $sale, 'reprint' => $entry->getKey()]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('pos/Receipt')
                ->where('title', 'Reprint T1-000001')
                ->where('thermal.lines.0.text', '                *** REPRINT ***')
                ->where('thermal.lines', fn ($lines): bool => collect($lines)->pluck('text')->contains('            REPRINT 18/10/2026 12:00')
                    && collect($lines)->pluck('text')->contains('               by Clinic Cashier')));

        // Only whoever reprinted it may open it.
        $this->actingAs($this->owner)
            ->get(route('pos.receipt.reprinted', ['sale' => $sale, 'reprint' => $entry->getKey()]))
            ->assertNotFound();
    }

    public function test_the_header_reads_the_till_and_location_by_name_and_old_lines_without_a_strength_print_as_they_were(): void
    {
        $this->stockTheClinic();
        $sale = $this->billAtGeneral();

        // A line from before this branch: no strength or base unit copied (the migration fills them where it can).
        $sale->items()->update(['strength' => null, 'base_unit_name' => null]);
        $printed = $this->printed('sale', app(ReceiptService::class)->sale($sale->fresh() ?? $sale));

        $this->assertStringContainsString("Panadol Tablets\n  1 Box of 30 x 540.00", $printed);
        $this->assertStringContainsString('Batch AX0921 x 5', $printed);
        $this->assertStringContainsString('Till                            T1 Front counter', $printed);
        $this->assertStringContainsString('Location                        General Pharmacy', $printed);
    }

    public function test_sale_lines_keep_the_strength_and_base_unit_they_were_sold_with(): void
    {
        $this->stockTheClinic();
        $sale = $this->billAtGeneral();

        $this->assertSame([['500 mg', 'Tablet'], ['250 mg', 'Capsule']], $sale->items->map(fn ($item): array => [$item->strength, $item->base_unit_name])->all());
    }
}
