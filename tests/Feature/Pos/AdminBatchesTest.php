<?php

namespace Tests\Feature\Pos;

use App\Enums\AdjustmentReason;
use App\Enums\DrugRoute;
use App\Enums\StockMovementType;
use App\Filament\Pages\ShopSettings;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Sales\Pages\ViewSale;
use App\Filament\Resources\StockBatches\Pages\ListStockBatches;
use App\Filament\Resources\StockBatches\StockBatchResource;
use App\Filament\Resources\StockLevels\Pages\ListStockLevels;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Widgets\UnallocatedStockTable;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Services\Data\CartLine;
use App\Services\StockService;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The admin screens for batches (pharmacy): the medicine form's batch
 * tracking and opening batch, Stock in and Adjust by batch, the Batches
 * list with its expiry filters, settling unallocated stock, the dashboard
 * alert, the expiry settings, and batches on the sale page and return form.
 */
class AdminBatchesTest extends BatchTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);
    }

    /** @return array<string, mixed> */
    private function medicineForm(array $variant = []): array
    {
        return [
            'is_medicine' => true,
            'drug' => [
                'generic_names' => ['Paracetamol'],
                'dosage_form' => 'tablet',
                'schedule' => 'otc',
                'route' => 'oral',
                'min_days_to_expiry' => 30,
            ],
            'name' => 'Panadol 500 mg Tablets',
            'unit' => 'Tablet',
            'track_stock' => true,
            'variants' => [['price' => '20', 'cost' => '10', 'opening_stock' => 100, 'packs' => [], ...$variant]],
        ];
    }

    public function test_a_new_medicine_tracks_batches_and_counts_its_opening_stock_in_one(): void
    {
        Livewire::test(CreateProduct::class)
            ->assertFormFieldExists('track_batches')
            ->fillForm($this->medicineForm(['opening_batch_no' => 'pn-1', 'opening_expiry' => '12/2027']))
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::query()->where('name', 'Panadol 500 mg Tablets')->firstOrFail();
        $this->assertTrue($product->track_batches);
        $this->assertSame([DrugRoute::Oral, 30], [$product->drugDetail?->route, $product->drugDetail?->min_days_to_expiry]);
        $this->assertSame(['PN-1' => 100.0], $this->batchQuantities($product->variants()->firstOrFail()));

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertFormSet(['track_batches' => true, 'drug.route' => 'oral', 'drug.min_days_to_expiry' => 30])
            ->fillForm(['track_batches' => false])
            ->call('save')
            ->assertNotified('Panadol 500 mg Tablets has stock in batches. Batch tracking can only be turned off once its stock is zero.');

        $this->assertTrue($product->fresh()?->track_batches);
    }

    public function test_opening_stock_of_a_medicine_needs_its_batch(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm($this->medicineForm())
            ->call('create')
            ->assertHasFormErrors(['variants.0.opening_batch_no' => 'required', 'variants.0.opening_expiry' => 'required']);

        $this->assertSame(0, Product::query()->count());

        // A consultation fee is not a medicine and has no batches.
        Livewire::test(CreateProduct::class)
            ->fillForm(['is_medicine' => false, 'name' => 'Consultation', 'unit' => 'Visit', 'track_stock' => false, 'variants' => [['price' => '1500']]])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertFalse(Product::query()->where('name', 'Consultation')->firstOrFail()->track_batches);
    }

    public function test_stock_in_asks_for_the_batch_and_short_expiry_needs_accepting(): void
    {
        $panadol = $this->medicine();

        Livewire::test(ListStockLevels::class)
            ->callTableAction('stockIn', $panadol, data: ['location_id' => $this->general->getKey(), 'quantity' => 10, 'batch_no' => 'SD1', 'expiry' => '12/2026', 'unit_cost' => '10'])
            ->assertNotified('Batch SD1 expires on 31/12/2026, within 90 days. Tick "Accept short expiry" to receive it anyway (owner only).');

        $this->assertSame(0, StockMovement::query()->count());

        Livewire::test(ListStockLevels::class)
            ->callTableAction('stockIn', $panadol, data: ['location_id' => $this->general->getKey(), 'quantity' => 10, 'batch_no' => 'SD1', 'expiry' => '12/2026', 'accept_near_expiry' => true, 'unit_cost' => '10'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(['SD1' => 10.0], $this->batchQuantities($panadol));
    }

    public function test_adjust_counts_one_batch_or_a_batch_found_on_the_shelf(): void
    {
        $panadol = $this->medicine();
        $batch = $this->receive($panadol, 'A', '03/2027', 40);

        Livewire::test(ListStockLevels::class)
            ->callTableAction('adjust', $panadol, data: ['location_id' => $this->general->getKey(), 'batch_id' => $batch->getKey(), 'counted_quantity' => 38, 'reason' => 'damaged'])
            ->assertHasNoTableActionErrors();

        Livewire::test(ListStockLevels::class)
            ->callTableAction('adjust', $panadol, data: ['location_id' => $this->general->getKey(), 'batch_id' => 'new', 'batch_no' => 'FOUND', 'expiry' => '06/2027', 'counted_quantity' => 5, 'reason' => 'count_correction'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(['A' => 38.0, 'FOUND' => 5.0], $this->batchQuantities($panadol));
        $this->assertLedgerMatches();
    }

    public function test_the_batches_list_filters_by_expiry_without_unallocated_stock(): void
    {
        $panadol = $this->medicine();
        $expired = $this->foundExpired($panadol, 'GONE', '09/2026', 4);
        $soon = $this->receive($panadol, 'SOON', '11/2026', 3);
        $later = $this->receive($panadol, 'LATER', '06/2027', 1);
        $this->shop->update(['settings' => ['allow_negative_stock' => true]]);
        $this->sell([new CartLine($panadol->getKey(), 6)]); // SOON 3, LATER 1, 2 beyond the batches
        $unallocated = $this->unallocated($panadol);
        $this->assertNotNull($unallocated);
        // A count finds 3 more of SOON (a count never settles by itself).
        app(StockService::class)->adjustBatchTo($panadol, $this->general, $soon, 3, AdjustmentReason::CountCorrection, $this->owner);

        Livewire::test(ListStockBatches::class)
            ->assertCanSeeTableRecords([$expired, $soon, $unallocated])
            ->assertCanNotSeeTableRecords([$later]) // sold out
            ->filterTable('in_stock', false)
            ->assertCanSeeTableRecords([$expired, $soon, $later, $unallocated])
            ->filterTable('expiry', 'expired')
            ->assertCanSeeTableRecords([$expired])
            ->assertCanNotSeeTableRecords([$unallocated, $soon, $later])
            ->resetTableFilters()
            ->filterTable('in_stock', false)
            ->filterTable('expiry', '60')
            ->assertCanSeeTableRecords([$expired, $soon])
            ->assertCanNotSeeTableRecords([$later, $unallocated])
            ->resetTableFilters()
            ->filterTable('unallocated', true)
            ->assertCanSeeTableRecords([$unallocated])
            ->assertCanNotSeeTableRecords([$expired]);
    }

    public function test_settling_unallocated_stock_and_counting_a_batch_from_the_batches_list(): void
    {
        $panadol = $this->medicine();
        $this->shop->update(['settings' => ['allow_negative_stock' => true]]);
        $this->sell([new CartLine($panadol->getKey(), 3)]);
        $found = $this->foundExpired($panadol, 'FOUND', '06/2027', 10);
        $unallocated = $this->unallocated($panadol);
        $this->assertNotNull($unallocated);

        $this->assertTrue(UnallocatedStockTable::canView());
        $this->assertTrue(Filament::getCurrentPanel()?->hasDatabaseNotifications(), 'Pharmacy shops get the notification bell.');
        Livewire::test(UnallocatedStockTable::class)->assertSee('Unallocated stock')->assertSee($panadol->sku);

        Livewire::test(ListStockBatches::class)
            ->assertTableActionHidden('settle', $found)
            ->callTableAction('settle', $unallocated, data: ['batch_id' => $found->getKey(), 'quantity' => 3])
            ->assertHasNoTableActionErrors()
            ->callTableAction('count', $found, data: ['counted_quantity' => 0, 'reason' => 'expired'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(0.0, (float) $unallocated->fresh()?->quantity);
        $this->assertSame(0.0, (float) $found->fresh()?->quantity);
        $this->assertFalse(UnallocatedStockTable::canView(), 'The alert clears once settled.');

        Livewire::test(ListStockMovements::class)
            ->filterTable('batch', $found->getKey())
            ->assertCanSeeTableRecords(StockMovement::query()->where('stock_batch_id', $found->getKey())->get())
            ->assertCanNotSeeTableRecords(StockMovement::query()->where('type', StockMovementType::Sale)->get());
        $this->assertLedgerMatches();
    }

    public function test_cashiers_see_batches_but_cannot_count_or_settle(): void
    {
        $panadol = $this->medicine();
        $batch = $this->receive($panadol, 'A', '03/2027', 5);
        $this->actingAs($this->cashier);

        // Cashiers have no admin access at all.
        $this->get(StockBatchResource::getUrl('index'))->assertForbidden();

        $this->actingAs($this->owner);
        $this->get(StockBatchResource::getUrl('index'))->assertOk()->assertSee('Batches');
        Livewire::test(ListStockBatches::class)->assertTableActionVisible('count', $batch);
    }

    public function test_the_shop_sets_its_expiry_rules_and_where_goods_are_received(): void
    {
        Livewire::test(ShopSettings::class)
            ->assertFormSet(['min_days_to_expiry_for_sale' => 0, 'near_expiry_warning_days' => 90, 'receive_at_default_location' => true])
            ->assertSee('Receive goods at General Pharmacy')
            ->fillForm(['min_days_to_expiry_for_sale' => 14, 'near_expiry_warning_days' => 120, 'receive_at_default_location' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->shop->refresh();
        $this->assertSame([14, 120, false], [$this->shop->setting('min_days_to_expiry_for_sale'), $this->shop->setting('near_expiry_warning_days'), $this->shop->setting('receive_at_default_location')]);
    }

    public function test_the_sale_page_shows_batches_and_returns_say_which_batch(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'A', '11/2026', 4);
        $this->receive($panadol, 'B', '03/2027', 30);
        $sale = $this->sell([new CartLine($panadol->getKey(), 6)]);
        $line = $sale->items->sole();
        $pieces = $line->batches()->get()->keyBy('batch_no');

        $this->get(route('filament.admin.resources.sales.view', $sale))
            ->assertOk()
            ->assertSee('A · exp 11/2026 x 4')
            ->assertSee('B · exp 03/2027 x 2');

        Livewire::test(ViewSale::class, ['record' => $sale->getRouteKey()])
            ->callAction('return', data: [
                'items' => [['sale_item_id' => $line->getKey(), 'quantity' => 3, 'restock' => true, 'batches' => [
                    ['sale_item_batch_id' => $pieces['A']->getKey(), 'quantity' => 3],
                    ['sale_item_batch_id' => $pieces['B']->getKey(), 'quantity' => null],
                ]]],
                'refund_method' => 'cash',
                'reason' => 'changed_mind',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(['A' => 3.0, 'B' => 28.0], $this->batchQuantities($panadol));
        $this->assertSame(3.0, (float) $pieces['A']->fresh()?->returned_quantity);
    }

    public function test_stock_in_without_a_batch_is_impossible_for_a_tracked_item(): void
    {
        $panadol = $this->medicine();

        Livewire::test(ListStockLevels::class)
            ->callTableAction('stockIn', $panadol, data: ['location_id' => $this->general->getKey(), 'quantity' => 10])
            ->assertHasTableActionErrors(['batch_no' => 'required', 'expiry' => 'required']);

        $this->assertSame(0, StockBatch::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
    }
}
