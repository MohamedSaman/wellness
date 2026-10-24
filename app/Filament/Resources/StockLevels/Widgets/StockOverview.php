<?php

namespace App\Filament\Resources\StockLevels\Widgets;

use App\Filament\Support\ShopMoney;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\ReportService;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Stock value (never counting stock below zero), low stock and negative stock counts. */
class StockOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $user = auth()->user();
        $reports = app(ReportService::class);
        $stats = [];

        if ($user instanceof User && $user->can('cost.view')) {
            $stats[] = Stat::make('Stock value (at cost)', ShopMoney::format($reports->stockValueAtCost()))
                ->description('Stock below zero counts as zero');
        }

        $stats[] = Stat::make('Items at or below reorder level', ProductVariant::query()->lowStock()->count());

        $negative = $reports->negativeStockCount();
        $stats[] = Stat::make('Items below zero', $negative)
            ->description($negative > 0 ? 'Do a stock count' : 'None')
            ->descriptionIcon($negative > 0 ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedCheckCircle)
            ->color($negative > 0 ? 'danger' : 'success');

        return $stats;
    }
}
