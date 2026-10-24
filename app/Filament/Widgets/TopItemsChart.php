<?php

namespace App\Filament\Widgets;

use App\Filament\Support\ChartColors;
use App\Filament\Widgets\Concerns\DashboardChart;
use App\Services\ReportService;
use App\Support\ReportPeriod;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/**
 * The 10 items with the highest net sales (revenue, net of returns), goods
 * only: medicines in a clinic, never the consultation
 * (ReportService::topItemsByRevenue(), as Monthly sales adds them up).
 */
class TopItemsChart extends ChartWidget
{
    use DashboardChart;

    protected static ?int $sort = 4;

    protected function defaultPeriod(): string
    {
        return 'this_month';
    }

    public function getHeading(): string
    {
        return $this->shop()->hasModule('pharmacy') ? 'Top 10 medicines by sales' : 'Top 10 items by sales';
    }

    protected function getData(): array
    {
        return $this->remember(function (ReportPeriod $period): array {
            $rows = app(ReportService::class)->topItemsByRevenue($period, 10);

            return [
                'datasets' => [[
                    'label' => 'Net sales',
                    'backgroundColor' => ChartColors::GOODS,
                    'data' => array_map(fn (array $row): float => self::units($row['net']), $rows),
                ]],
                // Long names would squeeze the bars: the axis shows the first 28 characters.
                'labels' => array_map(fn (array $row): string => mb_strimwidth($row['item'], 0, 28, '…'), $rows),
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
