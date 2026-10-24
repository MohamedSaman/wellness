<?php

namespace Tests\Feature\Pos;

use App\Exceptions\PosException;
use App\Filament\Pages\Reports\LowStockReport;
use App\Filament\Pages\Reports\StockMovementsReport;
use App\Filament\Resources\Locations\LocationResource;
use App\Filament\Resources\StockLevels\Pages\ListStockLevels;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\ProductVariant;
use App\Models\SaleItem;
use App\Models\StockLevel;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\TransferLine;
use App\Services\LocationService;
use App\Services\StockService;
use App\Services\StockTransferService;
use App\Support\UnitCost;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/** The retail shop (multi_location off) after the locations branch: same figures, nothing new on screen. */
class RetailLocationsTest extends AdminTestCase
{
    public function test_the_migrations_give_every_level_its_variants_cost_and_every_line_its_sales_location(): void
    {
        $plug = $this->variant('CPR8EA-9');
        app(StockService::class)->stockIn($plug, $this->location, 10, $this->owner, 99_999);
        app(CheckoutService::class)->checkout($this->cart([new CartLine($plug->getKey(), 3)]));

        $costs = ProductVariant::query()->withTrashed()->pluck('cost_price', 'id');
        $lastCosts = ProductVariant::query()->withTrashed()->pluck('last_cost_price', 'id');

        // Back to the schema before this branch, with the same data in it, then forward again.
        $steps = DB::table('migrations')->where('migration', '>=', '2026_10_18_000008_add_per_location_cost_and_location_columns')->count();
        Artisan::call('migrate:rollback', ['--step' => $steps]);
        $this->assertFalse(Schema::hasColumn('stock_levels', 'average_cost'));
        $this->assertFalse(Schema::hasColumn('sale_items', 'location_id'));
        $this->assertFalse(Schema::hasTable('stock_transfers'));

        Artisan::call('migrate');

        $levels = StockLevel::query()->get();
        $this->assertNotEmpty($levels);

        foreach ($levels as $level) {
            $this->assertSame(UnitCost::normalize((int) $costs[$level->product_variant_id]), $level->average_cost);
            $last = $lastCosts[$level->product_variant_id];
            $this->assertSame($last === null ? null : UnitCost::normalize((int) $last), $level->last_cost);
        }

        $this->assertSame(0, SaleItem::query()->where('location_id', '!=', $this->location->getKey())->count());
        $this->assertSame(SaleItem::query()->count(), SaleItem::query()->where('location_id', $this->location->getKey())->count());

        // The next sale costs exactly what it did before the branch.
        $sale = app(CheckoutService::class)->checkout($this->cart([new CartLine($plug->getKey(), 1)]));
        $this->assertSame($plug->fresh()->cost_price, $sale->items->sole()->unit_cost);
    }

    public function test_a_one_location_shop_keeps_its_cost_figures(): void
    {
        $plug = $this->variant('CPR8EA-9');
        $stock = app(StockService::class);

        // The first receipt after the migration starts from the old whole-minor average,
        // so it gives exactly what the integer formula gave.
        $before = $plug->fresh();
        $stock->stockIn($plug, $this->location, 10, $this->owner, 120_000);
        $this->assertSame(StockService::weightedAverageCost(40, (int) $before->cost_price, 10, 120_000), $plug->fresh()->cost_price);

        // From then on the average carries 4 decimals (Q9); the variant always shows it rounded.
        foreach ([[5, 133_333], [7, 101_010]] as [$quantity, $cost]) {
            $stock->stockIn($plug, $this->location, $quantity, $this->owner, $cost);
            $this->assertSame($plug->fresh()->cost_price, UnitCost::toMinor($stock->averageCost($plug, $this->location)));
        }
    }

    public function test_nothing_about_locations_shows_without_multi_location(): void
    {
        $this->actingAs($this->owner);
        $plug = $this->variant('CPR8EA-9');

        $this->assertFalse(LocationResource::canAccess());
        $this->assertFalse(StockTransferResource::canAccess());
        $this->get('/admin/locations')->assertForbidden();
        $this->get('/admin/stock-transfers')->assertForbidden();

        Livewire::test(ListStockLevels::class)
            ->assertTableFilterHidden('location')
            ->assertTableActionHidden('reorderLevel', $plug)
            // A location in the request is ignored: Stock In goes to the shop's one location.
            ->callTableAction('stockIn', $plug, data: ['location_id' => 999, 'quantity' => 2, 'unit_cost' => '900'])
            ->assertHasNoTableActionErrors();
        $this->assertSame(42.0, $this->stockOf($plug));

        Livewire::test(ListStockMovements::class)
            ->assertTableColumnHidden('location.name')
            ->assertTableFilterHidden('location');
        Livewire::test(StockMovementsReport::class)->assertTableColumnDoesNotExist('location')->assertDontSee('All locations');
        Livewire::test(LowStockReport::class)->assertTableColumnDoesNotExist('location');
        Livewire::test(EditUser::class, ['record' => $this->cashier->getRouteKey()])->assertFormFieldIsHidden('default_location_id');
    }

    public function test_locations_and_transfers_need_multi_location(): void
    {
        foreach ([
            fn () => app(LocationService::class)->create(['name' => 'Branch 2', 'code' => 'B2'], $this->owner),
            fn () => app(StockTransferService::class)->create($this->location, $this->location, [new TransferLine($this->variant('CPR8EA-9')->getKey(), 1)], $this->owner),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Allowed without multi_location.');
            } catch (PosException $exception) {
                $this->assertStringContainsString('multi_location', $exception->getMessage());
            }
        }
    }
}
