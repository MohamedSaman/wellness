<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Pages\ShiftPatients;
use App\Models\Shift;
use App\Models\User;
use App\Services\PatientListService;
use App\Services\ReceiptService;
use App\Services\ShiftSummaryService;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Support\ArrayRecord;
use Filament\Tables\Columns\TextColumn;

/**
 * Every shift opened in the period. A pharmacy shop also sees how many
 * patients each shift billed, linked to the shift's patients for whoever
 * may see patients (docs/PHARMACY.md, branch 16), and each closed shift
 * has Print, to reprint its summary on the receipt printer or A4, logged
 * and marked REPRINT (branch 17), for its cashier or shifts.manage.
 */
class ShiftHistoryReport extends ReportPage
{
    protected static ?string $title = 'Shift history';

    protected static ?int $navigationSort = 7;

    protected static bool $hasLocationFilter = true;

    protected function reportColumns(): array
    {
        return [
            ReportColumn::text('till', 'Till'),
            ReportColumn::text('cashier', 'Cashier'),
            ReportColumn::text('opened_at', 'Opened'),
            ReportColumn::text('closed_at', 'Closed'),
            ReportColumn::money('opening_cash', 'Opening float', total: false),
            ReportColumn::money('expected_cash', 'Expected cash', total: false),
            ReportColumn::money('counted_cash', 'Counted cash', total: false),
            ReportColumn::money('difference', 'Difference'),
            ...($this->hasPharmacy() ? [ReportColumn::integer('patients', 'Patients', total: false)] : []),
            // Branch 18: what the shift paid doctors, to the Doctor payments report for that shift.
            ...($this->doctorPayments() ? [ReportColumn::money('doctor_payments', 'Doctor payments')] : []),
        ];
    }

    protected function reportRows(): iterable
    {
        // Each row is keyed by its shift, so Print reprints the shift clicked even if a new shift was opened since.
        $rows = array_map(
            fn (array $row): array => [...$row, ArrayRecord::getKeyName() => $row['shift_uuid']],
            $this->reports()->shiftHistory($this->period(), $this->locationId()),
        );

        if (! $this->hasPharmacy()) {
            return $rows;
        }

        $ids = array_map(fn (array $row): int => (int) $row['shift_id'], $rows);
        $patients = app(PatientListService::class)->patientsPerShift($ids);
        $doctors = $this->doctorPayments() ? $this->reports()->doctorPaymentsPerShift($ids) : null;

        return array_map(fn (array $row): array => [
            ...$row,
            'patients' => $patients[$row['shift_id']] ?? 0,
            ...($doctors === null ? [] : ['doctor_payments' => $doctors[$row['shift_id']] ?? 0]),
        ], $rows);
    }

    /** Doctor payments are recorded: pharmacy and cash movements (branch 18). */
    private function doctorPayments(): bool
    {
        return $this->hasPharmacy() && $this->hasCashMovements();
    }

    protected function configureColumn(ReportColumn $column, TextColumn $text): TextColumn
    {
        if ($column->key === 'doctor_payments' && DoctorPaymentsReport::canAccess()) {
            return $text
                ->url(fn (array $record): ?string => isset($record['shift_uuid']) && (int) ($record['doctor_payments'] ?? 0) > 0 ? DoctorPaymentsReport::getUrl(['shift' => $record['shift_uuid']]) : null)
                ->color('primary')
                ->tooltip('Doctor payments in this shift');
        }

        if ($column->key !== 'patients' || ! ShiftPatients::canAccess()) {
            return $text;
        }

        return $text
            ->url(fn (array $record): ?string => isset($record['shift_uuid']) ? ShiftPatients::getUrl(['shift' => $record['shift_uuid']]) : null)
            ->color('primary')
            ->tooltip('Patients in this shift');
    }

    /** @return list<Action> */
    protected function recordActions(): array
    {
        if (! $this->hasPharmacy()) {
            return [];
        }

        $mayPrint = function (array $record): bool {
            $user = auth()->user();

            return isset($record['shift_uuid']) && ($record['shift_closed'] ?? false) === true && $user instanceof User
                && ((int) ($record['cashier_id'] ?? 0) === (int) $user->getKey() || $user->can('shifts.manage'));
        };

        return [
            Action::make('printSummary')
                ->label('Print')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->modalHeading('Reprint the shift summary')
                ->modalDescription('The reprint is marked REPRINT, with when and by whom, and logged.')
                ->modalSubmitActionLabel('Print')
                ->visible($mayPrint)
                ->authorize($mayPrint)
                ->schema([
                    Radio::make('format')
                        ->label('Print on')
                        ->options(fn (): array => $this->printFormats())
                        ->default(fn (): string => array_key_first($this->printFormats()))
                        ->required(),
                ])
                ->action(function (array $record, array $data): void {
                    /** @var User $user */
                    $user = auth()->user();
                    $shift = Shift::query()->where('uuid', $record['shift_uuid'])->firstOrFail();
                    abort_unless(ShiftSummaryService::mayRead($user, $shift), 403);

                    $reprint = app(ShiftSummaryService::class)->recordReprint($shift, $user, null, (string) $data['format']);
                    $url = route('pos.shift.reprinted', ['shift' => $shift, 'reprint' => $reprint->getKey()]).'?print=1';
                    $window = $data['format'] === 'a4' ? 'width=900,height=1000' : 'width=420,height=720';

                    $this->js('window.open('.json_encode($url).", '_blank', ".json_encode($window).')');

                    Notification::make()
                        ->title('Shift summary sent to print')
                        ->actions([Action::make('open')->label('Open')->url($url, shouldOpenInNewTab: true)])
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * The formats this shop reprints in: the receipt printer (its paper
     * width from Receipt settings) when it prints thermal receipts, and A4.
     *
     * @return array<string, string>
     */
    private function printFormats(): array
    {
        $receipts = app(ReceiptService::class);
        $labels = [
            'thermal' => 'Receipt printer ('.$receipts->shop()['receipt']['paperWidth'].' mm)',
            'a4' => 'A4',
        ];

        return array_intersect_key($labels, array_flip(app(ShiftSummaryService::class)->formats()));
    }
}
