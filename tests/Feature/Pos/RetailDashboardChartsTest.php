<?php

namespace Tests\Feature\Pos;

use App\Filament\Pages\Dashboard;
use App\Filament\Support\ChartColors;
use App\Filament\Widgets\IncomeChart;
use App\Filament\Widgets\NetSalesChart;
use App\Filament\Widgets\PaymentMethodsChart;
use App\Filament\Widgets\StockValueChart;
use App\Filament\Widgets\TopItemsChart;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\ReportService;
use App\Support\ReportPeriod;
use Filament\Widgets\ChartWidget;
use Livewire\Livewire;

/**
 * The retail shop's dashboard charts: net sales in one series, its top
 * items, payment methods and stock value by category; nothing about
 * consultations or counters.
 */
class RetailDashboardChartsTest extends AdminTestCase
{
    /** @return array{datasets: list<array<string, mixed>>, labels: list<string>} */
    private function data(string $widget): array
    {
        /** @var ChartWidget $chart */
        $chart = Livewire::test($widget)->instance();

        return (fn (): array => $this->getData())->call($chart);
    }

    public function test_the_retail_dashboard_has_its_charts_and_none_of_the_clinics(): void
    {
        app(CheckoutService::class)->checkout($this->cart([new CartLine($this->variant('CPR8EA-9')->getKey(), 3)]));
        $this->actingAs($this->owner);

        $this->get(Dashboard::getUrl())
            ->assertOk()
            ->assertSeeLivewire(NetSalesChart::class)
            ->assertSeeLivewire(TopItemsChart::class)
            ->assertSeeLivewire(PaymentMethodsChart::class)
            ->assertSeeLivewire(StockValueChart::class)
            ->assertDontSeeLivewire(IncomeChart::class);
        $this->assertFalse(IncomeChart::canView());

        $sales = $this->data(NetSalesChart::class);
        $this->assertSame(['Net sales'], array_column($sales['datasets'], 'label'));
        $this->assertSame(ChartColors::COUNTERS[0], $sales['datasets'][0]['backgroundColor']);
        $this->assertSame(4_350.0, $sales['datasets'][0]['data'][29]);
        $this->assertSame('Net sales', Livewire::test(NetSalesChart::class)->instance()->getHeading());

        $top = $this->data(TopItemsChart::class);
        $this->assertSame([4_350.0], $top['datasets'][0]['data']);
        $this->assertSame('Top 10 items by sales', Livewire::test(TopItemsChart::class)->instance()->getHeading());

        $stock = $this->data(StockValueChart::class);
        $rows = app(ReportService::class)->stockValuation(true);
        $this->assertSame(array_column($rows, 'category'), $stock['labels']);
        $this->assertSame(array_map(fn (array $row): float => $row['cost_value'] / 100, $rows), $stock['datasets'][0]['data']);
        $this->assertSame('Stock value by category', Livewire::test(StockValueChart::class)->instance()->getHeading());

        $this->assertSame(array_column(app(ReportService::class)->paymentMethods(ReportPeriod::thisMonth($this->shop)), 'method'), $this->data(PaymentMethodsChart::class)['labels']);
    }
}
