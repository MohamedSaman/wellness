<?php

namespace Tests\Feature\Pos;

use App\Filament\Pages\Reports\LowStockReport;
use App\Filament\Pages\Reports\PaymentMethodsReport;
use App\Filament\Pages\Reports\ProfitByProductReport;
use App\Filament\Pages\Reports\SalesByBrandReport;
use App\Filament\Pages\Reports\SalesByCashierReport;
use App\Filament\Pages\Reports\SalesByCategoryReport;
use App\Filament\Pages\Reports\SalesByDayReport;
use App\Filament\Pages\Reports\ShiftHistoryReport;
use App\Filament\Pages\Reports\StockMovementsReport;
use App\Filament\Pages\Reports\StockValuationReport;
use App\Filament\Pages\Reports\VoidsAndReturnsReport;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

class AdminReportsTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->shop->update(['timezone' => 'Asia/Colombo']);
    }

    /** @return array<string, array{0: class-string}> */
    public static function reports(): array
    {
        return [
            'sales by day' => [SalesByDayReport::class],
            'sales by cashier' => [SalesByCashierReport::class],
            'sales by category' => [SalesByCategoryReport::class],
            'sales by brand' => [SalesByBrandReport::class],
            'payment methods' => [PaymentMethodsReport::class],
            'voids and returns' => [VoidsAndReturnsReport::class],
            'shift history' => [ShiftHistoryReport::class],
            'stock valuation' => [StockValuationReport::class],
            'low stock' => [LowStockReport::class],
            'stock movements' => [StockMovementsReport::class],
            'profit by product' => [ProfitByProductReport::class],
        ];
    }

    /** @param class-string $report */
    #[DataProvider('reports')]
    public function test_owner_can_open_every_report(string $report): void
    {
        app(CheckoutService::class)->checkout($this->cart([new CartLine($this->variant('CPR8EA-9')->getKey(), 1)]));

        $this->actingAs($this->owner)->get($report::getUrl(panel: 'admin'))->assertSuccessful();
    }

    /** @param class-string $report */
    #[DataProvider('reports')]
    public function test_cashiers_cannot_open_reports(string $report): void
    {
        $this->actingAs($this->cashier)->get($report::getUrl(panel: 'admin'))->assertForbidden();
    }

    public function test_without_the_profit_permission_cost_and_profit_stay_hidden(): void
    {
        // An admin user with reports.view but not reports.profit.
        $this->cashier->givePermissionTo(['admin.access', 'reports.view']);
        $this->actingAs($this->cashier);

        $this->get(SalesByDayReport::getUrl(panel: 'admin'))->assertSuccessful();
        $this->get(ProfitByProductReport::getUrl(panel: 'admin'))->assertForbidden();

        Livewire::test(StockValuationReport::class)
            ->assertTableColumnExists('retail_value')
            ->assertTableColumnDoesNotExist('cost_value');

        Livewire::test(StockMovementsReport::class)->assertTableColumnDoesNotExist('unit_cost');

        $this->actingAs($this->owner);
        Livewire::test(StockValuationReport::class)->assertTableColumnExists('cost_value');
        Livewire::test(StockMovementsReport::class)->assertTableColumnExists('unit_cost');
    }

    public function test_csv_export_has_the_figures_for_the_chosen_shop_days(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-15 18:31:00', 'UTC')); // 00:01 on the 16th in Colombo
        app(CheckoutService::class)->checkout($this->cart([new CartLine($this->variant('CPR8EA-9')->getKey(), 2)]));

        $this->actingAs($this->owner);

        $csv = "Date,Sales,\"Sales total\",Discounts,Tax,Refunds,\"Net sales\"\n"
            ."2026-09-15,0,0.00,0.00,0.00,0.00,0.00\n"
            ."2026-09-16,1,2900.00,0.00,0.00,0.00,2900.00\n"
            ."Total,1,2900.00,0.00,0.00,0.00,2900.00\n";

        Livewire::withQueryParams(['from' => '2026-09-15', 'to' => '2026-09-16'])
            ->test(SalesByDayReport::class)
            ->assertSee('2,900.00')
            ->callTableAction('export')
            ->assertFileDownloaded('sales-by-day-report_2026-09-15_2026-09-16.csv', $csv);
    }

    public function test_profit_csv_is_owner_only_and_includes_cost(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-16 04:30:00', 'UTC'));
        app(CheckoutService::class)->checkout($this->cart([new CartLine($this->variant('CPR8EA-9')->getKey(), 1)]));
        $cost = $this->variant('CPR8EA-9')->cost_price;

        $this->actingAs($this->owner);

        Livewire::withQueryParams(['from' => '2026-09-16', 'to' => '2026-09-16'])
            ->test(ProfitByProductReport::class)
            ->assertSee('NGK Spark Plug')
            ->assertSee(number_format((145_000 - $cost) / 100, 2));
    }

    public function test_profit_by_product_shows_margin_per_product_and_overall(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-16 04:30:00', 'UTC'));
        $checkout = app(CheckoutService::class);
        $checkout->checkout($this->cart([new CartLine($this->variant('CPR8EA-9')->getKey(), 1)])); // 1,450 at cost 950
        $checkout->checkout($this->cart([new CartLine($this->variant('MOTUL')->getKey(), 1)]));    // 3,200 at cost 2,450

        $this->actingAs($this->owner);

        // Overall margin is total profit / total revenue (1,250 / 4,650), not a sum of the row percentages.
        $csv = "Product,Quantity,\"Revenue (before tax)\",Cost,\"Gross profit\",\"Margin %\"\n"
            ."\"Engine Oil 10W-30 (1L)\",1,3200.00,2450.00,750.00,23.4\n"
            ."\"NGK Spark Plug\",1,1450.00,950.00,500.00,34.5\n"
            ."Total,2,4650.00,3400.00,1250.00,26.9\n";

        Livewire::withQueryParams(['from' => '2026-09-16', 'to' => '2026-09-16'])
            ->test(ProfitByProductReport::class)
            ->callTableAction('export')
            ->assertFileDownloaded('profit-by-product-report_2026-09-16_2026-09-16.csv', $csv);
    }

    public function test_bad_dates_fall_back_to_the_last_30_days(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-16 04:30:00', 'UTC'));
        $this->actingAs($this->owner);

        Livewire::withQueryParams(['from' => 'yesterday', 'to' => '2026-02-31'])
            ->test(SalesByDayReport::class)
            ->assertSet('from', '2026-08-18')
            ->assertSet('to', '2026-09-16');
    }
}
