<?php

namespace App\Filament\Resources\SupplierReturns\Pages;

use App\Filament\Resources\SupplierReturns\SupplierReturnResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSupplierReturns extends ListRecords
{
    protected static string $resource = SupplierReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Return to supplier'),
        ];
    }
}
