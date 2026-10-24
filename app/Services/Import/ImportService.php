<?php

namespace App\Services\Import;

use App\Enums\ImportExisting;
use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Exceptions\PosException;
use App\Jobs\RunImport;
use App\Models\ImportMapping;
use App\Models\ImportRun;
use App\Models\Shop;
use App\Models\User;
use App\Support\CurrentShop;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Excel / CSV import (docs/PHARMACY.md §10, branch 7), owner only
 * (imports.manage):
 *
 *   upload()   keep the file, read its headings, suggest which column is
 *              which field (the shop's saved mapping first, then the
 *              headings' names);
 *   preview()  the dry run: every row read and checked as it would be
 *              written (errors, warnings, create / update / skip), an error
 *              report written; nothing else is written;
 *   confirm()  queue it (RunImport);
 *   run()      the write: the rows checked again against what is on file
 *              now and written through the services, all in one
 *              transaction, so a file goes in whole or not at all.
 *
 * Re-running a file is safe: what is already on file is matched (items by
 * SKU or barcode, suppliers by name, opening stock by batch, opening
 * invoices by number) and skipped or updated, never created twice. Imports
 * of one shop run one at a time (the shop row is locked first).
 */
class ImportService
{
    public const DISK = 'local';

    public function __construct(private readonly CurrentShop $currentShop) {}

    public function definition(ImportType $type): ImportDefinition
    {
        return app(match ($type) {
            ImportType::Items => ItemsImport::class,
            ImportType::Suppliers => SuppliersImport::class,
            ImportType::OpeningStock => OpeningStockImport::class,
            ImportType::OpeningInvoices => OpeningInvoicesImport::class,
        });
    }

    /** The template workbook for this shop, written to a temporary file the caller sends and deletes. */
    public function template(ImportType $type, User $user): string
    {
        $shop = $this->authorize($user, $type);
        $path = tempnam(sys_get_temp_dir(), 'pos-template-').'.xlsx';

        TemplateWriter::write($this->definition($type), $shop, $path, $this->maxRows());

        return $path;
    }

    /**
     * Keep an uploaded file and read its headings. Nothing is checked or
     * written yet.
     *
     * @param  string  $sourcePath  where the upload is now
     * @param  string  $fileName  as the user named it
     */
    public function upload(ImportType $type, string $sourcePath, string $fileName, User $user): ImportRun
    {
        $shop = $this->authorize($user, $type);
        $extension = Str::lower(pathinfo($fileName, PATHINFO_EXTENSION));

        if (! in_array($extension, ['xlsx', 'csv', 'txt'], true)) {
            throw new PosException('Upload an Excel workbook (.xlsx) or a CSV file (.csv). Older .xls files: open them in Excel and save as .xlsx.');
        }

        if (! is_file($sourcePath) || filesize($sourcePath) === 0) {
            throw new PosException('The file is empty.');
        }

        if (filesize($sourcePath) > $this->maxFileKb() * 1024) {
            throw new PosException('The file is larger than '.intdiv($this->maxFileKb(), 1024).' MB. Split it into smaller files.');
        }

        $headings = SpreadsheetReader::read($sourcePath, $extension, $this->maxRows(), headingsOnly: true)['headings'];
        $uuid = (string) Str::uuid7();
        $stored = Storage::disk(self::DISK)->putFileAs("imports/{$shop->getKey()}", new File($sourcePath), "{$uuid}.{$extension}");

        if ($stored === false) {
            throw new \RuntimeException('The uploaded file could not be stored.');
        }

        return ImportRun::query()->create([
            'uuid' => $uuid,
            'user_id' => $user->getKey(),
            'type' => $type,
            'status' => ImportStatus::Uploaded,
            'file_name' => Str::limit(basename($fileName), 250, ''),
            'file_path' => $stored,
            'file_hash' => (string) hash_file('sha256', $sourcePath),
            'headings' => $headings,
            'mapping' => $this->suggestMapping($type, $headings),
            'existing' => ImportExisting::Skip,
        ]);
    }

    /**
     * Which column holds which field. A file laid out like the last one the
     * shop saved a mapping for (the same headings) gets exactly that mapping,
     * columns left out included. Otherwise: the saved mapping where its
     * heading is in this file, then a heading with the field's name (the
     * template's), then one of its other names ("Qty", "Exp"). Each column is
     * used once.
     *
     * @param  list<string>  $headings
     * @return array<string, int> field key => column index
     */
    public function suggestMapping(ImportType $type, array $headings): array
    {
        $fields = $this->definition($type)->fields($this->currentShop->getOrFail());
        $normalized = array_map(fn (string $heading): string => ImportField::normalizeHeading($heading), $headings);
        /** @var array{headings?: list<string>, fields?: array<string, string|null>} $saved */
        $saved = ImportMapping::query()->where('type', $type->value)->first()->mapping ?? [];
        $savedFields = $saved['fields'] ?? [];
        $sameLayout = ($saved['headings'] ?? null) === $normalized;
        $mapping = [];
        $used = [];

        $take = function (string $key, int|false $index) use (&$mapping, &$used): void {
            if ($index !== false && ! isset($mapping[$key]) && ! isset($used[$index])) {
                $mapping[$key] = $index;
                $used[$index] = true;
            }
        };

        foreach ($fields as $field) {
            if (isset($savedFields[$field->key])) {
                $take($field->key, array_search(ImportField::normalizeHeading($savedFields[$field->key]), $normalized, true));
            }
        }

        if ($sameLayout) {
            return $mapping;
        }

        foreach ($fields as $field) {
            $take($field->key, array_search(ImportField::normalizeHeading($field->label), $normalized, true));
        }

        foreach ($fields as $field) {
            foreach ($field->matches() as $name) {
                $take($field->key, array_search($name, $normalized, true));
            }
        }

        return $mapping;
    }

    /**
     * The dry run. Reads every row with the mapping, checks it exactly as
     * the import would, and writes the error report. Writes nothing else
     * (apart from the run's own log entry and, if asked, the saved mapping).
     *
     * @param  array<string, int|string|null>  $mapping  field key => column index (null: not in the file)
     */
    public function preview(ImportRun $run, array $mapping, ImportExisting $existing, User $user, bool $saveMapping = true): ImportPreview
    {
        $shop = $this->authorize($user, $run->type);

        if (! in_array($run->status, [ImportStatus::Uploaded, ImportStatus::Previewed, ImportStatus::Failed], true)) {
            throw new PosException('This import has already been '.($run->status === ImportStatus::Completed ? 'imported' : 'confirmed').'.');
        }

        $mapping = $this->checkedMapping($run, $mapping);
        $context = new ImportContext($shop, $user, $run->type->canUpdateExisting() ? $existing : ImportExisting::Skip, $run);
        [$rows, $examples] = $this->rows($run, $mapping);

        $this->definition($run->type)->check($rows, $context);

        $counts = $this->counts([...$rows, ...$examples]);
        $report = $this->writeReport($run, $rows);

        if ($saveMapping) {
            $this->saveMapping($run, $mapping, $user);
        }

        $run->update([
            'user_id' => $user->getKey(),
            'status' => ImportStatus::Previewed,
            'mapping' => $mapping,
            'existing' => $context->existing,
            'total_rows' => count($rows) + count($examples),
            'preview_counts' => $counts,
            'result_counts' => null,
            'error_report_path' => $report,
            'message' => null,
            'previewed_at' => now(),
        ]);

        $issues = [];
        $limit = (int) config('pos.imports.preview_issue_limit', 200);

        foreach ([...$examples, ...$rows] as $row) {
            if (($row->errors !== [] || $row->warnings !== []) && count($issues) < $limit) {
                $issues[] = ['row' => $row->number, 'errors' => $row->errors, 'warnings' => $row->warnings];
            }
        }

        usort($issues, fn (array $a, array $b): int => [$a['errors'] === [], $a['row']] <=> [$b['errors'] === [], $b['row']]);

        return new ImportPreview($run, $counts, $issues, $this->importedBefore($run));
    }

    /** Confirm a checked file: it is queued and written by RunImport. */
    public function confirm(ImportRun $run, User $user): ImportRun
    {
        $this->authorize($user, $run->type);

        DB::transaction(function () use ($run, $user): void {
            $locked = ImportRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();
            $counts = $locked->preview_counts ?? [];

            if ($locked->status !== ImportStatus::Previewed) {
                throw new PosException($locked->status->isActive() || $locked->status === ImportStatus::Completed
                    ? 'This import has already been confirmed.'
                    : 'Check the file first.');
            }

            if (($counts['errors'] ?? 0) > 0) {
                throw new PosException('Some rows have errors. Correct them in the file (the error report lists them) and upload it again: a file is imported whole or not at all.');
            }

            if (($counts['created'] ?? 0) + ($counts['updated'] ?? 0) === 0) {
                throw new PosException('There is nothing to import: every row is already on file or skipped.');
            }

            $locked->update(['status' => ImportStatus::Queued, 'queued_at' => now(), 'user_id' => $user->getKey(), 'message' => null]);

            RunImport::dispatch((int) $locked->shop_id, (int) $locked->getKey())->afterCommit();
        });

        return $run->refresh();
    }

    /**
     * Write a confirmed import (RunImport). The rows are read and checked
     * again first, against what is on file now; any row with an error then
     * fails the whole import. Everything is written in one transaction,
     * after locking the shop row, so imports of a shop run one at a time
     * and see each other's results. Whatever fails, nothing is written.
     */
    public function run(ImportRun $run): void
    {
        $claimed = ImportRun::query()->whereKey($run->getKey())->where('status', ImportStatus::Queued->value)
            ->update(['status' => ImportStatus::Running->value, 'started_at' => now()]);

        if ($claimed === 0) {
            return; // run already, or not confirmed
        }

        $run->refresh();
        $user = $run->user()->firstOrFail();
        $previousUser = Auth::user();
        Auth::setUser($user); // the audit log names the importer

        try {
            $shop = $this->authorize($user, $run->type);
            $definition = $this->definition($run->type);

            $counts = DB::transaction(function () use ($run, $user, $shop, $definition): array {
                Shop::query()->whereKey($shop->getKey())->lockForUpdate()->first();

                $context = new ImportContext($shop, $user, $run->existing, $run);
                [$rows, $examples] = $this->rows($run, (array) $run->mapping);
                $definition->check($rows, $context);

                $bad = array_values(array_filter($rows, fn (ImportRow $row): bool => $row->hasErrors()));

                if ($bad !== []) {
                    throw new PosException(count($bad).' row(s) have errors now (row '.$bad[0]->number.': '.$bad[0]->errors[0].'). Something changed since the check: check the file again.');
                }

                $definition->commit($rows, $context, fn (int $done) => ImportProgress::set($run, $done + count($examples)));

                return $this->counts([...$rows, ...$examples]);
            });

            $run->update(['status' => ImportStatus::Completed, 'result_counts' => $counts, 'finished_at' => now()]);
        } catch (\Throwable $e) {
            if (! $e instanceof PosException) {
                report($e);
            }

            $run->update([
                'status' => ImportStatus::Failed,
                'message' => ($e instanceof PosException ? $e->getMessage() : 'Something went wrong while importing ('.class_basename($e).').').' Nothing was imported.',
                'finished_at' => now(),
            ]);
        } finally {
            ImportProgress::forget($run);
            $previousUser instanceof Authenticatable ? Auth::setUser($previousUser) : Auth::forgetUser();
        }
    }

    /** Rows written so far by a running import. */
    public function progress(ImportRun $run): int
    {
        return ImportProgress::get($run);
    }

    /** @return list<ImportField> */
    public function fieldsFor(ImportRun $run): array
    {
        return $this->definition($run->type)->fields($this->currentShop->getOrFail());
    }

    /** The newest earlier run of this same file that was imported, if any. */
    public function importedBefore(ImportRun $run): ?ImportRun
    {
        return ImportRun::query()
            ->with('user')
            ->where('type', $run->type->value)
            ->where('file_hash', $run->file_hash)
            ->where('status', ImportStatus::Completed->value)
            ->whereKeyNot($run->getKey())
            ->latest('finished_at')
            ->first();
    }

    public function maxRows(): int
    {
        return (int) config('pos.imports.max_rows', 20000);
    }

    public function maxFileKb(): int
    {
        return (int) config('pos.imports.max_file_kb', 20480);
    }

    /**
     * Every data row of the file as ImportRows, and apart from them the
     * template's example rows left in (skipped, never checked).
     *
     * @param  array<string, int|string|null>  $mapping
     * @return array{0: list<ImportRow>, 1: list<ImportRow>}
     */
    private function rows(ImportRun $run, array $mapping): array
    {
        $shop = $this->currentShop->getOrFail();
        $definition = $this->definition($run->type);
        $extension = pathinfo($run->file_path, PATHINFO_EXTENSION);
        $file = SpreadsheetReader::read(Storage::disk(self::DISK)->path($run->file_path), $extension, $this->maxRows());

        $labels = [];

        foreach ($definition->fields($shop) as $field) {
            $labels[$field->key] = $field->label;
        }

        $examples = array_map(fn (array $values): array => $this->canonical($values), $definition->examples($shop));
        $rows = [];
        $exampleRows = [];

        foreach ($file['rows'] as [$number, $cells]) {
            $values = [];

            foreach ($mapping as $key => $index) {
                if ($index !== null && isset($labels[$key])) {
                    $values[$key] = $cells[(int) $index] ?? null;
                }
            }

            $row = new ImportRow($number, $cells, $values, $labels);

            if ($this->isExample($values, $examples)) {
                $row->skip('An example row from the template: not imported.');
                $exampleRows[] = $row;

                continue;
            }

            $rows[] = $row;
        }

        return [$rows, $exampleRows];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<array<string, string>>  $examples
     */
    private function isExample(array $values, array $examples): bool
    {
        $row = $this->canonical($values);

        foreach ($examples as $example) {
            $compared = 0;
            $same = true;

            foreach ($example as $key => $value) {
                if (! array_key_exists($key, $values)) {
                    continue;
                }

                $compared++;

                if (($row[$key] ?? '') !== $value) {
                    $same = false;

                    break;
                }
            }

            if ($same && $compared >= 2) {
                return true;
            }
        }

        return false;
    }

    /**
     * Values compared as text: "450" and 450.0 are the same.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    private function canonical(array $values): array
    {
        return array_map(function (mixed $value): string {
            $text = mb_strtolower(ImportRow::stringOf($value));

            return is_numeric($text) ? rtrim(rtrim(number_format((float) $text, 6, '.', ''), '0'), '.') : $text;
        }, $values);
    }

    /**
     * @param  array<string, int|string|null>  $mapping
     * @return array<string, int>
     */
    private function checkedMapping(ImportRun $run, array $mapping): array
    {
        $headings = $run->headings ?? [];
        $fields = $this->definition($run->type)->fields($this->currentShop->getOrFail());
        $checked = [];
        $used = [];

        foreach ($fields as $field) {
            $index = $mapping[$field->key] ?? null;

            if ($index === null || $index === '') {
                if ($field->required) {
                    throw new PosException("Choose the column that holds {$field->label}: it is required.");
                }

                continue;
            }

            $index = (int) $index;

            if (! array_key_exists($index, $headings)) {
                throw new PosException("{$field->label}: the file has no such column.");
            }

            if (isset($used[$index])) {
                throw new PosException("The column \"{$headings[$index]}\" is chosen for both {$used[$index]} and {$field->label}.");
            }

            $used[$index] = $field->label;
            $checked[$field->key] = $index;
        }

        return $checked;
    }

    /** @param  array<string, int>  $mapping */
    private function saveMapping(ImportRun $run, array $mapping, User $user): void
    {
        $headings = $run->headings ?? [];

        ImportMapping::query()->updateOrCreate(
            ['type' => $run->type->value],
            ['mapping' => [
                'headings' => array_map(fn (string $heading): string => ImportField::normalizeHeading($heading), $headings),
                'fields' => array_map(fn (int $index): string => $headings[$index], $mapping),
            ], 'user_id' => $user->getKey()],
        );
    }

    /**
     * @param  list<ImportRow>  $rows
     * @return array{rows: int, created: int, updated: int, skipped: int, errors: int, warnings: int, expiry_months: int}
     */
    private function counts(array $rows): array
    {
        $counts = ['rows' => count($rows), 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'warnings' => 0, 'expiry_months' => 0];

        foreach ($rows as $row) {
            if ($row->hasErrors()) {
                $counts['errors']++;
            } elseif ($row->action === ImportRow::CREATE) {
                $counts['created']++;
            } elseif ($row->action === ImportRow::UPDATE) {
                $counts['updated']++;
            } else {
                $counts['skipped']++;
            }

            if ($row->warnings !== []) {
                $counts['warnings']++;
            }

            if ($row->expiryReadAsMonth) {
                $counts['expiry_months']++;
            }
        }

        return $counts;
    }

    /**
     * The error report (rows with errors or warnings), replacing the last
     * one; null when every row is clean.
     *
     * @param  list<ImportRow>  $rows
     */
    private function writeReport(ImportRun $run, array $rows): ?string
    {
        $disk = Storage::disk(self::DISK);

        if ($run->error_report_path !== null) {
            $disk->delete($run->error_report_path);
        }

        if (! collect($rows)->contains(fn (ImportRow $row): bool => $row->errors !== [] || $row->warnings !== [])) {
            return null;
        }

        $path = "imports/{$run->shop_id}/{$run->uuid}-report-".now()->format('YmdHis').'.xlsx';
        $disk->makeDirectory(dirname($path));
        ErrorReportWriter::write($disk->path($path), $run->headings ?? [], $rows);

        return $path;
    }

    private function authorize(User $user, ImportType $type): Shop
    {
        if (! $user->can('imports.manage')) {
            throw new PosException('You do not have permission to import data.');
        }

        $shop = $this->currentShop->getOrFail();

        if (! $type->availableFor($shop)) {
            throw new PosException("{$type->label()} can only be imported with the {$type->module()} module on.");
        }

        return $shop;
    }
}
