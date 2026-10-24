<?php

namespace App\Filament\Resources\VehicleMakes\Pages;

use App\Filament\Resources\VehicleMakes\VehicleMakeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageVehicleMakes extends ManageRecords
{
    protected static string $resource = VehicleMakeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
