<?php

namespace App\Filament\Widgets;

use App\Filament\Support\ChartColors;
use App\Filament\Support\ShopMoney;
use App\Filament\Widgets\Concerns\DashboardChart;
use App\Models\Location;
use App\Models\User;
use App\Services\ReportService;
use App\Support\CurrentShop;
use App\Support\ReportPeriod;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/**
 * Stock on hand at cost, now (Stock valuation's figures: each location's
 * weighted average, nothing below zero): by location in its counter's
 * colour with multi_location, else by category. Needs cost.view too.
 */
class StockValueChart extends ChartWidget
{
    use DashboardChart {
        canView as canSeeReports;
    }

    protected static ?int $sort = 6;

    public static function canView(): bool
    {
        $user = auth()->user();

        return self::canSeeReports() && $user instanceof User && $user->can('cost.view');
    }

    /** Stock value is now, not over a period: no filter, refigured each day (and every minute). */
    protected function getFilters(): ?array
    {
        return null;
    }

    protected function defaultPeriod(): string
    {
        return 'today';
    }

    private function byLocation(): bool
    {
        return app(CurrentShop::class)->getOrFail()->hasModule('multi_location');
    }

    public function getHeading(): string
    {
        return $this->byLocation() ? 'Stock value by location' : 'Stock value by category';
    }

    /** @return list<array{name: string, value: int, location_id: int|null}> */
    private function figures(): array
    {
        return $this->remember(function (ReportPeriod $period): array {
            $byLocation = $this->byLocation();
            $ids = $byLocation ? Location::query()->pluck('id', 'name') : collect();

            return array_map(fn (array $row): array => [
                'name' => $byLocation ? ($row['location'] ?? '') : ($row['category'] ?? ''),
                'value' => $row['cost_value'] ?? 0,
                'location_id' => $byLocation && isset($ids[$row['location'] ?? '']) ? (int) $ids[$row['location'] ?? ''] : null,
            ], app(ReportService::class)->stockValuation(true, null, $byLocation ? 'location' : 'category'));
        });
    }

    public function getDescription(): string
    {
        return 'At cost: '.ShopMoney::format((int) collect($this->figures())->sum('value'));
    }

    protected function getData(): array
    {
        $rows = $this->figures();
        $colors = ChartColors::locations();

        return [
            'datasets' => [[
                'label' => 'At cost',
                'backgroundColor' => array_map(fn (array $row): string => $row['location_id'] === null ? ChartColors::NEUTRAL : ($colors[$row['location_id']] ?? ChartColors::NEUTRAL), $rows),
                'data' => array_map(fn (array $row): float => self::units($row['value']), $rows),
            ]],
            'labels' => array_map(fn (array $row): string => mb_strimwidth($row['name'], 0, 28, '…'), $rows),
        ];
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
