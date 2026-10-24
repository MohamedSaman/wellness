<?php

namespace Tests\Feature\Pos;

use App\Enums\AdjustmentReason;
use App\Enums\PaymentMethod;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\BestSellersTable;
use App\Filament\Widgets\LowStockTable;
use App\Filament\Widgets\NetSalesChart;
use App\Filament\Widgets\TodayStats;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use App\Services\ShiftService;
use App\Services\StockService;
use Carbon\CarbonImmutable;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Livewire\Livewire;

/** The owner's dashboard at /admin. Every figure comes from ReportService. */
class DashboardStatsTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->shop->update(['timezone' => 'Asia/Colombo']);
        // 10:00 in Colombo on a Wednesday.
        $this->travelTo(CarbonImmutable::parse('2026-09-16 04:30:00', 'UTC'));
    }

    private function sell(float $quantity): void
    {
        app(CheckoutService::class)->checkout($this->cart([new CartLine($this->variant('CPR8EA-9')->getKey(), $quantity)]));
    }

    /** @return array{datasets: list<array{data: list<float>}>, labels: list<string>} */
    private function chartData(NetSalesChart $chart): array
    {
        return (fn (): array => $this->getData())->call($chart);
    }

    public function test_the_admin_dashboard_shows_the_shop_widgets_in_order_and_not_filaments_defaults(): void
    {
        $this->actingAs($this->owner)->get(Dashboard::getUrl())
            ->assertOk()
            ->assertSeeLivewire(TodayStats::class)
            ->assertSeeLivewire(NetSalesChart::class)
            ->assertSeeLivewire(BestSellersTable::class)
            ->assertSeeLivewire(LowStockTable::class)
            ->assertDontSeeLivewire(AccountWidget::class)
            ->assertDontSeeLivewire(FilamentInfoWidget::class);

        // Tiles, then the charts, then the tables (the charts in between since branch feat/dashboard-charts-branding).
        $order = array_map(fn (string $widget): ?int => $widget::getSort(), [TodayStats::class, NetSalesChart::class, BestSellersTable::class, LowStockTable::class]);
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order);
        $this->assertCount(4, array_unique($order));
    }

    public function test_todays_figures_leave_out_voided_sales_and_include_profit_for_the_owner(): void
    {
        $this->sell(2);
        $this->sell(1);
        $voided = app(CheckoutService::class)->checkout($this->cart([new CartLine($this->variant('CPR8EA-9')->getKey(), 5)]));
        app(CheckoutService::class)->void($voided, $this->owner, 'Mistake');
        $profit = 3 * (145_000 - $this->variant('CPR8EA-9')->cost_price);

        $this->actingAs($this->owner);

        Livewire::test(TodayStats::class)
            ->assertSeeText('Sales today')
            ->assertSeeText($this->shop->formatMoney(435_000))
            ->assertSeeText('Number of sales')
            ->assertSeeText('Average sale')
            ->assertSeeText($this->shop->formatMoney(217_500))
            ->assertSeeText('Gross profit today')
            ->assertSeeText($this->shop->formatMoney($profit));
    }

    public function test_gross_profit_is_never_shown_without_the_profit_permission(): void
    {
        $this->cashier->givePermissionTo('reports.view');
        $this->sell(1);
        $profit = 145_000 - $this->variant('CPR8EA-9')->cost_price;

        $this->actingAs($this->cashier);

        Livewire::test(TodayStats::class)
            ->assertSeeText($this->shop->formatMoney(145_000))
            ->assertDontSeeText('Gross profit today')
            ->assertDontSeeText($this->shop->formatMoney($profit));
    }

    public function test_users_without_reports_permission_cannot_load_the_widgets(): void
    {
        $this->actingAs($this->cashier);

        foreach ([TodayStats::class, NetSalesChart::class, BestSellersTable::class, LowStockTable::class] as $widget) {
            $this->assertFalse($widget::canView(), $widget);
            Livewire::test($widget)->assertForbidden();
        }
    }

    public function test_cash_expected_in_open_shifts_is_shown(): void
    {
        app(ShiftService::class)->open($this->till, $this->cashier, 500_000);
        $this->actingAs($this->owner);

        Livewire::test(TodayStats::class)
            ->assertSeeText('Cash expected in open shifts')
            ->assertSeeText($this->shop->formatMoney(500_000));
    }

    public function test_the_chart_shows_net_sales_per_shop_day_for_the_chosen_range(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-15 18:29:00', 'UTC')); // 23:59 on the 15th in Colombo
        $this->sell(1);
        $this->travelTo(CarbonImmutable::parse('2026-09-16 04:30:00', 'UTC'));
        $this->sell(2);

        $this->actingAs($this->owner);
        $chart = Livewire::test(NetSalesChart::class);

        $data = $this->chartData($chart->instance());
        $this->assertCount(30, $data['labels']);
        $this->assertSame('16 Sep', $data['labels'][29]);
        $this->assertSame([1450.0, 2900.0], array_slice($data['datasets'][0]['data'], 28));

        $chart->set('filter', '7');
        $this->assertCount(7, $this->chartData($chart->instance())['labels']);

        $chart->set('filter', '90');
        $this->assertCount(90, $this->chartData($chart->instance())['labels']);

        // Anything else falls back to 30 days.
        $chart->set('filter', '1; DROP TABLE sales');
        $this->assertCount(30, $this->chartData($chart->instance())['labels']);
    }

    public function test_best_sellers_lists_this_weeks_top_items(): void
    {
        $this->sell(3);
        $this->actingAs($this->owner);

        Livewire::test(BestSellersTable::class)
            ->assertSeeText($this->variant('CPR8EA-9')->sku)
            ->assertSeeText('NGK Spark Plug')
            ->assertSeeText($this->shop->formatMoney(435_000));
    }

    public function test_low_stock_shows_negative_stock_in_red_and_links_to_the_stock_page(): void
    {
        $plug = $this->variant('CPR8EA-9');
        $plug->update(['reorder_level' => 5]);
        app(StockService::class)->adjustTo($plug, $this->location, 0, AdjustmentReason::CountCorrection, $this->owner);
        $this->actingAs($this->owner);

        // At zero it is low, but not red.
        Livewire::test(LowStockTable::class)
            ->assertSeeText($plug->sku)
            ->assertDontSeeHtml('fi-color-danger');

        // Sold offline with no stock left: negative, shown in red.
        app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->till,
            cashier: $this->cashier,
            lines: [new CartLine($plug->getKey(), 2, tillUnitPrice: 145_000)],
            payments: [new PaymentInput(PaymentMethod::Cash, 290_000)],
            isOfflineSync: true,
            soldAt: now(),
        ));
        $this->assertSame(-2.0, $this->stockOf($plug));

        Livewire::test(LowStockTable::class)
            ->assertSeeText($plug->sku)
            ->assertSeeHtml('fi-color-danger')
            ->assertSee('stock-levels?search='.$plug->sku, escape: false)
            ->assertSee('low_stock', escape: false);
    }
}
