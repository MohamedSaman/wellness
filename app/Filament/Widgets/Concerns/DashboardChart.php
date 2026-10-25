<?php

namespace App\Filament\Widgets\Concerns;

use App\Filament\Support\ShopMoney;
use App\Models\Shop;
use App\Support\CurrentShop;
use App\Support\ReportPeriod;
use Filament\Support\RawJs;
use Illuminate\Support\Facades\Cache;

/**
 * What the dashboard's charts share: reports.view to see them; a period
 * from the reports' presets (and the last 7, 30 or 90 days); money in
 * whole currency units with the shop's currency in tooltips and axes; a
 * refresh every minute, and figures cached for a minute per shop, chart and
 * period, so polling and several owners don't re-run the aggregates.
 */
trait DashboardChart
{
    use NeedsReportsPermission;

    /** How long a chart's figures are reused, in seconds. */
    public const CACHE_SECONDS = 60;

    /** The filter's default period (a key of periodOptions()). */
    abstract protected function defaultPeriod(): string;

    /** @return array<int|string, string> the last 7, 30 and 90 days (int keys: PHP makes them so), then the presets */
    protected function periodOptions(): array
    {
        return [
            '7' => 'Last 7 days',
            '30' => 'Last 30 days',
            '90' => 'Last 90 days',
            ...array_diff_key(ReportPeriod::PRESETS, ['custom' => true]),
        ];
    }

    /*
     * Polling and height are methods, not properties: a trait property must
     * match any redeclaration exactly, so a chart that set its own
     * $pollingInterval or $maxHeight would be a fatal error. A chart
     * overrides these methods instead.
     */
    protected function getPollingInterval(): ?string
    {
        return '60s';
    }

    protected function getMaxHeight(): ?string
    {
        return '300px';
    }

    protected function getFilters(): ?array
    {
        return $this->periodOptions();
    }

    public function mount(): void
    {
        $this->filter ??= $this->defaultPeriod();

        parent::mount();
    }

    /** The filter is user-controllable, so only the offered values are used; anything else is the default. */
    public function period(): ReportPeriod
    {
        $shop = $this->shop();
        $key = array_key_exists((string) $this->filter, $this->periodOptions()) ? (string) $this->filter : $this->defaultPeriod();

        return match ($key) {
            '7', '30', '90' => ReportPeriod::lastDays($shop, (int) $key),
            default => ReportPeriod::preset($shop, $key) ?? ReportPeriod::lastDays($shop, 30),
        };
    }

    protected function shop(): Shop
    {
        return app(CurrentShop::class)->getOrFail();
    }

    /**
     * The chart's figures for its period, kept for a minute.
     *
     * @template T
     *
     * @param  \Closure(ReportPeriod): T  $compute
     * @return T
     */
    protected function remember(\Closure $compute): mixed
    {
        $period = $this->period();
        $key = implode(':', ['dashboard-chart', $this->shop()->getKey(), static::class, $period->fromDate(), $period->toDate()]);

        return Cache::remember($key, self::CACHE_SECONDS, fn (): mixed => $compute($period));
    }

    /** Minor units to whole currency units: the chart only draws them. */
    protected static function units(int $minor): float
    {
        return round($minor / 10 ** ShopMoney::decimals(), ShopMoney::decimals());
    }

    /**
     * Chart.js options for money: tooltips "GEN: LKR 1,250.50", the value
     * axis in short numbers (12K), thin bars with rounded ends, stacked or
     * not; horizontal bars put the value on the x axis.
     */
    protected function moneyOptions(bool $stacked = false, bool $horizontal = false, bool $legend = true): RawJs
    {
        // The options go inside an HTML attribute (x-data="..."): JS strings in single quotes only.
        // The currency is three letters (Shop settings), so it needs no escaping.
        $currency = "'".preg_replace('/[^A-Za-z]/', '', ShopMoney::currency())."'";
        $decimals = ShopMoney::decimals();
        $value = $horizontal ? 'x' : 'y';
        $category = $horizontal ? 'y' : 'x';
        $stacked = $stacked ? 'true' : 'false';
        $legend = $legend ? 'true' : 'false';
        $indexAxis = "'{$category}'";

        return RawJs::make(<<<JS
            {
                indexAxis: {$indexAxis},
                borderRadius: 4,
                borderWidth: 0,
                datasets: { bar: { maxBarThickness: 32, borderSkipped: 'start' } },
                plugins: {
                    legend: { display: {$legend} },
                    tooltip: {
                        callbacks: {
                            label: (context) => (context.dataset.label ? context.dataset.label + ': ' : '') + {$currency} + ' '
                                + new Intl.NumberFormat('en-US', { minimumFractionDigits: {$decimals}, maximumFractionDigits: {$decimals} }).format(context.parsed.{$value}),
                        },
                    },
                },
                scales: {
                    {$category}: { stacked: {$stacked}, grid: { display: false } },
                    {$value}: {
                        stacked: {$stacked},
                        beginAtZero: true,
                        grid: { display: true },
                        ticks: { callback: (value) => new Intl.NumberFormat('en-US', { notation: 'compact' }).format(value) },
                    },
                },
            }
        JS);
    }
}
