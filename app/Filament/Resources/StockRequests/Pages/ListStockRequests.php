<?php

namespace App\Filament\Resources\StockRequests\Pages;

use App\Filament\Resources\StockRequests\StockRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStockRequests extends ListRecords
{
    protected static string $resource = StockRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New request'),
        ];
    }
}
