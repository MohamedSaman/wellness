<?php

namespace App\Services\Import;

use App\Models\ImportRun;

/**
 * The dry run's answer: how many rows would be created, updated or
 * skipped, which have errors or warnings (the first few, for the page; the
 * error report has them all), and whether this very file was imported
 * before.
 */
final readonly class ImportPreview
{
    /**
     * @param  array{rows: int, created: int, updated: int, skipped: int, errors: int, warnings: int, expiry_months: int}  $counts  expiry_months: rows whose expiry date cell was read as a month
     * @param  list<array{row: int, errors: list<string>, warnings: list<string>}>  $issues
     */
    public function __construct(
        public ImportRun $run,
        public array $counts,
        public array $issues,
        public ?ImportRun $importedBefore = null,
    ) {}

    public function canCommit(): bool
    {
        return $this->counts['errors'] === 0 && ($this->counts['created'] + $this->counts['updated']) > 0;
    }
}
