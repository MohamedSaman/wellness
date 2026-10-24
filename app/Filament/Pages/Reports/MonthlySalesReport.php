<?php

namespace App\Filament\Pages\Reports;

/**
 * Sales over a month or any period (docs/PHARMACY.md §9, branch 11): the
 * totals, by location (the stock pool each line was sold from), by day,
 * by category, by schedule and by item (in base units and in packs), and
 * the OPD consultations with their income. Every table adds up to the same
 * net sales. With a location chosen, the totals and days are the bills
 * rung up at that counter and the other tables the lines sold from it
 * (the same thing when a counter sells only its own stock, as the
 * clinic's do).
 */
class MonthlySalesReport extends SummaryReportPage
{
    protected static ?string $title = 'Monthly sales';

    protected static ?int $navigationSort = -2;

    protected function tables(): array
    {
        $period = $this->period();
        $location = $this->locationId();
        $pharmacy = $this->hasPharmacy();
        $summary = $this->reports()->summary($period, $location);
        $breakdown = $this->reports()->salesBreakdown($period, ['location', 'category', 'schedule', 'item'], $location);
        $groups = $breakdown['groups'];

        $groupColumns = fn (string $label): array => [
            ReportColumn::text('name', $label),
            ReportColumn::money('gross', 'Sales'),
            ReportColumn::money('refunds', 'Refunds'),
            ReportColumn::money('net', 'Net sales'),
        ];

        $tables = [
            $this->makeTable('Totals', [
                ReportColumn::integer('sales_count', 'Bills'),
                ReportColumn::money('gross', 'Sales'),
                ReportColumn::money('refunds', 'Refunds'),
                ReportColumn::money('net', 'Net sales'),
                ReportColumn::money('average', 'Average bill', total: false),
            ], [$summary], withTotal: false),
        ];

        if ($this->multiLocation()) {
            $tables[] = $this->makeTable('By location', $groupColumns('Location'), $groups['location']);
        }

        $tables[] = $this->makeTable('By day', [
            ReportColumn::text('date', 'Date'),
            ReportColumn::integer('sales_count', 'Bills'),
            ReportColumn::money('gross', 'Sales'),
            ReportColumn::money('discounts', 'Discounts'),
            ReportColumn::money('tax', 'Tax'),
            ReportColumn::money('refunds', 'Refunds'),
            ReportColumn::money('net', 'Net sales'),
        ], $this->reports()->salesByDay($period, $location));

        $tables[] = $this->makeTable('By category', $groupColumns('Category'), $groups['category']);

        if ($pharmacy) {
            $tables[] = $this->makeTable('By schedule', $groupColumns('Schedule'), $groups['schedule']);

            $consultations = $breakdown['consultations'];
            $tables[] = $this->makeTable('OPD consultations', [
                ReportColumn::integer('opd_bills', 'OPD bills'),
                ReportColumn::quantity('consultations', 'Consultations'),
                ReportColumn::money('income', 'Consultation fees'),
                ReportColumn::money('refunds', 'Refunds'),
                ReportColumn::money('net', 'Net fees'),
                ReportColumn::money('opd_medicines', 'Medicines on OPD bills'),
            ], [$consultations], withTotal: false, note: 'Consultations: the consultation fees charged (one a visit), on any bill. Medicines on OPD bills: the Medicines amounts, as split over the medicines dispensed.');
        }

        $tables[] = $this->makeTable($pharmacy ? 'By medicine' : 'By item', [
            ReportColumn::text('sku', 'SKU'),
            ReportColumn::text('item', $pharmacy ? 'Medicine' : 'Item'),
            ...($pharmacy ? [ReportColumn::text('schedule', 'Schedule')] : []),
            ReportColumn::quantity('quantity', 'Quantity (base units)', total: false),
            ReportColumn::text('packs', 'In packs'),
            ReportColumn::money('gross', 'Sales'),
            ReportColumn::money('refunds', 'Refunds'),
            ReportColumn::money('net', 'Net sales'),
        ], $groups['item'], note: 'Quantities net of returns, in each item\'s base unit (tablet, ml, piece), and in its largest pack.');

        return $tables;
    }
}
