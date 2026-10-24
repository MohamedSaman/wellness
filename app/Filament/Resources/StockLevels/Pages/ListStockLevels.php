<?php

namespace App\Filament\Resources\StockLevels\Pages;

use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Resources\StockLevels\Widgets\StockOverview;
use Filament\Resources\Pages\ListRecords;

class ListStockLevels extends ListRecords
{
    protected static string $resource = StockLevelResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            StockOverview::class,
        ];
    }
}
