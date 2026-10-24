<?php

namespace App\Filament\Pages\Reports;

/**
 * The end-of-day report per counter (docs/PHARMACY.md §9, branch 11): for
 * each counter (selling location) the bills, sales, refunds, voids and net
 * sales; the takings by payment method; consultation fees against
 * medicines; each cashier's sales; and each shift's cash, expected against
 * counted. Days are the shop's days by the time each sale is counted at
 * (sales.reported_at). The totals reconcile: net = sales - refunds =
 * consultation fees + medicines = the payment methods' net.
 */
class DailySummaryReport extends SummaryReportPage
{
    protected static ?string $title = 'Daily summary';

    protected static ?string $navigationLabel = 'Daily summary (end of day)';

    protected static ?int $navigationSort = -3;

    protected static ?string $defaultRange = 'today';

    protected function locationLabel(): string
    {
        return 'Counter';
    }

    /** @return array{services: string, goods: string} */
    private function incomeLabels(): array
    {
        return $this->hasPharmacy()
            ? ['services' => 'Consultation fees', 'goods' => 'Medicines']
            : ['services' => 'Services', 'goods' => 'Goods'];
    }

    protected function tables(): array
    {
        $period = $this->period();
        $location = $this->locationId();
        $labels = $this->incomeLabels();
        $counters = $this->reports()->counters($period, $location);

        $tables = [
            $this->makeTable('By counter', [
                ReportColumn::text('location', 'Counter'),
                ReportColumn::integer('bills', 'Bills'),
                ReportColumn::money('gross', 'Sales'),
                ReportColumn::money('refunds', 'Refunds'),
                ReportColumn::money('net', 'Net sales'),
                ReportColumn::money('services', $labels['services']),
                ReportColumn::money('goods', $labels['goods']),
                ...($this->hasPharmacy() ? [ReportColumn::integer('opd_bills', 'OPD bills')] : []),
                ReportColumn::integer('voids', 'Voided bills'),
                ReportColumn::money('voids_total', 'Voided'),
            ], $counters, note: 'Net sales = sales − refunds given. Voided bills are not in the sales; they are shown for control, on the day they were voided.'),

            $this->makeTable('Payments', [
                ReportColumn::text('method', 'Method'),
                ReportColumn::integer('payments', 'Payments'),
                ReportColumn::money('taken', 'Taken'),
                ReportColumn::money('refunds', 'Refunded'),
                ReportColumn::money('net', 'Net'),
            ], $this->reports()->paymentMethods($period, $location), note: 'Cash is net of change given back.'),

            $this->makeTable($labels['services'].' and '.mb_strtolower($labels['goods']), [
                ReportColumn::text('type', 'Income'),
                ReportColumn::money('gross', 'Sales'),
                ReportColumn::money('refunds', 'Refunds'),
                ReportColumn::money('net', 'Net'),
            ], [
                ['type' => $labels['services'], 'gross' => array_sum(array_column($counters, 'services_gross')), 'refunds' => array_sum(array_column($counters, 'services_refunds')), 'net' => array_sum(array_column($counters, 'services'))],
                ['type' => $labels['goods'], 'gross' => array_sum(array_column($counters, 'goods_gross')), 'refunds' => array_sum(array_column($counters, 'goods_refunds')), 'net' => array_sum(array_column($counters, 'goods'))],
            ], note: $this->hasPharmacy() ? 'An OPD bill\'s Medicines amount counts as medicines, split over the medicines dispensed as the bill stored it.' : null),

            $this->makeTable('By cashier', [
                ReportColumn::text('cashier', 'Cashier'),
                ReportColumn::integer('sales_count', 'Bills'),
                ReportColumn::money('gross', 'Sales'),
                ReportColumn::money('refunds', 'Refunds'),
                ReportColumn::money('net', 'Net sales'),
                ReportColumn::integer('reprints', 'Reprints'),
            ], $this->reports()->salesByCashier($period, $location), note: 'Refunds count against the cashier who made the sale.'),
        ];

        $shifts = $this->reports()->shiftCash($period, $location);
        $cashColumns = [
            ReportColumn::text('till', 'Till'),
            ...($this->multiLocation() ? [ReportColumn::text('location', 'Counter')] : []),
            ReportColumn::text('cashier', 'Cashier'),
            ReportColumn::text('opened_at', 'Opened'),
            ReportColumn::text('closed_at', 'Closed'),
            ReportColumn::money('opening_cash', 'Float'),
            ReportColumn::money('cash_sales', 'Cash sales'),
            ReportColumn::money('cash_refunds', 'Cash refunds'),
            ReportColumn::money('expected_cash', 'Expected'),
            ReportColumn::money('counted_cash', 'Counted'),
            ReportColumn::money('difference', 'Difference'),
        ];
        $tables[] = $this->makeTable('Cash: expected and counted', $cashColumns, $shifts, note: 'Expected = float + cash sales − cash refunds. An open shift shows what its drawer should hold now; it is counted when it closes.');

        return $tables;
    }
}
