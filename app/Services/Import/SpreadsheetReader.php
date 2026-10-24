<?php

namespace App\Services\Import;

use App\Exceptions\PosException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\CSV\Sheet as CsvSheet;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Reader\XLSX\Sheet as XlsxSheet;

/**
 * Reads an .xlsx or .csv import file with openspout (streamed, low memory;
 * docs/PHARMACY.md §10). The headings are the first row with anything in
 * it; every later non-empty row is data, numbered as in the sheet. In a
 * workbook the data sheet is the first visible one that is not the
 * template's Instructions or Lists sheet. A CSV may be separated by commas,
 * semicolons or tabs (Excel in some locales saves semicolons), in UTF-8 or
 * the Windows-1252 older Excel saves.
 */
final class SpreadsheetReader
{
    /** Sheets of our templates that never hold data. */
    private const NOT_DATA = ['instructions', 'lists'];

    /**
     * @return array{headings: list<string>, rows: list<array{0: int, 1: list<mixed>}>}
     *
     * @throws PosException
     */
    public static function read(string $path, string $extension, int $maxRows, bool $headingsOnly = false): array
    {
        $reader = self::open($path, $extension);

        try {
            $sheet = self::dataSheet($reader);
            $headings = null;
            $rows = [];
            $number = 0;

            /** @var Row $row */
            foreach ($sheet->getRowIterator() as $row) {
                $number++;
                $cells = array_values(array_map(fn ($cell): mixed => self::value($cell->getValue()), $row->getCells()));

                if (self::isEmpty($cells)) {
                    continue;
                }

                if ($headings === null) {
                    $headings = self::headings($cells);

                    if ($headingsOnly) {
                        break;
                    }

                    continue;
                }

                if (count($rows) >= $maxRows) {
                    throw new PosException("The file has more than {$maxRows} rows. Split it into smaller files.");
                }

                $rows[] = [$number, $cells];
            }
        } finally {
            $reader->close();
        }

        if ($headings === null) {
            throw new PosException('The file is empty: it needs a row of column headings and the rows below it.');
        }

        return ['headings' => $headings, 'rows' => $rows];
    }

    private static function open(string $path, string $extension): XlsxReader|CsvReader
    {
        $extension = strtolower($extension);

        try {
            if ($extension === 'xlsx') {
                $options = new XlsxOptions;
                $options->SHOULD_PRESERVE_EMPTY_ROWS = true; // so row numbers match the sheet
                $reader = new XlsxReader($options);
            } elseif (in_array($extension, ['csv', 'txt'], true)) {
                $options = new CsvOptions;
                $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
                $options->FIELD_DELIMITER = self::delimiter($path);
                $options->ENCODING = self::encoding($path);
                $reader = new CsvReader($options);
            } else {
                throw new PosException('Upload an Excel workbook (.xlsx) or a CSV file (.csv).');
            }

            $reader->open($path);
        } catch (PosException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new PosException('The file could not be read. Save it again from Excel as .xlsx or .csv and upload that.');
        }

        return $reader;
    }

    private static function dataSheet(XlsxReader|CsvReader $reader): XlsxSheet|CsvSheet
    {
        $first = null;

        foreach ($reader->getSheetIterator() as $sheet) {
            $first ??= $sheet;

            $visible = ! $sheet instanceof XlsxSheet || $sheet->isVisible();

            if ($visible && ! in_array(mb_strtolower(trim($sheet->getName())), self::NOT_DATA, true)) {
                return $sheet;
            }
        }

        return $first ?? throw new PosException('The file has no sheets.');
    }

    /** UTF-8, or Windows-1252 for a CSV saved by an older Excel ("CSV (Comma delimited)"). */
    private static function encoding(string $path): string
    {
        $sample = (string) file_get_contents($path, length: 65536);

        return mb_check_encoding($sample, 'UTF-8') ? 'UTF-8' : 'Windows-1252';
    }

    /** The separator used on the first line: comma, semicolon or tab. */
    private static function delimiter(string $path): string
    {
        $handle = fopen($path, 'r');
        $line = $handle === false ? '' : (string) fgets($handle);

        if ($handle !== false) {
            fclose($handle);
        }

        $line = (string) preg_replace('/"[^"]*"/', '', $line); // separators inside quotes don't count
        $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /**
     * @param  list<mixed>  $cells
     * @return list<string>
     */
    private static function headings(array $cells): array
    {
        $last = -1;

        foreach ($cells as $index => $cell) {
            if (ImportRow::stringOf($cell) !== '') {
                $last = $index;
            }
        }

        $headings = [];

        // Blank cells after the last heading are only formatting; a blank one in between gets a name.
        for ($index = 0; $index <= $last; $index++) {
            $text = ImportRow::stringOf($cells[$index]);
            $headings[] = $text === '' ? 'Column '.self::columnLetter($index) : $text;
        }

        return $headings;
    }

    /** 0 is A, 26 is AA. */
    public static function columnLetter(int $index): string
    {
        $letter = '';

        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letter = chr(65 + (($n - 1) % 26)).$letter;
        }

        return $letter;
    }

    private static function value(mixed $value): mixed
    {
        if (is_string($value)) {
            // A UTF-8 byte order mark on the first CSV cell is not part of the heading.
            $value = trim(str_replace("\u{FEFF}", '', $value));

            return $value === '' ? null : $value;
        }

        return $value;
    }

    /** @param  list<mixed>  $cells */
    private static function isEmpty(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (ImportRow::stringOf($cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
