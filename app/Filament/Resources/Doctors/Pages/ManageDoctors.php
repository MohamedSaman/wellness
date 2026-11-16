<?php

namespace App\Filament\Resources\Doctors\Pages;

use App\Filament\Resources\Doctors\DoctorResource;
use App\Models\Doctor;
use App\Models\User;
use App\Services\DoctorService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageDoctors extends ManageRecords
{
    protected static string $resource = DoctorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add doctor')
                ->using(function (array $data, Action $action): Doctor {
                    $user = auth()->user();
                    abort_unless($user instanceof User, 403);

                    return DoctorResource::save($action, fn (): Doctor => app(DoctorService::class)->create($data, $user));
                }),
        ];
    }
}
