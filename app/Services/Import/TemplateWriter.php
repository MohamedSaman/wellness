<?php

namespace App\Services\Import;

use App\Models\Shop;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The downloadable template of an import (one per type): the data sheet
 * with the column headings and three example rows, an Instructions sheet
 * (what each column takes, which are required, the allowed values), and a
 * hidden Lists sheet that feeds the Excel dropdowns of columns with fixed
 * values. Codes and dates are text columns, so Excel leaves "03/2027" and
 * "0012345" as typed. The example rows are recognised and skipped if they
 * are left in.
 */
final class TemplateWriter
{
    private const HEADER_FILL = 'FFE8A3';

    /**
     * @param  list<array<string, string|int|float|null>>|null  $rows  rows to write instead of the examples (a filled-in sample), field key => value
     */
    public static function write(ImportDefinition $definition, Shop $shop, string $path, int $maxRows, ?array $rows = null): void
    {
        $fields = $definition->fields($shop);
        $lists = array_values(array_filter($fields, fn (ImportField $field): bool => $field->options !== null && $field->options !== []));

        $writer = new Writer;
        $writer->openToFile($path);

        // The data sheet, first so the file opens on it and a filled-in copy imports as it is.
        $sheet = $writer->getCurrentSheet();
        $sheet->setName(mb_substr($definition->sheetName($shop), 0, 31));
        $sheet->setSheetView((new SheetView)->setFreezeRow(2));

        foreach ($fields as $index => $field) {
            $sheet->setColumnWidth($field->width, $index + 1);
        }

        $header = (new Style)->setFontBold()->setBackgroundColor(self::HEADER_FILL)->setShouldWrapText();
        $writer->addRow(new Row(array_map(fn (ImportField $field): Cell => Cell::fromValue($field->label), $fields), $header));

        $example = $rows === null ? (new Style)->setFontItalic()->setFontColor('6B7280') : new Style;
        $exampleText = $rows === null ? (new Style)->setFontItalic()->setFontColor('6B7280')->setFormat('@') : (new Style)->setFormat('@');

        foreach ($rows ?? $definition->examples($shop) as $values) {
            $writer->addRow(new Row(array_map(
                fn (ImportField $field): Cell => Cell::fromValue(self::cellValue($values[$field->key] ?? null, $field), $field->text ? $exampleText : $example),
                $fields,
            )));
        }

        // Instructions.
        $writer->addNewSheetAndMakeItCurrent();
        $instructions = $writer->getCurrentSheet();
        $instructions->setName('Instructions');
        $instructions->setColumnWidth(32, 1);
        $instructions->setColumnWidth(11, 2);
        $instructions->setColumnWidth(70, 3);
        $instructions->setColumnWidth(45, 4);

        $wrap = (new Style)->setShouldWrapText();
        $writer->addRow(new Row([Cell::fromValue($definition->type()->labelFor($shop).' import', (new Style)->setFontBold()->setFontSize(14))]));

        foreach ($definition->instructions($shop) as $line) {
            $writer->addRow(new Row([Cell::fromValue($line)]));
        }

        $writer->addRow(new Row([]));
        $writer->addRow(new Row(array_map(fn (string $text): Cell => Cell::fromValue($text), ['Column', 'Required', 'What to enter', 'Allowed values']), $header));

        foreach ($fields as $field) {
            $writer->addRow(new Row([
                Cell::fromValue($field->label),
                Cell::fromValue($field->required ? 'Yes' : ''),
                Cell::fromValue($field->help),
                Cell::fromValue($field->options === null ? '' : implode(', ', $field->options)),
            ], $wrap));
        }

        // The dropdowns' values, one column per list.
        $writer->addNewSheetAndMakeItCurrent();
        $listSheet = $writer->getCurrentSheet();
        $listSheet->setName('Lists');
        $listSheet->setIsVisible(false);

        $longest = max([0, ...array_map(fn (ImportField $field): int => count($field->options ?? []), $lists)]);
        $writer->addRow(new Row(array_map(fn (ImportField $field): Cell => Cell::fromValue($field->label), $lists)));

        for ($i = 0; $i < $longest; $i++) {
            $writer->addRow(new Row(array_map(fn (ImportField $field): Cell => Cell::fromValue($field->options[$i] ?? null, (new Style)->setFormat('@')), $lists)));
        }

        $writer->close();

        $textColumns = [];
        $dropdowns = [];

        foreach ($fields as $index => $field) {
            if ($field->text) {
                $textColumns[] = $index + 1;
            }

            $listIndex = array_search($field, $lists, true);

            if ($listIndex !== false) {
                $letter = SpreadsheetReader::columnLetter((int) $listIndex);

                $dropdowns[] = [
                    'column' => $index + 1,
                    'firstRow' => 2,
                    'lastRow' => $maxRows + 1,
                    'source' => "Lists!\${$letter}\$2:\${$letter}\$".(count($field->options ?? []) + 1),
                    'strict' => $field->strictList,
                    'title' => $field->label,
                    'prompt' => $field->help,
                ];
            }
        }

        XlsxPatcher::patch($path, 1, $textColumns, $dropdowns);
    }

    /** Codes and dates are written as text (they are typed as text too); numbers stay numbers. */
    private static function cellValue(string|int|float|null $value, ImportField $field): string|int|float|null
    {
        return $value === null || ! $field->text ? $value : (string) $value;
    }
}
