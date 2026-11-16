<?php

namespace App\Filament\Resources\Doctors\Pages;

use App\Filament\Resources\Doctors\DoctorResource;
use App\Filament\Resources\Doctors\Widgets\DoctorPaymentStats;
use App\Models\Doctor;
use App\Models\User;
use App\Services\DoctorService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * A doctor's page (docs/PHARMACY.md, branch 18): their details and every
 * payment they were made, with the period's and all-time totals.
 */
class ViewDoctor extends ViewRecord
{
    protected static string $resource = DoctorResource::class;

    protected function getHeaderWidgets(): array
    {
        return [DoctorPaymentStats::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->schema(fn ($schema) => DoctorResource::form($schema))
                ->using(function (Doctor $record, array $data, Action $action): Doctor {
                    $user = auth()->user();
                    abort_unless($user instanceof User, 403);

                    return DoctorResource::save($action, fn (): Doctor => app(DoctorService::class)->update($record, $data, $user));
                }),
        ];
    }
}
