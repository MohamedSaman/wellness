<?php

namespace App\Filament\Widgets\Clinic;

use App\Filament\Pages\Reports\DailySummaryReport;
use App\Filament\Pages\Reports\ExpiryReport;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Support\ShopMoney;
use App\Filament\Widgets\Concerns\ClinicWidget;
use App\Services\ClinicDashboardService;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The clinic dashboard's four headline figures, refreshed every minute:
 * today's sales against yesterday, today's OPD bills and their consultation
 * fees, items low on stock, and batches expiring within 30 days (red when
 * there are any). The only clinic widget that polls.
 */
class ClinicKpis extends StatsOverviewWidget
{
    use ClinicWidget;

    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return self::clinicUserCan('reports.view');
    }

    protected function getColumns(): int|array
    {
        return ['default' => 1, 'sm' => 2, '2xl' => 4];
    }

    protected function getStats(): array
    {
        $today = $this->figures()->today();
        $change = ClinicDashboardService::change($today['sales'], $today['yesterday']);

        $sales = Stat::make("Today's sales", ShopMoney::format($today['sales']))
            ->description(match (true) {
                $change !== null => number_format(abs($change), 1).'% '.($change >= 0 ? 'up on' : 'down on').' yesterday',
                $today['sales'] > 0 => 'Nothing sold yesterday',
                default => 'No sales yet today',
            })
            ->color($change !== null && $change >= 0 ? 'success' : 'gray')
            ->url(DailySummaryReport::getUrl(['range' => 'today']));

        if ($change !== null) {
            $sales->descriptionIcon($change >= 0 ? Heroicon::ArrowTrendingUp : Heroicon::ArrowTrendingDown);
        }

        $expiring = Stat::make('Expiring ≤ '.ClinicDashboardService::EXPIRY_DAYS.' days', number_format($today['expiring']))
            ->description($today['expired'] > 0 ? number_format($today['expired']).' already expired' : 'Batches at the selling counters')
            ->color($today['expiring'] > 0 || $today['expired'] > 0 ? 'danger' : 'gray')
            ->url(ExpiryReport::canAccess() ? ExpiryReport::getUrl(['list' => 'soon', 'days' => (string) ClinicDashboardService::EXPIRY_DAYS]) : null);

        if ($today['expiring'] > 0) {
            // The number itself in red (theme.css): an alert, the one place red is for.
            $expiring->extraAttributes(['class' => 'pos-kpi-alert']);
        }

        return [
            $sales,
            Stat::make('OPD bills today', number_format($today['opd_bills']))
                ->description('Consultation fees '.ShopMoney::format($today['consultation_fees']))
                ->color('gray'),
            Stat::make('Low-stock items', number_format($today['low_stock']))
                ->description($today['out_of_stock'] > 0 ? number_format($today['out_of_stock']).' out of stock' : 'At or below the reorder level')
                ->color($today['out_of_stock'] > 0 ? 'danger' : 'gray')
                ->url(StockLevelResource::getUrl('index', ['filters' => ['low_stock' => ['isActive' => true]]])),
            $expiring,
        ];
    }
}
