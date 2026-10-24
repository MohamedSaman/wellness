<?php

namespace Tests\Feature\Pos\Concerns;

use App\Enums\ImportExisting;
use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Models\ImportRun;
use App\Models\User;
use App\Services\Import\ImportPreview;
use App\Services\Import\ImportService;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Builds import files (xlsx or csv) from a list of headings and rows, and
 * runs them through ImportService: upload, the dry run, confirm (the queue
 * is sync in tests, so confirming writes it).
 */
trait WorksWithImports
{
    /** @var list<string> */
    private array $importFiles = [];

    /** Uploads and error reports go to a fake disk, not storage/app. Call from setUp(). */
    protected function fakeImportStorage(): void
    {
        Storage::fake(ImportService::DISK);
    }

    protected function imports(): ImportService
    {
        return app(ImportService::class);
    }

    /**
     * @param  list<string>  $headings
     * @param  list<list<mixed>>  $rows
     */
    protected function spreadsheet(array $headings, array $rows, string $extension = 'xlsx'): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pos-import-test-').'.'.$extension;
        $this->importFiles[] = $path;

        $writer = $extension === 'csv' ? new CsvWriter : new XlsxWriter;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues($headings));

        // Dates as Excel stores a date cell: a serial number with a date format.
        $date = (new Style)->setFormat('dd/mm/yyyy');

        foreach ($rows as $row) {
            $writer->addRow(new Row(array_map(fn (mixed $value): Cell => Cell::fromValue($value, $value instanceof \DateTimeInterface ? $date : null), $row)));
        }

        $writer->close();

        return $path;
    }

    /**
     * A file whose headings are the template's labels: rows given as field key => value.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    protected function templateFile(ImportType $type, array $rows, string $extension = 'xlsx'): string
    {
        $fields = $this->imports()->definition($type)->fields($this->shop);
        $headings = array_map(fn ($field): string => $field->label, $fields);
        $data = array_map(fn (array $row): array => array_map(fn ($field): mixed => $row[$field->key] ?? null, $fields), $rows);

        return $this->spreadsheet($headings, $data, $extension);
    }

    /** @param  array<string, int|null>|null  $mapping  null: the suggested one */
    protected function preview(ImportType $type, string $path, ?array $mapping = null, ImportExisting $existing = ImportExisting::Skip, ?User $user = null, ?string $name = null): ImportPreview
    {
        $user ??= $this->owner;
        $run = $this->imports()->upload($type, $path, $name ?? 'import.'.pathinfo($path, PATHINFO_EXTENSION), $user);

        return $this->imports()->preview($run, $mapping ?? (array) $run->mapping, $existing, $user);
    }

    /** Check, then confirm: the import is written (the queue is sync in tests). */
    protected function import(ImportType $type, string $path, ImportExisting $existing = ImportExisting::Skip, ?array $mapping = null): ImportRun
    {
        $preview = $this->preview($type, $path, $mapping, $existing);
        $this->assertSame(0, $preview->counts['errors'], 'Rows with errors: '.json_encode($preview->issues));

        return $this->imports()->confirm($preview->run, $this->owner)->refresh();
    }

    protected function assertImported(ImportRun $run): void
    {
        $this->assertSame(ImportStatus::Completed, $run->status, (string) $run->message);
    }

    /** @param  list<array{row: int, errors: list<string>, warnings: list<string>}>  $issues */
    protected function issuesOf(ImportPreview $preview, int $row): array
    {
        foreach ($preview->issues as $issue) {
            if ($issue['row'] === $row) {
                return $issue;
            }
        }

        return ['row' => $row, 'errors' => [], 'warnings' => []];
    }

    protected function tearDown(): void
    {
        foreach ($this->importFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }
}
