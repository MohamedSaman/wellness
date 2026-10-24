<?php

namespace Tests\Feature\Pos;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\PosException;
use App\Filament\Pages\Reorder;
use App\Filament\Resources\StockRequests\Pages\CreateStockRequest;
use App\Models\Location;
use App\Models\PurchaseOrder;
use App\Models\StockLevel;
use App\Models\StockRequest;
use App\Services\Data\PurchaseLine;
use App\Services\Data\ReceiptLine;
use App\Services\Data\RequestLine;
use App\Services\ReorderService;
use App\Services\StockRequestService;
use App\Services\StockService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithPurchasing;

/**
 * Reorder (docs/PHARMACY.md §9, branch 10): per-location low stock with par
 * levels; suggestions up to par (else twice the reorder level) less what is
 * coming; the OPD's request up to par; draft purchase orders grouped by each
 * item's last supplier, rounded up to the pack it was last received in.
 */
class ReorderTest extends BatchTestCase
{
    use WorksWithPurchasing;

    private function reorder(): ReorderService
    {
        return app(ReorderService::class);
    }

    /** @return array<string, float> sku => suggested */
    private function suggested(?Location $at = null): array
    {
        return collect($this->reorder()->suggestions($at ?? $this->general))->pluck('suggested', 'sku')->all();
    }

    public function test_suggestions_top_up_to_par_or_twice_the_reorder_level_less_what_is_coming(): void
    {
        $stock = app(StockService::class);
        $panadol = $this->medicine();
        $amoxil = $this->medicine('Amoxil 250 mg Capsules');
        $plenty = $this->medicine('Cetirizine 10 mg Tablets');
        $this->receive($panadol, 'P1', '03/2028', 30);
        $this->receive($amoxil, 'A1', '03/2028', 5);
        $this->receive($plenty, 'C1', '03/2028', 100);
        $stock->setReorderLevel($panadol, $this->general, 50);
        $stock->setParLevel($panadol, $this->general, 200);
        $amoxil->update(['reorder_level' => 20]);
        $plenty->update(['reorder_level' => 20]);

        // Lowest stock first.
        $this->assertSame([$amoxil->sku => 35.0, $panadol->sku => 170.0], $this->suggested());

        // Two boxes already on order for General Pharmacy count as coming.
        $this->sentOrder($this->supplier(), [new PurchaseLine($amoxil->getKey(), 2, $this->boxOf($amoxil), 15_000)]);
        $rows = collect($this->reorder()->suggestions($this->general))->keyBy('sku');
        $this->assertSame(20.0, $rows[$amoxil->sku]['incoming']);
        $this->assertSame(0.0, $rows[$amoxil->sku]['expired']);
        $this->assertSame(15.0, $rows[$amoxil->sku]['suggested']);
        $this->assertSame(40.0, $rows[$amoxil->sku]['target']);
        $this->assertSame(200.0, $rows[$panadol->sku]['par_level']);
    }

    public function test_expired_stock_on_hand_does_not_count_towards_the_target(): void
    {
        $panadol = $this->medicine();
        $this->foundExpired($panadol, 'OLD', '2026-09-30', 8);
        $this->receive($panadol, 'NEW', '03/2028', 2);
        app(StockService::class)->setReorderLevel($panadol, $this->general, 10);
        app(StockService::class)->setParLevel($panadol, $this->general, 30);

        $row = collect($this->reorder()->suggestions($this->general))->sole();
        $this->assertSame(10.0, $row['on_hand']);
        $this->assertSame(8.0, $row['expired']);
        $this->assertSame(28.0, $row['suggested']);
    }

    public function test_the_par_level_is_never_below_the_reorder_level(): void
    {
        $panadol = $this->medicine();
        app(StockService::class)->setReorderLevel($panadol, $this->general, 50);

        $this->expectException(PosException::class);
        $this->expectExceptionMessage("can't be below the reorder level (50)");

        app(StockService::class)->setParLevel($panadol, $this->general, 40);
    }

    public function test_the_opd_requests_up_to_par_less_what_it_already_asked_for(): void
    {
        $stock = app(StockService::class);
        $panadol = $this->medicine();
        $amoxil = $this->medicine('Amoxil 250 mg Capsules');
        $this->receive($panadol, 'P1', '03/2028', 5, $this->doctors);
        $this->receive($amoxil, 'A1', '03/2028', 500);
        $stock->setReorderLevel($panadol, $this->doctors, 20);
        $stock->setParLevel($panadol, $this->doctors, 60);
        // Amoxil is low at General Pharmacy only (never stocked at the OPD): not the OPD's to ask for.
        $amoxil->update(['reorder_level' => 1000]);

        app(StockRequestService::class)->create($this->general, $this->doctors, [new RequestLine($panadol->getKey(), 10)], $this->owner);

        $lines = $this->reorder()->requestLines($this->doctors);
        $this->assertCount(1, $lines);
        $this->assertSame((int) $panadol->getKey(), $lines[0]->variantId);
        $this->assertSame(45.0, $lines[0]->quantity);
        $this->assertNull($lines[0]->unitId);
    }

