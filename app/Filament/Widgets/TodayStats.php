<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Reports\ProfitReport;
use App\Filament\Support\ShopMoney;
use App\Filament\Widgets\Concerns\NeedsReportsPermission;
use App\Models\User;
use App\Services\ReportService;
use App\Support\CurrentShop;
use App\Support\ReportPeriod;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\HtmlString;

/**
 * Today's sales in the shop's own day, refreshed every minute. Profit only
 * with reports.profit, explained under the figure: revenue less the lines'
 * cost and the margin (the cost with cost.view), red when below zero, and in
 * a clinic the OPD bills' medicines when their typed amounts were below
 * cost (what usually makes a day's profit negative). Opens the Profit report.
 */
class TodayStats extends StatsOverviewWidget
{
    use NeedsReportsPermission;

    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '60s';

    protected function getColumns(): int|array
    {
        // With profit, rows of three: its tile carries a few lines of explanation.
        return ['default' => 1, 'sm' => 2, 'lg' => 3, 'xl' => $this->canSeeProfit() ? 3 : 4];
    }

    protected function getStats(): array
    {
        $reports = app(ReportService::class);
        $today = ReportPeriod::today(app(CurrentShop::class)->getOrFail());
        $summary = $reports->summary($today);

        $stats = [
            Stat::make('Sales today', ShopMoney::format($summary['net']))
                ->description($summary['refunds'] > 0 ? 'After '.ShopMoney::format($summary['refunds']).' refunds' : 'Net of refunds')
                ->descriptionIcon(Heroicon::OutlinedBanknotes),
            Stat::make('Number of sales', number_format($summary['sales_count'])),
            Stat::make('Average sale', ShopMoney::format($summary['average'])),
            Stat::make('Cash expected in open shifts', ShopMoney::format($reports->cashInOpenShifts()))
                ->descriptionIcon(Heroicon::OutlinedInboxStack),
        ];

        // Only calculated for users allowed to see it, so cost data never reaches anyone else.
        if ($this->canSeeProfit()) {
            $stats[] = $this->grossProfit($reports->profit($today));
        }

        return $stats;
    }

    /** @param  array{total: array{revenue: int, cost: int, profit: int, margin: float|null}, opd_medicines: array{revenue: int, cost: int, profit: int}}  $profit */
    private function grossProfit(array $profit): Stat
    {
        $total = $profit['total'];
        $opd = $profit['opd_medicines'];
        $user = auth()->user();
        $canSeeCost = $user instanceof User && $user->can('cost.view');
        $lines = [$canSeeCost
            ? 'Revenue '.ShopMoney::format($total['revenue']).' − cost '.ShopMoney::format($total['cost'])
            : 'Revenue '.ShopMoney::format($total['revenue'])];

        if ($total['margin'] !== null) {
            $lines[] = 'Margin '.number_format($total['margin'], 1).'%';
        }

        if ($canSeeCost && $opd['profit'] < 0) {
            $lines[] = 'OPD medicines charged '.ShopMoney::format($opd['revenue']).', cost '.ShopMoney::format($opd['cost']);
        }

        return Stat::make('Gross profit today', ShopMoney::format($total['profit']))
            ->description(new HtmlString(implode('<br>', array_map(fn (string $line): string => e($line), $lines))))
            ->descriptionIcon($total['profit'] < 0 ? Heroicon::OutlinedArrowTrendingDown : Heroicon::OutlinedArrowTrendingUp)
            ->color($total['profit'] < 0 ? 'danger' : 'success')
            ->url(ProfitReport::getUrl(['range' => 'today']));
    }

    private function canSeeProfit(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('reports.profit');
    }
}
