<?php

namespace App\Filament\Widgets\Clinic;

use App\Filament\Pages\Reports\ExpiryReport;
use App\Filament\Resources\ExpiringStock\ExpiringStockResource;
use App\Filament\Widgets\Concerns\ClinicWidget;
use App\Http\Controllers\DashboardController;
use App\Models\User;
use App\Services\ClinicDashboardService;
use Filament\Widgets\Widget;
use Illuminate\Support\Str;

/**
 * The clinic dashboard's greeting: good morning, afternoon or evening by the
 * shop's clock, a line of today's figures (sales and OPD bills with
 * reports.view; stock running low with stock.view), and the way to the till
 * and to the stock expiring soon.
 */
class GreetingHero extends Widget
{
    use ClinicWidget;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.widgets.clinic.greeting-hero';

    protected int|string|array $columnSpan = ['default' => 1, 'xl' => 8];

    public static function canView(): bool
    {
        return self::clinicUserCan('admin.access');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $today = $this->figures()->today();
        $summary = [];

        if (self::userCan('reports.view')) {
            $summary[] = $today['sales_count'].' '.Str::plural('sale', $today['sales_count']).' today';
            $summary[] = $today['opd_bills'].' OPD '.Str::plural('bill', $today['opd_bills']);
        }

        if (self::userCan('stock.view') || self::userCan('reports.view')) {
            $summary[] = $today['low_stock'].' '.Str::plural('item', $today['low_stock']).' low on stock';
        }

        return [
            'greeting' => DashboardController::greeting($user),
            'firstName' => (string) strtok(trim($user->name), ' '),
            'summary' => implode(' · ', $summary),
            'posUrl' => $user->can('pos.checkout') ? route('pos.index') : null,
            'expiringUrl' => match (true) {
                ExpiryReport::canAccess() => ExpiryReport::getUrl(['list' => 'soon', 'days' => (string) ClinicDashboardService::EXPIRY_DAYS]),
                ExpiringStockResource::canAccess() => ExpiringStockResource::getUrl('index', ['tab' => 'soon']),
                default => null,
            },
        ];
    }
}
