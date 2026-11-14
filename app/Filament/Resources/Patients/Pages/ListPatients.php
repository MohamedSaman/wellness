<?php

namespace App\Filament\Resources\Patients\Pages;

use App\Filament\Pages\ShiftPatients;
use App\Filament\Resources\Patients\PatientResource;
use App\Models\Shift;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

/**
 * The clinic's patients with their filters (docs/PHARMACY.md, branch 16).
 * With one shift chosen, "Patients in this shift" opens that shift's bills.
 */
class ListPatients extends ListRecords
{
    protected static string $resource = PatientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('shiftPatients')
                ->label('Patients in this shift')
                ->icon(Heroicon::OutlinedClock)
                ->color('gray')
                ->visible(fn (): bool => is_int($this->chosenShift()) && ShiftPatients::canAccess())
                ->url(fn (): ?string => is_int($id = $this->chosenShift()) && ($shift = Shift::query()->find($id)) !== null ? ShiftPatients::getUrl(['shift' => $shift]) : null),
        ];
    }

    /** The one shift the filter names, if it names one (not "open shifts now"). */
    private function chosenShift(): int|string|null
    {
        return PatientResource::filtersOf($this)->shift;
    }
}
