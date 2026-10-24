<?php

namespace Tests\Feature\Pos;

use App\Enums\PayableStatus;
use App\Enums\StockMovementType;
use App\Enums\SupplierReturnReason;
use App\Enums\TaxCategory;
use App\Exceptions\PosException;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\SupplierPayables\SupplierPayableResource;
use App\Filament\Resources\SupplierReturns\SupplierReturnResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Widgets\SupplierPayablesDue;
use App\Models\Category;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\Data\GoodsReceiptData;
use App\Services\Data\ReceiptLine;
use App\Services\Data\SupplierReturnLine;
use App\Services\GoodsReceiptService;
use App\Services\ModuleService;
use App\Services\StockService;
use App\Services\SupplierReturnService;
use App\Services\SupplierService;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/**
 * Purchasing on the retail moto-gear shop: switched off (the default)
 * nothing about it shows or runs; switched on, it works for items without
 * batches exactly as for medicines, minus the batch and expiry.
 */
class RetailPurchasingTest extends AdminTestCase
{
    public function test_with_the_modules_off_nothing_shows_or_runs(): void
    {
        $this->actingAs($this->owner);

        $this->assertFalse($this->shop->hasModule('suppliers'));
        $this->assertFalse($this->shop->hasModule('purchasing'));

        foreach ([SupplierResource::class, PurchaseOrderResource::class, GoodsReceiptResource::class, SupplierReturnResource::class, SupplierPayableResource::class] as $resource) {
            $this->assertFalse($resource::canAccess(), "{$resource} is reachable with the module off.");
            $this->get($resource::getUrl('index'))->assertForbidden();
        }

        $this->get('/purchasing/goods-received/'.fake()->uuid().'/print')->assertNotFound();
        $this->get('/purchasing/suppliers/'.fake()->uuid().'/statement')->assertNotFound();
        $this->assertFalse(SupplierPayablesDue::canView(), 'No payables tile on the dashboard.');
        $this->assertArrayNotHasKey('supplier_return', StockMovementType::options(false));
        $this->assertArrayNotHasKey('receipt_reversal', StockMovementType::options(false));

        try {
            app(SupplierService::class)->create(['name' => 'Vega Helmets'], $this->owner);
            $this->fail('A supplier was created with the module off.');
        } catch (PosException $exception) {
            $this->assertStringContainsString('needs the suppliers module', $exception->getMessage());
        }
    }

    public function test_switched_on_it_works_for_items_without_batches(): void
    {
        $this->assertSame(['suppliers'], app(ModuleService::class)->enable($this->shop, 'purchasing'), 'Purchasing brings suppliers with it.');

        $plug = $this->variant('CPR8EA-9');
        $stock = app(StockService::class);
        $onHand = $this->stockOf($plug);
        $average = $stock->averageCost($plug, $this->location);
        $supplier = app(SupplierService::class)->create(['name' => 'NGK Lanka'], $this->owner);
        $grns = app(GoodsReceiptService::class);

        // Batch fields are ignored for an item that does not track batches.
        $grn = $grns->post($grns->saveDraft(null, new GoodsReceiptData($supplier->getKey(), $this->location->getKey(), [
            new ReceiptLine($plug->getKey(), 10, freeQuantity: 2, unitCost: 60_000, batchNo: 'X', expiry: '12/2027'),
        ]), $this->owner), $this->owner);

        $line = $grn->items->sole();
        $this->assertSame([null, null, null, '50000.0000'], [$line->batch_no, $line->expiry_date, $line->stock_batch_id, $line->base_unit_cost]);
        $this->assertSame($onHand + 12, $this->stockOf($plug));

        $movement = StockMovement::query()->findOrFail($line->stock_movement_id);
        $this->assertSame([StockMovementType::StockIn, 12.0, null], [$movement->type, (float) $movement->quantity, $movement->stock_batch_id]);
        $reaveraged = $stock->averageCost($plug, $this->location);
        $this->assertNotSame($average, $reaveraged);

        // A return leaves at the location's average, which does not change.
        $return = app(SupplierReturnService::class)->create($supplier, $this->location, SupplierReturnReason::Damaged, [new SupplierReturnLine($plug->getKey(), 2)], $this->owner);
        $this->assertSame([null, $reaveraged], [$return->items->sole()->batch_no, $return->items->sole()->unit_cost]);
        $this->assertSame($reaveraged, $stock->averageCost($plug, $this->location));

        // Without batches, stock is one pool: a reversal needs the location to still hold what came in.
        $reversal = $grns->reverse($grn, 'Wrong supplier', $this->owner);
        $out = StockMovement::query()->findOrFail($reversal->items->sole()->stock_movement_id);
        $this->assertSame([StockMovementType::ReceiptReversal, -12.0, null], [$out->type, (float) $out->quantity, $out->stock_batch_id]);
        $this->assertSame($onHand - 2, $this->stockOf($plug));
        $this->assertSame(['opening' => 0, 'purchases' => 0, 'returns' => $return->total, 'payments' => 0, 'balance' => -$return->total], app(SupplierService::class)->balance($supplier));
        $this->assertSame([], $stock->rebuildCosts());

        // Payables too: the reversed GRN owes nothing; the return (no batch to trace) is credit.
        $this->assertSame([PayableStatus::Reversed, null], [$grn->payable?->status, $return->goods_receipt_id]);
        $this->assertSame(['balance' => -$return->total, 'outstanding' => 0, 'credit' => $return->total, 'difference' => 0], app(SupplierService::class)->reconcile($supplier));
    }

    public function test_a_retail_item_is_created_from_an_order_line_with_the_retail_essentials(): void
    {
        app(ModuleService::class)->enable($this->shop, 'purchasing');
        $this->actingAs($this->owner);
        $category = Category::query()->firstOrFail();

        $page = Livewire::test(CreatePurchaseOrder::class);
        $key = (string) array_key_first($page->get('data.lines'));
        $page->instance()->callSchemaComponentMethod("form.lines.{$key}.variant_id", 'getSearchResultsForJs', ['search' => 'Motul 7100 10W40 1L']);

        $page->mountAction(TestAction::make('createOption')->schemaComponent("lines.{$key}.variant_id", 'form'))
            ->assertSchemaStateSet(['name' => 'Motul 7100 10W40 1L', 'unit' => 'pcs'])
            ->assertMountedActionModalDontSee('Generic name')
            ->setActionData(['category_id' => $category->getKey(), 'unit' => 'bottle', 'barcode' => '3374650000001', 'cost' => '3200'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $oil = ProductVariant::query()->where('barcode', '3374650000001')->with('product')->sole();
        $this->assertSame(['Motul 7100 10W40 1L', $category->getKey(), 'bottle', 320_000, false, false, TaxCategory::Standard], [
            $oil->product->name, $oil->product->category_id, $oil->product->unit, $oil->cost_price, $oil->product->needs_review, $oil->product->track_batches, $oil->product->tax_category,
        ]);
        $page->assertSet("data.lines.{$key}.variant_id", (string) $oil->getKey());

        // No price yet: not for sale at the till until one is set.
        $this->assertNull($oil->priceOn());
    }
}
