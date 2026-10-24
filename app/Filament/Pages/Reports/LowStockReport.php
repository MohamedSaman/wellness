<?php

namespace App\Filament\Pages\Reports;

class LowStockReport extends ReportPage
{
    protected static ?string $title = 'Low stock';

    protected static ?int $navigationSort = 9;

    protected static bool $hasDateRange = false;

    protected static bool $hasLocationFilter = true;

    protected function reportColumns(): array
    {
        return [
            ReportColumn::text('sku', 'SKU'),
            ReportColumn::text('product', 'Product'),
            ReportColumn::text('variant', 'Variant'),
            ...($this->multiLocation() ? [ReportColumn::text('location', 'Location')] : []),
            ReportColumn::quantity('on_hand', 'On hand', total: false),
            ReportColumn::quantity('reorder_level', 'Reorder level', total: false),
            ReportColumn::text('bin_location', 'Shelf / bin'),
        ];
    }

    protected function reportRows(): iterable
    {
        return $this->reports()->lowStock(locationId: $this->locationId());
    }
}
