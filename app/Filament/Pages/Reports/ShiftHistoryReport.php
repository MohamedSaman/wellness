<?php

namespace App\Filament\Pages\Reports;

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
        ];
    }

    protected function reportRows(): iterable
    {
        return $this->reports()->shiftHistory($this->period(), $this->locationId());
    }
}
