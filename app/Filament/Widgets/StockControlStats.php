<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ExpiringStock\ExpiringStockResource;
use App\Filament\Resources\StockRequests\StockRequestResource;
use App\Filament\Support\ShopMoney;
use App\Models\User;
use App\Services\ExpiryService;
use App\Services\StockRequestService;
use App\Support\CurrentShop;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Stock control at a glance (docs/PHARMACY.md §9, branch 10): batches
 * expired and expiring within the shop's warning window, with their value at
 * batch cost (cost.view), and stock requests waiting to be sent. Each opens
 * its list. Pharmacy shops see the expiry, multi-location shops the
 * requests; a retail shop sees nothing new.
 */
class StockControlStats extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    private static function showsExpiry(): bool
    {
        return (app(CurrentShop::class)->get()?->hasModule('pharmacy') ?? false) && (self::user()?->can('stock.view') ?? false);
    }

    private static function showsRequests(): bool
    {
        return (app(CurrentShop::class)->get()?->hasModule('multi_location') ?? false) && (self::user()?->canAny(['stock.transfer', 'stock.request']) ?? false);
    }

    public static function canView(): bool
    {
        return self::showsExpiry() || self::showsRequests();
    }

    protected function getColumns(): int|array
    {
        return ['default' => 1, 'sm' => 2, 'lg' => 3];
    }

    protected function getStats(): array
    {
        $stats = [];
        $canSeeCost = self::user()?->can('cost.view') ?? false;

        if (self::showsExpiry()) {
            $summary = app(ExpiryService::class)->summary();
            $describe = fn (array $list): string => $list['batches'].' '.str('batch')->plural($list['batches'])
                .($list['locations'] === [] ? '' : ': '.implode(', ', array_map(fn (array $row): string => "{$row['location']} {$row['batches']}", $list['locations'])));

            $stats[] = Stat::make('Expired stock', $canSeeCost ? ShopMoney::format($summary['expired']['value']) : (string) $summary['expired']['batches'])
                ->description($summary['expired']['batches'] > 0 ? $describe($summary['expired']).' to write off or return' : 'Nothing expired on the shelves')
                ->descriptionIcon($summary['expired']['batches'] > 0 ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedCheckCircle)
                ->color($summary['expired']['batches'] > 0 ? 'danger' : 'success')
                ->url(ExpiringStockResource::getUrl('index', ['tab' => ExpiryService::EXPIRED]));

            $stats[] = Stat::make("Expiring within {$summary['days']} days", $canSeeCost ? ShopMoney::format($summary['soon']['value']) : (string) $summary['soon']['batches'])
                ->description($summary['soon']['batches'] > 0 ? $describe($summary['soon']) : 'Nothing close to expiry')
                ->descriptionIcon(Heroicon::OutlinedClock)
                ->color($summary['soon']['batches'] > 0 ? 'warning' : null)
                ->url(ExpiringStockResource::getUrl('index', ['tab' => ExpiryService::SOON]));
        }

        if (self::showsRequests()) {
            $open = app(StockRequestService::class)->openCount(self::user());

            $stats[] = Stat::make('Stock requests waiting', (string) $open)
                ->description($open > 0 ? 'Asked for and not yet sent in full' : 'None waiting')
                ->descriptionIcon(Heroicon::OutlinedInboxArrowDown)
                ->color($open > 0 ? 'warning' : null)
                ->url(StockRequestResource::getUrl('index'));
        }

        return $stats;
    }
}
