<?php

namespace App\Filament\Widgets\Clinic;

use App\Filament\Support\ShopMoney;
use App\Filament\Widgets\Concerns\ClinicWidget;
use App\Filament\Widgets\Concerns\DashboardChart;
use App\Services\BrandingService;
use App\Services\ThemeService;
use App\Support\ThemePresets;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/**
 * Sales trend, this week: net sales each day from Monday to Sunday (the
 * daily summary's figures), a smooth line over a soft fill of the theme's
 * primary colour, no grid. Days still to come have no point. No period
 * filter and no polling: the KPI cards above refresh today's figure.
 */
class SalesTrendChart extends ChartWidget
{
    use ClinicWidget;
    use DashboardChart;

    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Sales trend: this week';

    protected int|string|array $columnSpan = ['default' => 1, 'xl' => 5];

    public static function canView(): bool
    {
        return self::clinicUserCan('reports.view');
    }

    /** Beside the stock value ring; the whole width when the user may not see cost. */
    public function getColumnSpan(): int|string|array
    {
        return StockValueDonut::canView() ? $this->columnSpan : 'full';
    }

    protected function defaultPeriod(): string
    {
        return 'this_week';
    }

    protected function getFilters(): ?array
    {
        return null;
    }

    protected function getPollingInterval(): ?string
    {
        return null;
    }

    protected function getMaxHeight(): ?string
    {
        return '240px';
    }

    protected function getType(): string
    {
        return 'line';
    }

    public function getDescription(): ?string
    {
        $net = array_sum(array_map(fn (array $day): int => (int) $day['net'], $this->figures()->weekTrend()));

        return ShopMoney::format($net).' so far this week';
    }

    protected function getData(): array
    {
        $week = $this->figures()->weekTrend();

        return [
            'datasets' => [[
                'label' => 'Net sales',
                'data' => array_map(fn (array $day): ?float => $day['net'] === null ? null : self::units($day['net']), $week),
            ]],
            'labels' => array_column($week, 'label'),
        ];
    }

    /** The line's colour: a brand colour, else the theme's primary, else the panel's amber (classic). */
    public static function lineColor(): string
    {
        $brand = app(BrandingService::class)->palette();

        return $brand?->shades[600] ?? ThemePresets::tokens(app(ThemeService::class)->preset())['primary'] ?? '#d97706';
    }

    protected function getOptions(): RawJs
    {
        // Inside an HTML attribute (x-data="..."): JS strings in single quotes only.
        $currency = "'".preg_replace('/[^A-Za-z]/', '', ShopMoney::currency())."'";
        $decimals = ShopMoney::decimals();
        [$red, $green, $blue] = sscanf(self::lineColor(), '#%02x%02x%02x');
        $line = "'rgb({$red}, {$green}, {$blue})'";
        $top = "'rgba({$red}, {$green}, {$blue}, 0.28)'";
        $bottom = "'rgba({$red}, {$green}, {$blue}, 0)'";

        return RawJs::make(<<<JS
            {
                datasets: {
                    line: {
                        fill: true,
                        tension: 0.4,
                        borderWidth: 2,
                        borderColor: {$line},
                        pointBackgroundColor: {$line},
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        spanGaps: false,
                        backgroundColor: (context) => {
                            const area = context.chart.chartArea;

                            if (! area) {
                                return {$top};
                            }

                            const gradient = context.chart.ctx.createLinearGradient(0, area.top, 0, area.bottom);
                            gradient.addColorStop(0, {$top});
                            gradient.addColorStop(1, {$bottom});

                            return gradient;
                        },
                    },
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (context) => {$currency} + ' '
                                + new Intl.NumberFormat('en-US', { minimumFractionDigits: {$decimals}, maximumFractionDigits: {$decimals} }).format(context.parsed.y),
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false } },
                    y: {
                        beginAtZero: true,
                        grid: { display: false },
                        ticks: { maxTicksLimit: 4, callback: (value) => new Intl.NumberFormat('en-US', { notation: 'compact' }).format(value) },
                    },
                },
            }
        JS);
    }
}
