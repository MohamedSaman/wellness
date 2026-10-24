<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Filament\Resources\Sales\SaleResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListSales extends ListRecords
{
    protected static string $resource = SaleResource::class;

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All sales'),
            'needs_review' => Tab::make('Needs review')
                ->badge(fn (): ?string => SaleResource::getNavigationBadge())
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->scopes(['awaitingReview'])),
            'offline' => Tab::make('Made offline')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('synced_at')),
        ];
    }
}
