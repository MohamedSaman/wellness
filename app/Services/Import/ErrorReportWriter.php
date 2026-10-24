<?php

namespace App\Services\Import;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The preview's error report: every row with an error or a warning, with
 * its row number, the cells exactly as uploaded under the file's own
 * headings, then what is wrong (Errors: the row can't be imported) and what
 * to check (Warnings). The extra columns are ignored if the corrected
 * report is uploaded again, so it can be fixed and re-imported as it is.
 */
final class ErrorReportWriter
{
    /**
     * @param  list<string>  $headings
     * @param  iterable<ImportRow>  $rows  only rows with errors or warnings are written
     */
    public static function write(string $path, array $headings, iterable $rows): void
    {
        $writer = new Writer;
        $writer->openToFile($path);

        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Errors');
        $sheet->setSheetView((new SheetView)->setFreezeRow(2));
        $sheet->setColumnWidth(8, 1);

        $columns = count($headings);

        foreach (range(2, $columns + 1) as $column) {
            $sheet->setColumnWidth(16, $column);
        }

        $sheet->setColumnWidth(60, $columns + 2);
        $sheet->setColumnWidth(60, $columns + 3);

        $bold = (new Style)->setFontBold()->setBackgroundColor('FFE8A3');
        $writer->addRow(new Row(array_map(fn (string $text): Cell => Cell::fromValue($text), ['Row', ...$headings, 'Errors', 'Warnings']), $bold));

        $text = (new Style)->setFormat('@');
        $red = (new Style)->setFontColor('B91C1C')->setShouldWrapText();
        $amber = (new Style)->setFontColor('92400E')->setShouldWrapText();

        foreach ($rows as $row) {
            if ($row->errors === [] && $row->warnings === []) {
                continue;
            }

            $cells = [Cell::fromValue($row->number)];

            for ($i = 0; $i < $columns; $i++) {
                $value = $row->cells[$i] ?? null;
                // Codes read from Excel as numbers go back as typed (no 8.9E+12).
                $cells[] = $value instanceof \DateTimeInterface || is_float($value) || is_bool($value)
                    ? Cell::fromValue(ImportRow::stringOf($value), $text)
                    : Cell::fromValue(is_scalar($value) ? $value : null);
            }

            $cells[] = Cell::fromValue(implode("\n", $row->errors), $red);
            $cells[] = Cell::fromValue(implode("\n", $row->warnings), $amber);

            $writer->addRow(new Row($cells));
        }

        $writer->close();
    }
}
