<?php

namespace App\Filament\Widgets;

use App\Filament\Support\ChartColors;
use App\Filament\Widgets\Concerns\DashboardChart;
use App\Services\ReportService;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/**
 * Net sales (sales minus refunds) per shop day; with multi_location, one
 * stacked series per counter (the bills rung up there: the daily summary's
 * figures), each counter in its own colour on every chart.
 */
class NetSalesChart extends ChartWidget
{
    use DashboardChart;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected function defaultPeriod(): string
    {
        return '30';
    }

    public function getHeading(): string
    {
        return $this->shop()->hasModule('multi_location') ? 'Net sales by counter' : 'Net sales';
    }

    protected function getData(): array
    {
        return $this->remember(function (ReportPeriod $period): array {
            $reports = app(ReportService::class);
            $colors = ChartColors::locations();
            $series = $this->shop()->hasModule('multi_location')
                ? array_map(fn (array $counter): array => [$counter['location'], $colors[$counter['location_id']] ?? ChartColors::NEUTRAL, $reports->salesByDay($period, $counter['location_id'])], $reports->counters($period))
                : [['Net sales', $colors[(int) $this->shop()->defaultLocation()->getKey()] ?? ChartColors::COUNTERS[0], $reports->salesByDay($period)]];

            return [
                'datasets' => array_map(fn (array $one): array => [
                    'label' => $one[0],
                    'backgroundColor' => $one[1],
                    'data' => array_map(fn (array $day): float => self::units($day['net']), $one[2]),
                ], $series),
                'labels' => array_map(fn (string $day): string => CarbonImmutable::parse($day)->format('j M'), $period->days()),
            ];
        });
    }

    protected function getOptions(): RawJs
    {
        return $this->moneyOptions(stacked: true, legend: $this->shop()->hasModule('multi_location'));
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
