<?php

namespace App\Filament\Widgets\Clinic;

use App\Filament\Resources\ExpiringStock\ExpiringStockResource;
use App\Filament\Widgets\Concerns\ClinicWidget;
use Filament\Widgets\Widget;

/**
 * Stock alerts: batches expired or expiring within 30 days at a selling
 * counter and items out of stock, the urgent ones first, each marked High
 * or Medium. Opens the Expiry list.
 */
class StockAlerts extends Widget
{
    use ClinicWidget;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.widgets.clinic.stock-alerts';

    protected int|string|array $columnSpan = ['default' => 1, 'xl' => 4];

    public static function canView(): bool
    {
        return self::clinicUserCan('stock.view');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            ...$this->figures()->stockAlerts(),
            'allUrl' => ExpiringStockResource::canAccess() ? ExpiringStockResource::getUrl('index') : null,
        ];
    }
}
