<?php

namespace App\Filament\Widgets;

use App\Filament\Support\ChartColors;
use App\Filament\Widgets\Concerns\DashboardChart;
use App\Services\ReportService;
use App\Support\ReportPeriod;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/** Money taken per payment method, net of refunds (cash net of change): the Payment methods report's net. */
class PaymentMethodsChart extends ChartWidget
{
    use DashboardChart;

    protected static ?int $sort = 5;

    protected ?string $heading = 'Payment methods';

    protected function defaultPeriod(): string
    {
        return 'this_month';
    }

    protected function getData(): array
    {
        return $this->remember(function (ReportPeriod $period): array {
            $rows = app(ReportService::class)->paymentMethods($period);

            return [
                'datasets' => [[
                    'label' => 'Net',
                    'backgroundColor' => ChartColors::NEUTRAL,
                    'data' => array_map(fn (array $row): float => self::units($row['net']), $rows),
                ]],
                'labels' => array_map(fn (array $row): string => $row['method'], $rows),
            ];
        });
    }

    protected function getOptions(): RawJs
    {
        return $this->moneyOptions(horizontal: true, legend: false);
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
