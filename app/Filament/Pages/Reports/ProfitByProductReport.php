<?php

namespace App\Filament\Pages\Reports;

/**
 * Revenue before tax, cost and gross profit per product. Owner only
 * (reports.profit); returns reverse revenue, and cost when restocked.
 */
class ProfitByProductReport extends ReportPage
{
    protected static ?string $title = 'Profit by product';

    protected static ?int $navigationSort = 11;

    protected static bool $hasLocationFilter = true;

    protected static bool $requiresProfitPermission = true;

    protected function reportColumns(): array
    {
        return [
            ReportColumn::text('product', 'Product'),
            ReportColumn::quantity('quantity', 'Quantity'),
            ReportColumn::money('revenue', 'Revenue (before tax)', profitOnly: true),
            ReportColumn::money('cost', 'Cost', profitOnly: true),
            ReportColumn::money('profit', 'Gross profit', profitOnly: true),
            ReportColumn::percent('margin', 'Margin %', profitOnly: true),
        ];
    }

    /** The total row's margin is total profit over total revenue, not a sum of percentages. */
    protected function totalRow(array $rows): ?array
    {
        $total = parent::totalRow($rows);

        if ($total !== null && isset($total['revenue'], $total['profit'])) {
            $total['margin'] = (int) $total['revenue'] === 0 ? null : round((int) $total['profit'] / (int) $total['revenue'] * 100, 1);
        }

        return $total;
    }

    protected function reportRows(): iterable
    {
        return $this->reports()->profitByProduct($this->period(), $this->locationId());
    }
}
