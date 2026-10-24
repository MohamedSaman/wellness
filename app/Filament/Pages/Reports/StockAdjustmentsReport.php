<?php

namespace App\Filament\Pages\Reports;

/**
 * Stock adjustments and write-offs by reason (docs/PHARMACY.md §9, branch
 * 11; ReportService::stockAdjustments()): every adjustment in the period by
 * reason and by where it came from (a write-off note, a stock count, by
 * hand), with the quantities in and out and the value at the ledger's
 * cost (a write-off at batch cost, anything else at the location's average).
 */
class StockAdjustmentsReport extends ReportPage
{
    protected static ?string $title = 'Adjustments and write-offs';

    protected static ?int $navigationSort = 24;

    protected static bool $hasLocationFilter = true;

    private const SOURCES = ['write_off' => 'Write-off note', 'count' => 'Stock count', 'manual' => 'By hand'];

    protected function reportColumns(): array
    {
        return [
            ReportColumn::text('label', 'Reason'),
            ReportColumn::text('source', 'From'),
            ReportColumn::integer('movements', 'Movements'),
            ReportColumn::quantity('quantity_in', 'In (base units)'),
            ReportColumn::quantity('quantity_out', 'Out (base units)'),
            ReportColumn::money('value', 'Value at cost')->costOnly(),
        ];
    }

    protected function reportRows(): iterable
    {
        return array_map(
            fn (array $row): array => [...$row, 'source' => self::SOURCES[$row['source']] ?? $row['source']],
            $this->reports()->stockAdjustments($this->period(), $this->locationId()),
        );
    }

    protected function documentNote(): ?string
    {
        return 'Value: in less out, at the cost the ledger recorded.';
    }
}
