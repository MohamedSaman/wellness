<?php

namespace Tests\Feature\Pos;

use App\Models\AuditLog;
use App\Models\StockLevel;
use App\Services\Data\BatchInput;
use App\Services\Data\CartLine;
use App\Services\Data\TransferLine;
use App\Services\ProductCatalogService;
use App\Services\StockService;
use App\Services\StockTransferService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * pos:stock-rebuild --costs (docs/PHARMACY.md §5, deferred from branch 4):
 * each location's average and last cost rebuilt from the ledger by
 * replaying receipts, transfers and revaluations in the order they
 * happened. On a ledger StockService wrote, it finds nothing to change.
 */
class CostRebuildTest extends BatchTestCase
{
    public function test_replaying_the_ledger_gives_exactly_the_stored_costs(): void
    {
        $panadol = $this->medicine();
        $stock = app(StockService::class);

        $this->receive($panadol, 'A', '03/2027', 10, cost: 1_000);
        $this->receive($panadol, 'B', '06/2027', 20, cost: 1_001);              // 1,000.6667
        $this->receive($panadol, 'C', '09/2027', 30, $this->doctors, '3333.3333'); // a decimal cost, kept exactly
        $this->sell([new CartLine($panadol->getKey(), 7)]);
        app(StockTransferService::class)->create($this->general, $this->doctors, [new TransferLine($panadol->getKey(), 5)], $this->owner);
        app(ProductCatalogService::class)->revalueCost($panadol, 1_200, 'Supplier credit', $this->owner, $this->general);
        $this->receive($panadol, 'D', '12/2027', 4, cost: 1_300);                // re-averages from the revalued 1,200
        $stock->stockIn($panadol, $this->doctors, 6, $this->owner, null, batch: new BatchInput('E', '12/2027')); // no cost: no re-average

        $this->assertSame([], $stock->rebuildCosts());
        $this->assertSame(0, Artisan::call('pos:stock-rebuild', ['--costs' => true]));
        $this->assertStringContainsString('every average cost matches the ledger', Artisan::output());
    }

    public function test_a_cost_changed_outside_the_ledger_is_reported_and_fixed_on_request(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'A', '03/2027', 10, cost: 1_000);
        $this->receive($panadol, 'B', '06/2027', 10, cost: 1_100);

        DB::table('stock_levels')->where('product_variant_id', $panadol->getKey())->update(['average_cost' => '999.0000']);

        $differences = app(StockService::class)->rebuildCosts();
        $this->assertSame([[
            'sku' => $panadol->sku, 'location' => 'General Pharmacy',
            'stored_average' => '999.0000', 'rebuilt_average' => '1050.0000',
            'stored_last' => '1100.0000', 'rebuilt_last' => '1100.0000',
        ]], $differences);

        $this->assertSame(1, Artisan::call('pos:stock-rebuild', ['--costs' => true]));
        $this->assertStringContainsString('1 cost(s) differ from the ledger', Artisan::output());
        $this->assertSame('999.0000', StockLevel::query()->where('product_variant_id', $panadol->getKey())->value('average_cost'), 'Reporting changes nothing.');

        $this->assertSame(0, Artisan::call('pos:stock-rebuild', ['--costs' => true, '--fix' => true]));
        $this->assertSame('1050.0000', StockLevel::query()->where('product_variant_id', $panadol->getKey())->value('average_cost'));
        $this->assertSame(1_050, $panadol->fresh()?->cost_price);

        $log = AuditLog::query()->where('event', 'cost_rebuilt')->sole();
        $this->assertSameIgnoringKeyOrder(['location' => 'GEN', 'average_cost' => '999.0000', 'last_cost' => '1100.0000'], $log->old_values);
        $this->assertSame('1050.0000', $log->new_values['average_cost'] ?? null);

        $this->assertSame([], app(StockService::class)->rebuildCosts());
    }
}
