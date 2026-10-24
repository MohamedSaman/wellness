<?php

namespace App\Filament\Resources\ExpiringStock\Pages;

use App\Filament\Resources\ExpiringStock\ExpiringStockResource;
use App\Filament\Resources\StockWriteOffs\StockWriteOffResource;
use App\Filament\Support\ShopMoney;
use App\Services\ExpiryService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

/** Two lists: expiring within the shop's warning window, and expired (?tab=soon|expired). */
class ListExpiringStock extends ListRecords
{
    protected static string $resource = ExpiringStockResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'Expiry';
    }

    public function getSubheading(): ?string
    {
        $expiry = app(ExpiryService::class);
        $summary = $expiry->summary();
        $canSeeCost = auth()->user()?->can('cost.view') ?? false;
        $describe = fn (array $list): string => $list['batches'].' '.str('batch')->plural($list['batches'])
            .($canSeeCost ? ', '.ShopMoney::format($list['value']).' at cost' : '');

        return sprintf(
            'Expired: %s. Expiring within %d days (by %s): %s. A batch sells up to and including its expiry day.',
            $describe($summary['expired']),
            $summary['days'],
            $expiry->soonUntil()->format('d/m/Y'),
            $describe($summary['soon']),
        );
    }

    public function getTabs(): array
    {
        $expiry = app(ExpiryService::class);
        $counts = ExpiringStockResource::counts();

        return [
            ExpiryService::SOON => Tab::make('Expiring within '.$expiry->warningDays().' days')
                ->badge($counts[ExpiryService::SOON] ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query): Builder => $expiry->onList($query, ExpiryService::SOON)),
            ExpiryService::EXPIRED => Tab::make('Expired')
                ->badge($counts[ExpiryService::EXPIRED] ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query): Builder => $expiry->onList($query, ExpiryService::EXPIRED)),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return ExpiryService::SOON;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('writeOffs')
                ->label('Write-off notes')
                ->icon(Heroicon::OutlinedDocumentText)
                ->color('gray')
                ->url(StockWriteOffResource::getUrl('index'))
                ->visible(StockWriteOffResource::canAccess()),
        ];
    }
}
