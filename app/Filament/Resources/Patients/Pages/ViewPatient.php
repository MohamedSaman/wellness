<?php

namespace App\Filament\Resources\Patients\Pages;

use App\Exceptions\PosException;
use App\Filament\Resources\Patients\PatientResource;
use App\Models\Patient;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Services\PatientService;
use App\Support\PatientAge;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * A patient and every bill they had, OPD and pharmacy, at every location.
 * Opening it is written to the audit log (health data); Edit patient
 * (patients.manage) goes through PatientService, audited too.
 */
class ViewPatient extends ViewRecord
{
    public const VIEWED_EVENT = 'patient_viewed';

    protected static string $resource = PatientResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $patient = $this->getRecord();
        abort_unless($patient instanceof Patient, 404);
        app(AuditObserver::class)->record(self::VIEWED_EVENT, $patient, [], ['name' => $patient->name], auth()->id() === null ? null : (int) auth()->id());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit')
                ->label('Edit patient')
                ->icon('heroicon-o-pencil-square')
                ->visible(fn (): bool => auth()->user()?->can('patients.manage') ?? false)
                ->fillForm(function (): array {
                    $patient = $this->getRecord();
                    abort_unless($patient instanceof Patient, 404);

                    return [
                        'name' => $patient->name,
                        'birth_date' => $patient->birth_date_estimated ? null : $patient->birth_date?->toDateString(),
                        'age' => $patient->birth_date_estimated ? str_replace(' ', '', (string) PatientAge::format($patient->ageMonths())) : null,
                        'notes' => $patient->notes,
                    ];
                })
                ->schema([
                    TextInput::make('name')->label('Name')->required()->maxLength(255),
                    DatePicker::make('birth_date')->label('Date of birth')->maxDate(now())->helperText('If known. Otherwise give the age.'),
                    TextInput::make('age')->label('Age today')->maxLength(5)->helperText('Years (34) or months for a baby (8m). Estimates the date of birth.'),
                    Textarea::make('notes')->label('Notes')->rows(3)->maxLength(2000)->helperText('Kept here only: never sent to the tills or printed.'),
                ])
                ->action(function (array $data): void {
                    $user = auth()->user();
                    $patient = $this->getRecord();
                    abort_unless($user instanceof User && $patient instanceof Patient, 403);

                    try {
                        app(PatientService::class)->update($patient, $user, [
                            'name' => (string) ($data['name'] ?? ''),
                            'birth_date' => filled($data['birth_date'] ?? null) ? (string) $data['birth_date'] : null,
                            'age' => filled($data['age'] ?? null) ? (string) $data['age'] : null,
                            'notes' => filled($data['notes'] ?? null) ? (string) $data['notes'] : null,
                        ]);
                    } catch (PosException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title('Patient saved')->success()->send();
                }),
        ];
    }
}
