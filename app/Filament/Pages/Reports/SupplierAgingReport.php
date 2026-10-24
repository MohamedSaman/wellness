<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Support\PayablesForm;
use App\Services\SupplierPayablesService;

/**
 * What is owed to each supplier by how late it is (docs/PHARMACY.md §9,
 * branch 11; purchasing): not yet due, 1–30, 31–60, 61–90 and over 90
 * days past the due date, the credit not yet allocated and the balance
 * (= the supplier's balance, SupplierService::reconcile()). Today's
 * figures; exportable, as each supplier's statement is from its page.
 * Needs reports.view and the payables permission (payables.view or
 * purchasing.manage).
 */
class SupplierAgingReport extends ReportPage
{
    protected static ?string $title = 'Supplier aging';

    protected static ?int $navigationSort = 31;

    protected static bool $hasDateRange = false;

    protected static function isAvailable(): bool
    {
        return PayablesForm::canSee();
    }

    protected function reportColumns(): array
    {
        return [
            ReportColumn::text('supplier', 'Supplier'),
            ...array_map(
                fn (string $key, string $label): ReportColumn => ReportColumn::money($key, $label),
                array_keys(SupplierPayablesService::AGING_BUCKETS),
                array_values(SupplierPayablesService::AGING_BUCKETS),
            ),
            ReportColumn::money('total', 'Outstanding'),
            ReportColumn::money('credit', 'Credit to allocate'),
            ReportColumn::money('balance', 'Balance'),
        ];
    }

    protected function reportRows(): iterable
    {
        return app(SupplierPayablesService::class)->agingBySupplier();
    }

    protected function documentNote(): ?string
    {
        return 'Days past each invoice\'s due date, today. Balance = outstanding − credit not yet allocated.';
    }
}