    public function test_draft_orders_go_to_each_items_last_supplier_in_its_last_pack(): void
    {
        $a = $this->supplier('Hemas Pharmaceuticals');
        $b = $this->supplier('Emerchemie');
        $panadol = $this->medicine();
        $amoxil = $this->medicine('Amoxil 250 mg Capsules');
        $cetirizine = $this->medicine('Cetirizine 10 mg Tablets');

        // Panadol came from Hemas, then from Emerchemie (the last one counts), by the box.
        $this->postReceipt($a, [new ReceiptLine($panadol->getKey(), 1, $this->boxOf($panadol), unitCost: 15_000, batchNo: 'P1', expiry: '03/2028')], receivedOn: '2026-10-01');
        $this->postReceipt($b, [new ReceiptLine($panadol->getKey(), 2, $this->boxOf($panadol), unitCost: 16_000, batchNo: 'P2', expiry: '03/2028')], receivedOn: '2026-10-10');
        // Amoxil came loose from Hemas.
        $this->postReceipt($a, [new ReceiptLine($amoxil->getKey(), 5, unitCost: 300, batchNo: 'A1', expiry: '03/2028')], receivedOn: '2026-10-12');
        // Cetirizine was only ever counted in, never bought.
        $this->receive($cetirizine, 'C1', '03/2028', 2);

        app(StockService::class)->setReorderLevel($panadol, $this->general, 50);
        app(StockService::class)->setParLevel($panadol, $this->general, 195); // 165 more: 17 boxes of 10
        $amoxil->update(['reorder_level' => 20]);
        $cetirizine->update(['reorder_level' => 10]);

        $result = $this->reorder()->purchaseOrders($this->general, $this->owner);

        $this->assertSame([['sku' => $cetirizine->sku, 'reason' => 'never received from a supplier']], $result['skipped']);
        $orders = collect($result['orders'])->keyBy(fn (PurchaseOrder $order): string => $order->supplier->name);
        $this->assertSame(['Hemas Pharmaceuticals', 'Emerchemie'], $orders->keys()->all());

        $hemas = $orders['Hemas Pharmaceuticals']->items->sole();
        $this->assertSame((int) $amoxil->getKey(), (int) $hemas->product_variant_id);
        $this->assertSame(35.0, (float) $hemas->quantity);
        $this->assertSame('Tablet', $hemas->unit_name);
        $this->assertSame(300, $hemas->unit_cost);

        $emerchemie = $orders['Emerchemie']->items->sole();
        $this->assertSame((int) $panadol->getKey(), (int) $emerchemie->product_variant_id);
        $this->assertSame(17.0, (float) $emerchemie->quantity);
        $this->assertSame('Box', $emerchemie->unit_name);
        $this->assertSame(170.0, (float) $emerchemie->base_quantity);
        $this->assertSame(16_000, $emerchemie->unit_cost);

        foreach ($result['orders'] as $order) {
            $this->assertSame(PurchaseOrderStatus::Draft, $order->status);
            $this->assertSame((int) $this->general->getKey(), (int) $order->location_id);
            $this->assertStringStartsWith('Suggested from low stock at General Pharmacy on 18/10/2026', (string) $order->notes);
        }

        // The drafts count as coming now: only the item nobody supplies is still suggested, and left out.
        $again = $this->reorder()->purchaseOrders($this->general, $this->owner);
        $this->assertSame([], $again['orders']);
        $this->assertSame([$cetirizine->sku], array_column($again['skipped'], 'sku'));
        $this->assertSame(2, PurchaseOrder::query()->count());
    }

    public function test_the_reorder_page_lists_low_stock_and_acts_on_it(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);
        $supplier = $this->supplier();
        $panadol = $this->medicine();
        $this->postReceipt($supplier, [new ReceiptLine($panadol->getKey(), 1, $this->boxOf($panadol), unitCost: 15_000, batchNo: 'P1', expiry: '03/2028')]);
        $this->receive($panadol, 'P1', '03/2028', 3, $this->doctors);
        app(StockService::class)->setReorderLevel($panadol, $this->general, 20);
        app(StockService::class)->setReorderLevel($panadol, $this->doctors, 5);

        $this->get(Reorder::getUrl())->assertOk()->assertSee('Panadol 500 mg Tablets')->assertSee('Hemas Pharmaceuticals')->assertSee('3 Box');

        // Par level from the list, then the OPD's request up to it.
        Livewire::test(Reorder::class, ['location' => (string) $this->doctors->getKey()])
            ->callTableAction('levels', (string) $panadol->getKey(), data: ['reorder_level' => 5, 'par_level' => 12])
            ->assertHasNoTableActionErrors()
            ->assertTableActionVisible('suggestRequest');
        $this->assertSame(12.0, (float) StockLevel::query()->where('location_id', $this->doctors->getKey())->where('product_variant_id', $panadol->getKey())->value('par_level'));

        Livewire::withQueryParams(['suggest' => (string) $this->doctors->getKey()])
            ->test(CreateStockRequest::class)
            ->assertSet('data.to_location_id', $this->doctors->getKey())
            ->assertSet('data.from_location_id', $this->general->getKey())
            ->call('create')
            ->assertHasNoFormErrors();
        $request = StockRequest::query()->sole();
        $this->assertSame(9.0, (float) $request->items->sole()->base_quantity);

        // General Pharmacy orders from the list: 10 on hand, 40 wanted, 3 boxes of 10.
        Livewire::test(Reorder::class, ['location' => (string) $this->general->getKey()])
            ->assertTableActionHidden('suggestRequest')
            ->callTableAction('suggestOrders')
            ->assertRedirect();
        $this->assertSame(3.0, (float) PurchaseOrder::query()->sole()->items->sole()->quantity);
    }
}
