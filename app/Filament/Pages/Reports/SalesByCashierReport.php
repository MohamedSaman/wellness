<?php

namespace App\Filament\Pages\Reports;

class SalesByCashierReport extends ReportPage
{
    protected static ?string $title = 'Sales by cashier';

    protected static ?int $navigationSort = 2;

    protected static bool $hasLocationFilter = true;

    protected function reportColumns(): array
    {
        return [
            ReportColumn::text('cashier', 'Cashier'),
            ReportColumn::integer('sales_count', 'Sales'),
            ReportColumn::money('gross', 'Sales total'),
            ReportColumn::money('refunds', 'Refunds'),
            ReportColumn::money('net', 'Net sales'),
            ReportColumn::money('average', 'Average sale', total: false),
            ReportColumn::integer('reprints', 'Reprints'),
        ];
    }

    protected function reportRows(): iterable
    {
        return $this->reports()->salesByCashier($this->period(), $this->locationId());
    }
}
