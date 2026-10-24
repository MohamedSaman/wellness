<?php

namespace App\Filament\Pages\Reports\Export;

use App\Filament\Pages\Reports\ReportColumn;

/**
 * One table of a report as it is exported: its columns, rows and total
 * row, exactly as the screen shows them (docs/PHARMACY.md §9, branch 11).
 */
final readonly class ReportTable
{
    /**
     * @param  list<ReportColumn>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $total  a last row, its first column saying "Total"
     */
    public function __construct(
        public ?string $title,
        public array $columns,
        public array $rows,
        public ?array $total = null,
        public ?string $note = null,
    ) {}

    /**
     * The rows and the total row, in order.
     *
     * @return list<array<string, mixed>>
     */
    public function allRows(): array
    {
        return $this->total === null ? $this->rows : [...$this->rows, $this->total];
    }
}
