<?php

namespace App\Filament\Resources\Patients\Actions;

use App\Filament\Resources\Patients\Pages\ViewPatient;
use App\Filament\Resources\Patients\PatientResource;
use App\Models\Patient;
use App\Models\Sale;
use App\Observers\AuditObserver;
use App\Services\PatientListService;
use App\Services\PatientVisitsService;
use App\Support\PatientAge;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;

/**
 * A patient's bills within the list's filters, in a panel over the list
 * (docs/PHARMACY.md, branch 21): no page change, so the filters stay where
 * they are. Opened from a row of either tab (the patient, or the bill's
 * patient). Health data like the patient's page: opening it is written to
 * the audit log (patient_viewed, via the quick view).
 */
final class PatientQuickView
{
    public const NAME = 'quickView';

    public static function make(): Action
    {
        return Action::make(self::NAME)
            ->label('Quick view')
            ->icon(Heroicon::OutlinedEye)
            ->iconButton()
            ->color('gray')
            ->slideOver()
            ->modalWidth('2xl')
            ->modalHeading(fn (Model $record): string => self::patientOf($record)->name ?? 'Patient')
            ->modalDescription(fn (Model $record, HasTable $livewire): string => self::description($record, $livewire))
            ->mountUsing(function (Model $record): void {
                $patient = self::patientOf($record);
                abort_unless($patient instanceof Patient, 404);
                app(AuditObserver::class)->record(ViewPatient::VIEWED_EVENT, $patient, [], ['name' => $patient->name, 'via' => 'quick view'], auth()->id() === null ? null : (int) auth()->id());
            })
            ->modalContent(fn (Model $record, HasTable $livewire): View => self::content($record, $livewire))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->extraModalFooterActions(fn (Model $record): array => [
                Action::make('openPatient')
                    ->label('Open full page')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(PatientResource::getUrl('view', ['record' => self::patientOf($record)])),
            ]);
    }

    /** The row's patient: the patient, or the bill's. */
    public static function patientOf(Model $record): ?Patient
    {
        return match (true) {
            $record instanceof Patient => $record,
            $record instanceof Sale => $record->patient,
            default => null,
        };
    }

    /** "34 y · Today (18/10/2026) · OPD consultation", or "Every bill". */
    private static function description(Model $record, HasTable $livewire): string
    {
        $patient = self::patientOf($record);
        $filters = app(PatientListService::class)->describe(PatientResource::filtersOf($livewire));
        unset($filters['Visits at least']);

        return implode(' · ', array_filter([
            PatientAge::format($patient?->ageMonths()),
            $filters === [] ? 'Every bill' : 'Bills: '.implode(' · ', $filters),
        ]));
    }

    private static function content(Model $record, HasTable $livewire): View
    {
        $patient = self::patientOf($record);
        abort_unless($patient instanceof Patient, 404);
        $service = app(PatientVisitsService::class);
        $view = $service->forPatient($patient, PatientResource::filtersOf($livewire));

        return view('filament.patients.quick-view', [
            'bills' => $view['bills'],
            'more' => $view['more'],
            'service' => $service,
            'money' => auth()->user()?->can('reports.view') ?? false,
            'timezone' => PatientResource::timezone(),
        ]);
    }
}
