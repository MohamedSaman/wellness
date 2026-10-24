<?php

namespace App\Filament\Resources\Locations\Pages;

use App\Filament\Resources\Locations\LocationResource;
use App\Models\Location;
use App\Models\User;
use App\Services\LocationService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageLocations extends ManageRecords
{
    protected static string $resource = LocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add location')
                ->using(function (array $data, Action $action): Location {
                    $user = auth()->user();
                    abort_unless($user instanceof User, 403);

                    return LocationResource::save($action, fn (): Location => app(LocationService::class)->create($data, $user));
                }),
        ];
    }
}
