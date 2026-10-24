<?php

namespace App\Services\Import;

/**
 * What openspout can't write into a worksheet, patched into the saved
 * .xlsx: Excel dropdowns (data validation lists) and a column-wide cell
 * format, so a code or an expiry typed into an empty template row stays
 * text ("03/2027" is a month, not 1 March; a barcode keeps its leading
 * zeros). Plain SpreadsheetML, written where the schema puts it.
 */
final class XlsxPatcher
{
    /** Elements that come after dataValidations in a worksheet (ECMA-376, CT_Worksheet). */
    private const AFTER_VALIDATIONS = ['<hyperlinks', '<printOptions', '<pageMargins', '<pageSetup', '<headerFooter', '<rowBreaks', '<colBreaks', '<customProperties', '<cellWatches', '<ignoredErrors', '<smartTags', '<drawing', '<legacyDrawing', '<picture', '<oleObjects', '<controls', '<webPublishItems', '<tableParts', '<extLst', '</worksheet>'];

    /**
     * @param  int  $sheet  1 for the first sheet
     * @param  list<int>  $textColumns  1-based columns formatted as text
     * @param  list<array{column: int, firstRow: int, lastRow: int, source: string, strict: bool, title: string, prompt: string}>  $lists  dropdowns; source is a range such as Lists!$A$2:$A$12
     */
    public static function patch(string $path, int $sheet, array $textColumns, array $lists): void
    {
        $zip = new \ZipArchive;

        if ($zip->open($path) !== true) {
            throw new \RuntimeException("Could not open {$path}.");
        }

        $name = "xl/worksheets/sheet{$sheet}.xml";
        $xml = $zip->getFromName($name);

        if ($xml === false) {
            $zip->close();

            throw new \RuntimeException("{$path} has no {$name}.");
        }

        if ($textColumns !== []) {
            $styles = (string) $zip->getFromName('xl/styles.xml');
            [$styles, $textStyle] = self::textStyle($styles);
            $zip->addFromString('xl/styles.xml', $styles);
            $xml = self::columnStyles($xml, $textColumns, $textStyle);
        }

        $xml = self::validations($xml, $lists);

        $zip->addFromString($name, $xml);
        $zip->close();
    }

    /**
     * Adds a plain cell format "Text" (number format 49, "@") to the
     * workbook's styles.
     *
     * @return array{0: string, 1: int} the styles and the new format's index
     */
    private static function textStyle(string $styles): array
    {
        if (preg_match('/<cellXfs count="(\d+)">/', $styles, $m) !== 1) {
            throw new \RuntimeException('The workbook has no cell formats.');
        }

        $index = (int) $m[1];
        $styles = str_replace($m[0], '<cellXfs count="'.($index + 1).'">', $styles);
        $styles = str_replace('</cellXfs>', '<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>', $styles);

        return [$styles, $index];
    }

    /** @param  list<int>  $columns */
    private static function columnStyles(string $xml, array $columns, int $style): string
    {
        foreach ($columns as $column) {
            $xml = (string) preg_replace_callback(
                '/<col min="'.$column.'" max="'.$column.'"([^>]*?)\/>/',
                fn (array $col): string => str_contains($col[1], 'style=') ? $col[0] : '<col min="'.$column.'" max="'.$column.'"'.$col[1].' style="'.$style.'"/>',
                $xml,
                1,
            );
        }

        return $xml;
    }

    /** @param  list<array{column: int, firstRow: int, lastRow: int, source: string, strict: bool, title: string, prompt: string}>  $lists */
    private static function validations(string $xml, array $lists): string
    {
        if ($lists === []) {
            return $xml;
        }

        $items = '';

        foreach ($lists as $list) {
            $letter = SpreadsheetReader::columnLetter($list['column'] - 1);

            $items .= sprintf(
                '<dataValidation type="list" errorStyle="%s" allowBlank="1" showInputMessage="1" showErrorMessage="1" errorTitle="%s" error="%s" promptTitle="%s" prompt="%s" sqref="%s%d:%s%d"><formula1>%s</formula1></dataValidation>',
                $list['strict'] ? 'stop' : 'warning',
                self::attr('Not in the list'),
                self::attr($list['strict'] ? 'Choose one of the values in the list.' : 'This is not in the list. Keep it only if you are sure.'),
                self::attr(mb_substr($list['title'], 0, 32)),
                self::attr(mb_substr($list['prompt'], 0, 250)),
                $letter, $list['firstRow'], $letter, $list['lastRow'],
                htmlspecialchars($list['source'], ENT_XML1),
            );
        }

        $block = '<dataValidations count="'.count($lists).'">'.$items.'</dataValidations>';

        foreach (self::AFTER_VALIDATIONS as $tag) {
            $at = strpos($xml, $tag);

            if ($at !== false) {
                return substr($xml, 0, $at).$block.substr($xml, $at);
            }
        }

        return $xml.$block;
    }

    private static function attr(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES);
    }
}
