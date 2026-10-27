<?php

namespace Tests\Feature\Pos;

use App\Enums\BillType;
use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Filament\Pages\Dashboard;
use App\Filament\Support\ChartColors;
use App\Filament\Widgets\Clinic\ClinicKpis;
use App\Filament\Widgets\Clinic\SalesTrendChart;
use App\Filament\Widgets\Clinic\StockValueDonut;
use App\Filament\Widgets\IncomeChart;
use App\Filament\Widgets\NetSalesChart;
use App\Filament\Widgets\PaymentMethodsChart;
use App\Filament\Widgets\StockValueChart;
use App\Filament\Widgets\TopItemsChart;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use App\Services\ReportService;
use App\Services\ReturnService;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * The dashboard's charts (branch feat/dashboard-charts-branding) on the
 * demo clinic, 18 October 2026: every figure is the report's own, so each
 * chart adds up to the report it comes from; a counter has one colour on
 * every chart; reports.view to see any, cost.view for stock value.
 */
class DashboardChartsTest extends BatchTestCase
{
    use WorksWithCheckout;

    private ReportService $reports;

    private ProductVariant $tabs;

    private ProductVariant $syrup;

    private Sale $genSale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reports = app(ReportService::class);
        $this->tabs = $this->medicine();
        $this->syrup = $this->syrup();
        $this->receive($this->tabs, 'G1', '03/2027', 200);
        $this->receive($this->tabs, 'D1', '03/2027', 100, $this->doctors);
        $this->receive($this->syrup, 'S1', '03/2027', 600, $this->doctors, 300);

        // Yesterday at General Pharmacy, by card.
        $this->travelTo(CarbonImmutable::parse('2026-10-17 05:00:00', 'UTC'));
        $this->genSale([new CartLine($this->tabs->getKey(), 2)], PaymentMethod::Card, 4_000);
        $this->travelTo(CarbonImmutable::parse('2026-10-18 04:30:00', 'UTC'));

