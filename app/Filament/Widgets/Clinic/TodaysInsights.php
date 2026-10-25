<?php

namespace App\Filament\Widgets\Clinic;

use App\Filament\Pages\Reports\DailySummaryReport;
use App\Filament\Support\ShopMoney;
use App\Filament\Widgets\Concerns\ClinicWidget;
use App\Services\ClinicDashboardService;
use Filament\Widgets\Widget;
use Illuminate\Support\Str;

/**
 * Today's insights: a few plain rules over today's figures, most urgent
 * first (no guessing, nothing learned): expired batches still at a selling
 * location, batches expiring within 30 days (with their cost for cost.view),
 * stock below zero or low, today's sales against the same weekday last week,
 * stock requests waiting. At most five.
 */
class TodaysInsights extends Widget
{
    use ClinicWidget;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.widgets.clinic.todays-insights';

    protected int|string|array $columnSpan = ['default' => 1, 'xl' => 4];

    public static function canView(): bool
    {
        return self::clinicUserCan('reports.view');
    }

    /**
     * @return list<array{tone: string, text: string}> tone: danger, warning or primary
     */
    public function insights(): array
    {
        $today = $this->figures()->today();
        $insights = [];
        $batches = fn (int $count): string => $count.' '.Str::plural('batch', $count);
        $items = fn (int $count): string => $count.' '.Str::plural('item', $count);

        if ($today['expired'] > 0) {
            $insights[] = ['tone' => 'danger', 'text' => $batches($today['expired']).' past expiry still at a selling counter: move '.($today['expired'] === 1 ? 'it' : 'them').' to Quarantine.'];
        }

        if ($today['expiring'] > 0) {
            $value = self::userCan('cost.view') ? ' ('.ShopMoney::format($this->figures()->expiringValue()).' at cost)' : '';
            $insights[] = ['tone' => 'warning', 'text' => $batches($today['expiring']).' '.($today['expiring'] === 1 ? 'expires' : 'expire').' within '.ClinicDashboardService::EXPIRY_DAYS.' days'.$value.'.'];
        }

        if ($today['negative_stock'] > 0) {
            $insights[] = ['tone' => 'danger', 'text' => $items($today['negative_stock']).' sold below zero: receive or count them.'];
        }

        if ($today['low_stock'] > 0) {
            $insights[] = ['tone' => 'warning', 'text' => $items($today['low_stock']).' at or below the reorder level.'];
        }

        $change = ClinicDashboardService::change($today['sales'], $today['last_week']);
        $insights[] = match (true) {
            $change !== null => ['tone' => 'primary', 'text' => 'Sales are '.number_format(abs($change), 1).'% '.($change >= 0 ? 'up on' : 'down on').' last '.$today['last_week_day'].' so far.'],
            $today['sales'] > 0 => ['tone' => 'primary', 'text' => ShopMoney::format($today['sales']).' sold today; nothing was sold last '.$today['last_week_day'].'.'],
            default => ['tone' => 'primary', 'text' => 'No sales yet today.'],
        };

        if ($today['requests'] > 0) {
            $insights[] = ['tone' => 'primary', 'text' => $today['requests'].' stock '.Str::plural('request', $today['requests']).' from the counters waiting to be sent.'];
        }

        return array_slice($insights, 0, 5);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'insights' => $this->insights(),
            'reportsUrl' => DailySummaryReport::getUrl(['range' => 'today']),
        ];
    }
}
