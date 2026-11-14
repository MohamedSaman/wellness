<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Pages\ShiftPatients;
use App\Services\PatientListService;
use Filament\Tables\Columns\TextColumn;

/**
 * Every shift opened in the period. A pharmacy shop also sees how many
 * patients each shift billed, linked to the shift's patients for whoever
 * may see patients (docs/PHARMACY.md, branch 16).
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
        ];
    }

    protected function reportRows(): iterable
    {
        $rows = $this->reports()->shiftHistory($this->period(), $this->locationId());

        if (! $this->hasPharmacy()) {
            return $rows;
        }

        $patients = app(PatientListService::class)->patientsPerShift(array_map(fn (array $row): int => (int) $row['shift_id'], $rows));

        return array_map(fn (array $row): array => [...$row, 'patients' => $patients[$row['shift_id']] ?? 0], $rows);
    }

    protected function configureColumn(ReportColumn $column, TextColumn $text): TextColumn
    {
        if ($column->key !== 'patients' || ! ShiftPatients::canAccess()) {
            return $text;
        }

        return $text
            ->url(fn (array $record): ?string => isset($record['shift_uuid']) ? ShiftPatients::getUrl(['shift' => $record['shift_uuid']]) : null)
            ->color('primary')
            ->tooltip('Patients in this shift');
    }
}
