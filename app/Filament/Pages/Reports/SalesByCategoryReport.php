<?php

namespace App\Filament\Pages\Reports;

class SalesByCategoryReport extends ReportPage
{
    protected static ?string $title = 'Sales by category';

    protected static ?int $navigationSort = 3;

    protected static bool $hasLocationFilter = true;

    /** @var 'category'|'brand' */
    protected string $groupBy = 'category';

    protected function reportColumns(): array
    {
        return [
            ReportColumn::text('name', $this->groupBy === 'brand' ? 'Brand' : 'Category'),
            ReportColumn::quantity('quantity', 'Quantity'),
            ReportColumn::money('gross', 'Sales total'),
            ReportColumn::money('refunds', 'Refunds'),
            ReportColumn::money('net', 'Net sales'),
        ];
    }

    protected function reportRows(): iterable
    {
        return $this->reports()->salesByGroup($this->period(), $this->groupBy, $this->locationId());
    }
}
