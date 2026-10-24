<?php

namespace App\Filament\Widgets;

use App\Filament\Support\ChartColors;
use App\Filament\Support\ShopMoney;
use App\Filament\Widgets\Concerns\DashboardChart;
use App\Services\ReportService;
use App\Support\CurrentShop;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/**
 * A clinic's consultation fees against its medicines per day, net of
 * refunds, at both counters (ReportService::incomeByDay(): the daily
 * summary's split). Pharmacy only.
 */
class IncomeChart extends ChartWidget
{
    use DashboardChart {
        canView as canSeeReports;
    }

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Consultation fees and medicines';

    public static function canView(): bool
    {
        return self::canSeeReports() && (app(CurrentShop::class)->get()?->hasModule('pharmacy') ?? false);
    }

    protected function defaultPeriod(): string
    {
        return 'this_month';
    }

    /** @return array{days: list<array{date: string, services: int, goods: int}>} */
    private function figures(): array
    {
        return $this->remember(fn (ReportPeriod $period): array => ['days' => app(ReportService::class)->incomeByDay($period)]);
    }

    public function getDescription(): string
    {
        $days = collect($this->figures()['days']);

        return 'Consultation fees '.ShopMoney::format((int) $days->sum('services')).' · medicines '.ShopMoney::format((int) $days->sum('goods'));
    }

    protected function getData(): array
    {
        $days = $this->figures()['days'];

        return [
            'datasets' => [
                ['label' => 'Consultation fees', 'backgroundColor' => ChartColors::SERVICES, 'data' => array_map(fn (array $day): float => self::units($day['services']), $days)],
                ['label' => 'Medicines', 'backgroundColor' => ChartColors::GOODS, 'data' => array_map(fn (array $day): float => self::units($day['goods']), $days)],
            ],
            'labels' => array_map(fn (array $day): string => CarbonImmutable::parse($day['date'])->format('j M'), $days),
        ];
    }

    protected function getOptions(): RawJs
    {
        return $this->moneyOptions(stacked: true);
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