        // Today: General Pharmacy in cash, one tablet of it returned; an OPD bill at the doctor's.
        $this->genSale = $this->genSale([new CartLine($this->tabs->getKey(), 5)], PaymentMethod::Cash);
        app(ReturnService::class)->process($this->genSale, [$this->genSale->items[0]->getKey() => 1], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);
        app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->docTill(),
            cashier: $this->cashierAt($this->doctors),
            lines: [
                new CartLine($this->consultation(150_000)->getKey(), 1),
                new CartLine($this->syrup->getKey(), 40),
                new CartLine($this->tabs->getKey(), 15),
            ],
            payments: [new PaymentInput(PaymentMethod::Cash, 300_000)],
            billType: BillType::Opd,
            opdMedicinesAmount: 80_000,
        ));
    }

    /** @param  list<CartLine>  $lines */
    private function genSale(array $lines, PaymentMethod $method, int $amount = 1_000_000): Sale
    {
        return app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->genTill(),
            cashier: $this->cashier,
            lines: $lines,
            payments: [new PaymentInput($method, $amount)],
        ));
    }

    /** @return array{datasets: list<array<string, mixed>>, labels: list<string>} */
    private function data(ChartWidget $chart): array
    {
        return (fn (): array => $this->getData())->call($chart);
    }

    /** A chart's money back in minor units. */
    private function minor(float $units): int
    {
        return (int) round($units * 100);
    }

    /**
     * @param  class-string<ChartWidget>  $widget
     * @return array{datasets: list<array<string, mixed>>, labels: list<string>}
     */
    private function chart(string $widget, ?string $filter = null): array
    {
        $test = Livewire::test($widget);

        if ($filter !== null) {
            $test->set('filter', $filter);
        }

        return $this->data($test->instance());
    }

    public function test_net_sales_by_counter_are_the_daily_summarys_counters_day_by_day(): void
    {
        $this->actingAs($this->owner);
        $period = ReportPeriod::lastDays($this->shop, 30);
        $data = $this->chart(NetSalesChart::class);
        $colors = ChartColors::locations();

        $this->assertCount(30, $data['labels']);
        $this->assertSame('18 Oct', $data['labels'][29]);
        $this->assertSame([$this->general->name, $this->doctors->name], array_column($data['datasets'], 'label'));
        $this->assertSame([$colors[$this->general->getKey()], $colors[$this->doctors->getKey()]], array_column($data['datasets'], 'backgroundColor'));

        foreach ($this->reports->counters($period) as $index => $counter) {
            $series = $data['datasets'][$index]['data'];
            $this->assertSame($counter['net'], $this->minor(array_sum($series)), $counter['location']);
            $this->assertSame(array_column($this->reports->salesByDay($period, $counter['location_id']), 'net'), array_map($this->minor(...), $series));
        }

        // General Pharmacy: 4,000 yesterday; 10,000 less a 2,000 refund today. The OPD bill: 150,000 + 80,000.
        $this->assertSame([4_000, 8_000], array_map($this->minor(...), array_slice($data['datasets'][0]['data'], 28)));
        $this->assertSame(230_000, $this->minor(array_sum($data['datasets'][1]['data'])));
        $this->assertSame('Net sales by counter', Livewire::test(NetSalesChart::class)->instance()->getHeading());
    }

    public function test_consultation_fees_and_medicines_are_the_daily_summarys_split(): void
    {
        $this->actingAs($this->owner);
        $period = ReportPeriod::thisMonth($this->shop);
        $data = $this->chart(IncomeChart::class);
        [$services, $goods] = $data['datasets'];

        $this->assertSame(['Consultation fees', ChartColors::SERVICES], [$services['label'], $services['backgroundColor']]);
        $this->assertSame(['Medicines', ChartColors::GOODS], [$goods['label'], $goods['backgroundColor']]);
        $this->assertCount(18, $data['labels']);

        $counters = collect($this->reports->counters($period));
        $this->assertSame($this->reports->consultations($period)['net'], $this->minor(array_sum($services['data'])));
        $this->assertSame((int) $counters->sum('services'), $this->minor(array_sum($services['data'])));
        $this->assertSame((int) $counters->sum('goods'), $this->minor(array_sum($goods['data'])));

        // Each day's two parts are that day's net sales.
        foreach ($this->reports->salesByDay($period) as $day => $row) {
            $this->assertSame($row['net'], $this->minor($services['data'][$day]) + $this->minor($goods['data'][$day]), $row['date']);
        }

        $this->assertSame(150_000, $this->minor(array_sum($services['data'])));
        $this->assertSame(4_000 + 8_000 + 80_000, $this->minor(array_sum($goods['data'])));
        $this->assertSame('Consultation fees LKR 1,500.00 · medicines LKR 920.00', Livewire::test(IncomeChart::class)->instance()->getDescription());
    }

    public function test_top_medicines_are_by_net_sales_as_monthly_sales_lists_them_without_the_consultation(): void
    {
        $this->actingAs($this->owner);
        $data = $this->chart(TopItemsChart::class);
        $byItem = collect($this->reports->salesByItem(ReportPeriod::thisMonth($this->shop)))->keyBy('item');

        $this->assertSame(['Panadol 500 mg Tablets', 'Paracetamol Syrup'], $data['labels']);
        $this->assertSame([$byItem['Panadol 500 mg Tablets']['net'], $byItem['Paracetamol Syrup']['net']], array_map($this->minor(...), $data['datasets'][0]['data']));
        $this->assertNotContains('Consultation', $data['labels']);
        $this->assertSame('Top 10 medicines by sales', Livewire::test(TopItemsChart::class)->instance()->getHeading());
    }

    public function test_payment_methods_are_the_reports_net_per_method(): void
    {
        $this->actingAs($this->owner);
        $rows = $this->reports->paymentMethods(ReportPeriod::thisMonth($this->shop));
        $data = $this->chart(PaymentMethodsChart::class);

        $this->assertSame(array_column($rows, 'method'), $data['labels']);
        $this->assertSame(array_column($rows, 'net'), array_map($this->minor(...), $data['datasets'][0]['data']));
        $this->assertSame(['Cash', 'Card'], $data['labels']);

        // Today only: no card payments.
        $this->assertSame(['Cash'], $this->chart(PaymentMethodsChart::class, 'today')['labels']);
    }

    public function test_stock_value_by_location_is_stock_valuation_at_cost_in_each_counters_colour(): void
    {
        $this->actingAs($this->owner);
        $rows = $this->reports->stockValuation(true, null, 'location');
        $data = $this->chart(StockValueChart::class);
        $colors = ChartColors::locations();

        $this->assertSame(array_column($rows, 'location'), $data['labels']);
        $this->assertSame(array_column($rows, 'cost_value'), array_map($this->minor(...), $data['datasets'][0]['data']));
        $this->assertSame([$colors[$this->general->getKey()], $colors[$this->doctors->getKey()]], $data['datasets'][0]['backgroundColor']);
        $this->assertSame('At cost: '.$this->shop->formatMoney($this->reports->stockValueAtCost()), Livewire::test(StockValueChart::class)->instance()->getDescription());

        // The counter colours are the same on the sales chart.
        $this->assertSame($data['datasets'][0]['backgroundColor'], array_column($this->chart(NetSalesChart::class)['datasets'], 'backgroundColor'));
    }

    public function test_the_period_filter_offers_the_report_presets_and_ignores_anything_else(): void
    {
        $this->actingAs($this->owner);

        $this->assertCount(7, $this->chart(NetSalesChart::class, '7')['labels']);
        $this->assertCount(18, $this->chart(NetSalesChart::class, 'this_month')['labels']);
        $this->assertCount(30, $this->chart(NetSalesChart::class, 'last_month')['labels']);
        $this->assertCount(1, $this->chart(IncomeChart::class, 'yesterday')['labels']);
        $this->assertCount(18, $this->chart(IncomeChart::class, '1; DROP TABLE sales')['labels']);
        $this->assertCount(30, $this->chart(NetSalesChart::class, 'custom')['labels']);
    }

    public function test_the_figures_are_kept_for_a_minute(): void
    {
        $this->actingAs($this->owner);
        $before = $this->minor(array_sum($this->chart(PaymentMethodsChart::class, 'today')['datasets'][0]['data']));

        $this->genSale([new CartLine($this->tabs->getKey(), 1)], PaymentMethod::Cash);
        $this->assertSame($before, $this->minor(array_sum($this->chart(PaymentMethodsChart::class, 'today')['datasets'][0]['data'])));

        $this->travel(61)->seconds();
        $this->assertSame($before + 2_000, $this->minor(array_sum($this->chart(PaymentMethodsChart::class, 'today')['datasets'][0]['data'])));
    }

    public function test_the_clinic_dashboard_has_its_own_layout_instead_of_the_period_charts(): void
    {
        // feat/clinic-theme-dashboard: the clinic's dashboard (ClinicDashboardTest); these charts stay the retail dashboard's.
        $page = $this->actingAs($this->owner)->get(Dashboard::getUrl())
            ->assertOk()
            ->assertSeeLivewire(ClinicKpis::class)
            ->assertSeeLivewire(SalesTrendChart::class)
            ->assertSeeLivewire(StockValueDonut::class);

        foreach ([NetSalesChart::class, IncomeChart::class, TopItemsChart::class, PaymentMethodsChart::class, StockValueChart::class] as $chart) {
            $page->assertDontSeeLivewire($chart);
        }
    }

    public function test_the_chart_options_fit_inside_the_html_attribute_they_are_written_into(): void
    {
        $this->actingAs($this->owner);

        foreach ([NetSalesChart::class, IncomeChart::class, TopItemsChart::class, PaymentMethodsChart::class, StockValueChart::class] as $chart) {
            $options = (string) (fn (): mixed => $this->getOptions())->call(Livewire::test($chart)->instance());

            // A double quote would end Filament's x-data="..." and print the script as text.
            $this->assertStringNotContainsString('"', $options, $chart);
            $this->assertStringContainsString("'LKR' + ' '", $options, $chart);
        }
    }

    public function test_cashiers_see_no_chart_and_stock_value_needs_cost_view(): void
    {
        $charts = [NetSalesChart::class, IncomeChart::class, TopItemsChart::class, PaymentMethodsChart::class, StockValueChart::class];
        $this->actingAs($this->cashier);

        foreach ($charts as $chart) {
            $this->assertFalse($chart::canView(), $chart);
            Livewire::test($chart)->assertForbidden();
        }

        $viewer = User::factory()->create(['shop_id' => $this->shop->getKey()]);
        $viewer->givePermissionTo(['admin.access', 'reports.view']);
        $this->actingAs($viewer);

        foreach ([NetSalesChart::class, IncomeChart::class, TopItemsChart::class, PaymentMethodsChart::class] as $chart) {
            $this->assertTrue($chart::canView(), $chart);
        }

        $this->assertFalse(StockValueChart::canView());
        Livewire::test(StockValueChart::class)->assertForbidden();
    }
}
