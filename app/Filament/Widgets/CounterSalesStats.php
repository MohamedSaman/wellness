<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Reports\DailySummaryReport;
use App\Filament\Pages\Reports\MonthlySalesReport;
use App\Filament\Support\ShopMoney;
use App\Filament\Widgets\Concerns\NeedsReportsPermission;
use App\Services\ReportService;
use App\Support\CurrentShop;
use App\Support\Quantity;
use App\Support\ReportPeriod;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The owner's view of the counters (docs/PHARMACY.md §9, branch 11): with
 * multi_location, today's net sales at each counter, and in a pharmacy
 * the consultations today; this month so far against the same days of
 * last month, and last month. Each opens its report. Refreshed every minute.
 */
class CounterSalesStats extends StatsOverviewWidget
{
    use NeedsReportsPermission;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '60s';

    protected function getColumns(): int|array
    {
        return ['default' => 1, 'sm' => 2, 'lg' => 4];
    }

    protected function getStats(): array
    {
        $shop = app(CurrentShop::class)->getOrFail();
        $reports = app(ReportService::class);
        $today = ReportPeriod::today($shop);
        $pharmacy = $shop->hasModule('pharmacy');
        $stats = [];

        if ($shop->hasModule('multi_location')) {
            foreach ($reports->counters($today) as $counter) {
                $stats[] = Stat::make($counter['location'].' today', ShopMoney::format($counter['net']))
                    ->description($counter['bills'].' '.str('bill')->plural($counter['bills'])
                        .($pharmacy && $counter['services'] !== 0 ? ' · consultations '.ShopMoney::format($counter['services']) : ''))
                    ->descriptionIcon(Heroicon::OutlinedBuildingStorefront)
                    ->url(DailySummaryReport::getUrl(['range' => 'today', 'location' => (string) $counter['location_id']]));
            }
        }

        if ($pharmacy) {
            $consultations = $reports->consultations($today);
            $stats[] = Stat::make('Consultations today', Quantity::format($consultations['consultations']))
                ->description(ShopMoney::format($consultations['net']).' in fees · '.$consultations['opd_bills'].' OPD '.str('bill')->plural($consultations['opd_bills']))
                ->descriptionIcon(Heroicon::OutlinedUserGroup)
                ->url(DailySummaryReport::getUrl(['range' => 'today']));
        }

        $month = $reports->monthComparison();
        $change = $month['change'];

        $stats[] = Stat::make('This month', ShopMoney::format($month['this_month']))
            ->description($change === null
                ? 'Nothing on the same days last month'
                : ($change >= 0 ? '+' : '').number_format($change, 1).'% on the same days last month ('.ShopMoney::format($month['last_month_to_date']).')')
            ->descriptionIcon($change !== null && $change < 0 ? Heroicon::OutlinedArrowTrendingDown : Heroicon::OutlinedArrowTrendingUp)
            ->color($change === null ? null : ($change < 0 ? 'danger' : 'success'))
            ->url(MonthlySalesReport::getUrl(['range' => 'this_month']));

        $stats[] = Stat::make('Last month', ShopMoney::format($month['last_month']))
            ->description('Net of refunds')
            ->url(MonthlySalesReport::getUrl(['range' => 'last_month']));

        return $stats;
    }
}
