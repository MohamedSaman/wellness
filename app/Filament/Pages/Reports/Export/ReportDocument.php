<?php

namespace App\Filament\Pages\Reports\Export;

/**
 * A report as a document (docs/PHARMACY.md §9, branch 11): its title, what
 * it covers (period, location, filters) and its tables. The Excel file,
 * the PDF and the print page are all written from one, so they show the
 * same figures as the screen.
 */
final readonly class ReportDocument
{
    /**
     * @param  list<array{0: string, 1: string}>  $meta  label and value: "Period", "01/10/2026 – 21/10/2026"
     * @param  list<ReportTable>  $tables
     * @param  string  $filename  without extension: "daily-summary_2026-10-21_2026-10-21"
     */
    public function __construct(
        public string $title,
        public array $meta,
        public array $tables,
        public string $filename,
    ) {}
}
