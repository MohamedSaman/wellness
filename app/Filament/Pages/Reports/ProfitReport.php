<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Pages\Reports\Export\ReportTable;
use App\Services\ReportService;

/**
 * Gross profit (docs/PHARMACY.md §9, branch 11; ReportService::profit()):
 * revenue as each line was charged (an OPD medicine line, its share of the
 * Medicines amount), cost as each line stored it when it was sold, gross
 * profit and margin, by location, category and medicine; returns netted at
 * their original line's cost; consultation fees on their own. Owner only
 * (reports.profit).
 */
class ProfitReport extends SummaryReportPage
{
    protected static ?string $title = 'Profit';

    protected static ?int $navigationSort = -1;

    protected static bool $requiresProfitPermission = true;

    /** @return list<ReportColumn> */
    private function columns(string $label): array
    {
        return [
            ReportColumn::text('name', $label),
            ReportColumn::quantity('quantity', 'Quantity (base units)', total: false),
            ReportColumn::money('revenue', 'Revenue (before tax)', profitOnly: true),
            ReportColumn::money('cost', 'Cost', profitOnly: true),
            ReportColumn::money('profit', 'Gross profit', profitOnly: true),
            ReportColumn::percent('margin', 'Margin %', profitOnly: true),
        ];
    }

    /**
     * The total row's margin is total profit over total revenue, not a sum of percentages.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function profitTable(string $title, string $label, array $rows, ?string $note = null): ReportTable
    {
        $table = $this->makeTable($title, $this->columns($label), $rows, note: $note);

        if ($table->total === null) {
            return $table;
        }

        $total = $table->total;
        $total['margin'] = ReportService::margin((int) ($total['revenue'] ?? 0), (int) ($total['profit'] ?? 0));

        return new ReportTable($table->title, $table->columns, $table->rows, $total, $table->note);
    }

    protected function tables(): array
    {
        $period = $this->period();
        $location = $this->locationId();
        $pharmacy = $this->hasPharmacy();
        $profit = $this->reports()->profitBy($period, ['product', 'category', 'location'], $location);
        $byItem = ['rows' => $profit['groups']['product'], ...$profit];
        [$goods, $services] = $pharmacy ? ['Medicines', 'Consultation fees'] : ['Goods', 'Services'];

        $summary = [
            ['name' => $goods, 'revenue' => $byItem['goods']['revenue'], 'cost' => $byItem['goods']['cost'], 'profit' => $byItem['goods']['profit'], 'margin' => $byItem['goods']['margin']],
            ['name' => $services, 'revenue' => $byItem['services_total']['revenue'], 'cost' => $byItem['services_total']['cost'], 'profit' => $byItem['services_total']['profit'], 'margin' => ReportService::margin($byItem['services_total']['revenue'], $byItem['services_total']['profit'])],
        ];

        $tables = [
            $this->profitTable('Summary', 'Income', $summary, 'Cost: each line\'s own, stored when it was sold. Returns put back on the shelf take back the cost they were sold at; goods not put back stay a cost. Services carry no stock, so no cost of goods unless one was given.'),
        ];

        if ($this->multiLocation()) {
            $tables[] = $this->profitTable("{$goods} by location", 'Location', $profit['groups']['location']);
        }

        $tables[] = $this->profitTable("{$goods} by category", 'Category', $profit['groups']['category']);
        $tables[] = $this->profitTable($pharmacy ? 'By medicine' : 'By item', $pharmacy ? 'Medicine' : 'Item', $byItem['rows'], $pharmacy ? 'An OPD bill\'s medicines earn their share of its Medicines amount, as the bill stored it.' : null);

        $tables[] = $this->makeTable($services, [
            ReportColumn::text('name', 'Service'),
            ReportColumn::quantity('quantity', 'Quantity'),
            ReportColumn::money('revenue', 'Revenue (before tax)', profitOnly: true),
            ReportColumn::money('cost', 'Cost', profitOnly: true),
            ReportColumn::money('profit', 'Gross profit', profitOnly: true),
        ], $byItem['services']);

        return $tables;
    }
}
