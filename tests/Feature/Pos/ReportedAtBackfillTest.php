<?php

namespace Tests\Feature\Pos;

use App\Enums\BillType;
use App\Models\Sale;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branch 8's sale columns on a live retail install: every existing sale is a
 * standard bill reported at the time it was sold, so retail reports don't
 * move (docs/PHARMACY.md §7.5).
 */
class ReportedAtBackfillTest extends PosTestCase
{
    public function test_existing_sales_are_reported_at_their_sale_time(): void
    {
        $plug = $this->variant('CPR8EA-9');
        $this->travel(-3)->days();
        app(CheckoutService::class)->checkout($this->cart([new CartLine($plug->getKey(), 1)]));
        $this->travelBack();
        app(CheckoutService::class)->checkout($this->cart([new CartLine($plug->getKey(), 2)]));

        // Back to the schema before these columns, with the same sales in it.
        $steps = DB::table('migrations')->where('migration', '>=', '2026_10_23_000002_add_pharmacy_checkout_columns')->count();
        Artisan::call('migrate:rollback', ['--step' => $steps]);
        $this->assertFalse(Schema::hasColumn('sales', 'reported_at'));
        $this->assertFalse(Schema::hasColumn('sale_items', 'is_opd_medicine'));

        Artisan::call('migrate');

        $sales = Sale::query()->with('items')->orderBy('id')->get();
        $this->assertCount(2, $sales);

        foreach ($sales as $sale) {
            $this->assertSame($sale->sold_at->toDateTimeString(), $sale->reported_at?->toDateTimeString());
            $this->assertSame(BillType::Standard, $sale->bill_type);
            $this->assertNull($sale->clock_offset_seconds);
            $this->assertFalse($sale->items->sole()->is_opd_medicine);
        }
    }
}
