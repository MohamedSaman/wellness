<?php

namespace App\Filament\Pages\Reports;

class VoidsAndReturnsReport extends ReportPage
{
    protected static ?string $title = 'Voids and returns';

    protected static ?int $navigationSort = 6;

    protected static bool $hasLocationFilter = true;

    protected function reportColumns(): array
    {
        return [
            ReportColumn::text('when', 'When'),
            ReportColumn::text('type', 'Type'),
            ReportColumn::text('reference', 'Invoice / return no.'),
            ReportColumn::money('amount', 'Amount', total: false),
            ReportColumn::text('reason', 'Reason'),
            ReportColumn::text('stock', 'Stock'),
            ReportColumn::text('by_user', 'By'),
        ];
    }

    protected function reportRows(): iterable
    {
        return $this->reports()->voidsAndReturns($this->period(), $this->locationId());
    }
}
