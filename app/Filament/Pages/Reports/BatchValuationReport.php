<?php

namespace App\Filament\Pages\Reports;

use App\Support\CurrentShop;

/**
 * Stock by batch at batch cost (docs/PHARMACY.md §9, branch 11; pharmacy):
 * every real batch holding stock, per location, soonest expiry first, at
 * its own purchase cost. Stock valuation values the same stock at each
 * location's weighted average. The unallocated batch is left out.
 */
class BatchValuationReport extends ReportPage
{
    protected static ?string $title = 'Stock by batch';

    protected static ?int $navigationSort = 21;

    protected static bool $hasDateRange = false;

    protected static bool $hasLocationFilter = true;

    protected static function isAvailable(): bool
    {
        return app(CurrentShop::class)->get()?->hasModule('pharmacy') ?? false;
    }

    protected function reportColumns(): array
    {
        return [
            ...($this->multiLocation() ? [ReportColumn::text('location', 'Location')] : []),
            ReportColumn::text('sku', 'SKU'),
            ReportColumn::text('item', 'Medicine'),
            ReportColumn::text('batch', 'Batch'),
            ReportColumn::text('expiry', 'Expiry'),
            ReportColumn::quantity('quantity', 'On hand (base units)', total: false),
            ReportColumn::money('unit_cost', 'Batch cost', total: false)->costOnly(),
            ReportColumn::money('value', 'Value at batch cost')->costOnly(),
        ];
    }

    protected function reportRows(): iterable
    {
        return $this->reports()->batchValuation($this->locationId());
    }
}
