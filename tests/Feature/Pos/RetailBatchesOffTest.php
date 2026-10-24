<?php

namespace Tests\Feature\Pos;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\StockBatches\StockBatchResource;
use App\Filament\Widgets\UnallocatedStockTable;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\ReceiptService;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * With the pharmacy module off (the retail moto-gear shop), batches do not
 * exist: no batch field, no Batches screen, sales and voids move stock
 * exactly as before and receipts carry no batch lines.
 */
class RetailBatchesOffTest extends AdminTestCase
{
    public function test_retail_sales_voids_and_receipts_have_no_batches(): void
    {
        $plug = $this->variant('CPR8EA-9');
        $this->assertFalse($plug->product->track_batches);

        $sale = app(CheckoutService::class)->checkout($this->cart([new CartLine($plug->getKey(), 2)]));
        app(CheckoutService::class)->void($sale, $this->owner, 'Test');

        $this->assertSame(40.0, $this->stockOf($plug));
        $this->assertSame(0, StockBatch::query()->count());
        $this->assertFalse(StockMovement::query()->whereNotNull('stock_batch_id')->exists());
        $this->assertSame(0, $sale->items->sole()->batches()->count());
        $this->assertArrayNotHasKey('batches', app(ReceiptService::class)->sale($sale)['items'][0]);
    }

    public function test_retail_screens_have_no_batch_fields_or_screens(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(CreateProduct::class)
            ->assertFormFieldIsHidden('track_batches')
            ->fillForm(['name' => 'Chain lube', 'unit' => 'pcs', 'variants' => [['price' => '1500', 'opening_stock' => 5]]])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertFalse(Product::query()->where('name', 'Chain lube')->firstOrFail()->track_batches);
        $this->assertFalse(StockBatchResource::canAccess());
        $this->assertFalse(UnallocatedStockTable::canView());
        $this->assertFalse(Filament::getCurrentPanel()?->hasDatabaseNotifications(), 'No notification bell in a retail shop.');
        $this->get('/admin/stock-batches')->assertForbidden();
    }
}
