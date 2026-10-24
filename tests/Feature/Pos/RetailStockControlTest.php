<?php

namespace Tests\Feature\Pos;

use App\Enums\StockMovementType;
use App\Exceptions\PosException;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Reorder;
use App\Filament\Resources\ExpiringStock\ExpiringStockResource;
use App\Filament\Resources\StockCounts\Pages\ListStockCounts;
use App\Filament\Resources\StockLevels\Pages\ListStockLevels;
use App\Filament\Resources\StockRequests\StockRequestResource;
use App\Filament\Resources\StockWriteOffs\StockWriteOffResource;
use App\Filament\Widgets\StockControlStats;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Services\Data\GoodsReceiptData;
use App\Services\Data\ReceiptLine;
use App\Services\Data\RequestLine;
use App\Services\GoodsReceiptService;
use App\Services\ModuleService;
use App\Services\ReorderService;
use App\Services\StockCountService;
use App\Services\StockRequestService;
use App\Services\StockService;
use App\Services\SupplierService;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;

/**
 * Retail with stock control (docs/PHARMACY.md §9, branch 10): no expiry,
 * write-off or stock request screens (no pharmacy, one location), while low
 * stock, reorder suggestions (purchase orders once purchasing is on) and
 * stock counts work for items without batches.
 */
class RetailStockControlTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->owner);
    }

    public function test_pharmacy_and_multi_location_screens_stay_hidden(): void
    {
        $this->assertFalse(ExpiringStockResource::canAccess());
        $this->assertFalse(StockWriteOffResource::canAccess());
        $this->assertFalse(StockRequestResource::canAccess());
        $this->assertFalse(StockControlStats::canView());
        $this->get('/admin/expiry')->assertForbidden();
        $this->get('/admin/stock-requests')->assertForbidden();
        $this->get(Dashboard::getUrl())->assertOk()->assertDontSee('Expired stock')->assertDontSee('Stock requests waiting');

        $this->flushSession();
        $this->actingAs($this->cashier)->get(route('pos.stock-requests.index'))->assertNotFound();
        $this->defaultCookies = [];
        $this->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page->where('cashier.canRequestStock', false));

        $this->expectException(PosException::class);
        $this->expectExceptionMessage('Stock requests need the multi_location module.');
        app(StockRequestService::class)->create($this->location, $this->location, [new RequestLine($this->variant('CPR8EA-9')->getKey(), 1)], $this->owner);
    }

    public function test_low_stock_and_reorder_suggestions_work_for_items_without_batches(): void
    {
        $plug = $this->variant('CPR8EA-9');
        $onHand = $this->stockOf($plug);
        $plug->update(['reorder_level' => $onHand + 10]);

        $row = collect(app(ReorderService::class)->suggestions($this->location))->firstWhere('sku', $plug->sku);
        $this->assertSame(2 * ($onHand + 10) - $onHand, $row['suggested'], 'No par level: twice the reorder level.');

        Livewire::test(ListStockLevels::class)
            ->assertTableActionHidden('reorderLevel', $plug)
            ->callTableAction('parLevel', $plug, data: ['par_level' => $onHand + 25])
            ->assertHasNoTableActionErrors();
        $this->assertSame((float) ($onHand + 25), (float) StockLevel::query()->where('product_variant_id', $plug->getKey())->value('par_level'));
        $this->assertSame(25.0, collect(app(ReorderService::class)->suggestions($this->location))->firstWhere('sku', $plug->sku)['suggested']);

        $this->get(Reorder::getUrl())->assertOk()->assertSee($plug->sku)->assertDontSee('Suggest request')->assertDontSee('Suggest purchase orders');

        try {
            app(ReorderService::class)->purchaseOrders($this->location, $this->owner);
            $this->fail('Orders were made without purchasing.');
        } catch (PosException $exception) {
            $this->assertSame('Purchase orders need the purchasing module.', $exception->getMessage());
        }

        // With purchasing on, the plug goes to its last supplier.
        app(ModuleService::class)->enable($this->shop, 'purchasing');
        $supplier = app(SupplierService::class)->create(['name' => 'NGK Lanka'], $this->owner);
        $grns = app(GoodsReceiptService::class);
        $grns->post($grns->saveDraft(null, new GoodsReceiptData($supplier->getKey(), $this->location->getKey(), [new ReceiptLine($plug->getKey(), 2, unitCost: 60_000)]), $this->owner), $this->owner);

        $orders = app(ReorderService::class)->purchaseOrders($this->location, $this->owner)['orders'];
        $line = collect($orders)->flatMap->items->firstWhere('product_variant_id', $plug->getKey());
        $this->assertSame(23.0, (float) $line?->quantity);
        $this->assertSame(60_000, $line?->unit_cost);
    }

    public function test_a_stock_count_of_items_without_batches(): void
    {
        $plug = $this->variant('CPR8EA-9');
        $onHand = $this->stockOf($plug);
        $counts = app(StockCountService::class);

        $count = $counts->start($this->location, $this->owner);
        $line = $count->lines()->where('product_variant_id', $plug->getKey())->sole();
        $this->assertNull($line->batch_no);
        $this->assertSame($onHand, (float) $line->expected_quantity);

        $counts->enter($line, $onHand - 3, null, $this->owner);
        $counts->post($count, $this->owner);

        $this->assertSame($onHand - 3, $this->stockOf($plug));
        $movement = StockMovement::query()->findOrFail($line->fresh()?->stock_movement_id);
        $this->assertSame([StockMovementType::AdjustmentOut, -3.0, 'count_correction'], [$movement->type, (float) $movement->quantity, $movement->reason_code]);
        $this->assertSame(0, app(StockService::class)->rebuildLevels(), 'The ledger and the levels agree.');

        Livewire::test(ListStockCounts::class)->assertCanSeeTableRecords([$count]);
    }
}
