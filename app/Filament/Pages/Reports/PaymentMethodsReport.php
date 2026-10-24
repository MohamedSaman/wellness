<?php

namespace App\Filament\Pages\Reports;

/** Cash is shown net of change given back, so it matches the drawer. */
class PaymentMethodsReport extends ReportPage
{
    protected static ?string $title = 'Payment methods';

    protected static ?int $navigationSort = 5;

    protected static bool $hasLocationFilter = true;

    protected function reportColumns(): array
    {
        return [
            ReportColumn::text('method', 'Method'),
            ReportColumn::integer('payments', 'Payments'),
            ReportColumn::money('taken', 'Taken'),
            ReportColumn::money('refunds', 'Refunded'),
            ReportColumn::money('net', 'Net'),
        ];
    }

    protected function reportRows(): iterable
    {
        return $this->reports()->paymentMethods($this->period(), $this->locationId());
    }
}
