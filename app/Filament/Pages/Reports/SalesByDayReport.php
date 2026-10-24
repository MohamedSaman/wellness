<?php

namespace App\Filament\Pages\Reports;

class SalesByDayReport extends ReportPage
{
    protected static ?string $title = 'Sales by day';

    protected static ?int $navigationSort = 1;

    protected static bool $hasLocationFilter = true;

    protected function reportColumns(): array
    {
        return [
            ReportColumn::text('date', 'Date'),
            ReportColumn::integer('sales_count', 'Sales'),
            ReportColumn::money('gross', 'Sales total'),
            ReportColumn::money('discounts', 'Discounts'),
            ReportColumn::money('tax', 'Tax'),
            ReportColumn::money('refunds', 'Refunds'),
            ReportColumn::money('net', 'Net sales'),
        ];
    }

    protected function reportRows(): iterable
    {
        return $this->reports()->salesByDay($this->period(), $this->locationId());
    }
}
