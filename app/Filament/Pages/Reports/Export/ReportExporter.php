<?php

namespace App\Filament\Pages\Reports\Export;

use App\Filament\Pages\Reports\ReportColumn;
use App\Models\Shop;
use App\Support\CurrentShop;
use App\Support\Quantity;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Contracts\View\View;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Writes a ReportDocument as an Excel workbook (one sheet per table, money
 * as numbers in the shop's currency, a header with what the report
 * covers), a PDF (A4, landscape for wide tables) or an HTML page for the
 * browser's print dialog (docs/PHARMACY.md §9, branch 11). Money is stored
 * in minor units; only the written file holds major units.
 */
class ReportExporter
{
    /** A PDF table stops here (Excel has every row): dompdf lays out a page at a time in memory. */
    public const MAX_PDF_ROWS = 2000;

    public function __construct(private readonly CurrentShop $currentShop) {}

    public function xlsxDownload(ReportDocument $document): StreamedResponse
    {
        $contents = $this->xlsx($document);

        return response()->streamDownload(function () use ($contents): void {
            echo $contents;
        }, $document->filename.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function pdfDownload(ReportDocument $document): StreamedResponse
    {
        $contents = $this->pdf($document);

        return response()->streamDownload(function () use ($contents): void {
            echo $contents;
        }, $document->filename.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    /** The workbook's bytes. */
    public function xlsx(ReportDocument $document): string
    {
        $shop = $this->shop();
        $decimals = (int) $shop->currency_decimals;
        $path = tempnam(sys_get_temp_dir(), 'report');

        if ($path === false) {
            throw new \RuntimeException('Could not create a temporary file for the report.');
        }

        $writer = new Writer;
        $writer->openToFile($path);

        $title = (new Style)->setFontBold()->setFontSize(14);
        $muted = (new Style)->setFontColor('555555');
        $heading = (new Style)->setFontBold()->setBackgroundColor('E5E7EB')->setShouldWrapText();
        $bold = (new Style)->setFontBold();
        $names = [];

        foreach ($document->tables === [] ? [new ReportTable(null, [], [])] : $document->tables as $index => $table) {
            if ($index > 0) {
                $writer->addNewSheetAndMakeItCurrent();
            }

            $writer->getCurrentSheet()->setName($this->sheetName($table->title ?? $document->title, $names));

            $writer->addRow(new Row([Cell::fromValue($document->title, $title)]));
            $writer->addRow(new Row([Cell::fromValue($shop->name, $muted)]));

            foreach ($document->meta as [$label, $value]) {
                $writer->addRow(new Row([Cell::fromValue($label, $muted), Cell::fromValue($value)]));
            }

            $writer->addRow(new Row([Cell::fromValue('Amounts in', $muted), Cell::fromValue((string) $shop->currency)]));
            $writer->addRow(new Row([]));

            if ($table->title !== null && count($document->tables) > 1) {
                $writer->addRow(new Row([Cell::fromValue($table->title, $bold)]));
            }

            if ($table->columns === []) {
                continue;
            }

            $writer->addRow(new Row(array_map(fn (ReportColumn $column): Cell => Cell::fromValue($column->label), $table->columns), $heading));

            foreach ($table->rows as $row) {
                $writer->addRow(new Row($this->cells($table->columns, $row, $decimals, null)));
            }

            if ($table->total !== null) {
                $writer->addRow(new Row($this->cells($table->columns, $table->total, $decimals, $bold)));
            }

            if ($table->rows === []) {
                $writer->addRow(new Row([Cell::fromValue('Nothing in this period', $muted)]));
            }

            if ($table->note !== null) {
                $writer->addRow(new Row([]));
                $writer->addRow(new Row([Cell::fromValue($table->note, $muted)]));
            }
        }

        $writer->close();
        $contents = (string) file_get_contents($path);
        @unlink($path);

        return $contents;
    }

    /**
     * @param  list<ReportColumn>  $columns
     * @param  array<string, mixed>  $row
     * @return list<Cell>
     */
    private function cells(array $columns, array $row, int $decimals, ?Style $style): array
    {
        $money = (new Style)->setFormat($decimals > 0 ? '#,##0.'.str_repeat('0', $decimals) : '#,##0');
        $quantity = (new Style)->setFormat('#,##0.###');
        $percent = (new Style)->setFormat('0.0');

        if ($style !== null) {
            foreach ([$money, $quantity, $percent] as $numberStyle) {
                $numberStyle->setFontBold();
            }
        }

        return array_map(function (ReportColumn $column) use ($row, $decimals, $style, $money, $quantity, $percent): Cell {
            $value = $row[$column->key] ?? null;

            if ($value === null || $value === '') {
                return Cell::fromValue('', $style);
            }

            return match (true) {
                ! is_numeric($value) => Cell::fromValue((string) $value, $style),
                // Major units only in the file: the stored figure is an exact integer of minor units.
                $column->type === ReportColumn::MONEY => Cell::fromValue((int) $value / 10 ** $decimals, $money),
                $column->type === ReportColumn::QUANTITY => Cell::fromValue(round((float) $value, 3), $quantity),
                $column->type === ReportColumn::PERCENT => Cell::fromValue(round((float) $value, 1), $percent),
                $column->type === ReportColumn::INTEGER => Cell::fromValue((int) $value, $style),
                default => Cell::fromValue((string) $value, $style),
            };
        }, $columns);
    }

    /** @param  list<string>  $taken */
    private function sheetName(string $title, array &$taken): string
    {
        // Excel refuses \ / ? * : [ ] in a sheet name, and more than 31 characters.
        $name = mb_substr(trim((string) preg_replace(['/[\\\\\/?*:\[\]]+/', '/\s+/'], ' ', $title)), 0, 28) ?: 'Report';
        $unique = $name;

        for ($i = 2; in_array(mb_strtolower($unique), $taken, true); $i++) {
            $unique = mb_substr($name, 0, 27).' '.$i;
        }

        $taken[] = mb_strtolower($unique);

        return $unique;
    }

    /** The PDF's bytes. */
    public function pdf(ReportDocument $document): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setTempDir(sys_get_temp_dir());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($document, pdf: true)->render());
        $dompdf->setPaper('A4', $this->isWide($document) ? 'landscape' : 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /** The print page (and the PDF's source). */
    public function html(ReportDocument $document, bool $pdf = false, bool $autoPrint = false): View
    {
        $shop = $this->shop();

        return view('reports.document', [
            'shop' => $shop,
            'document' => $document,
            'pdf' => $pdf,
            'autoPrint' => $autoPrint,
            'landscape' => $this->isWide($document),
            'exporter' => $this,
            'maxRows' => $pdf ? self::MAX_PDF_ROWS : null,
            'generatedAt' => now()->setTimezone((string) ($shop->timezone ?: config('app.timezone')))->format('d/m/Y H:i'),
        ]);
    }

    private function isWide(ReportDocument $document): bool
    {
        return collect($document->tables)->contains(fn (ReportTable $table): bool => count($table->columns) > 7);
    }

    /** A cell as printed: money with the shop's decimals and no currency (the header says it), quantities without trailing zeros. */
    public function display(ReportColumn $column, mixed $value): string
    {
        if ($value === null || $value === '') {
            return $column->isNumeric() ? '-' : '';
        }

        if (! is_numeric($value)) {
            return (string) $value;
        }

        $decimals = (int) $this->shop()->currency_decimals;

        return match ($column->type) {
            ReportColumn::MONEY => number_format((int) $value / 10 ** $decimals, $decimals),
            ReportColumn::QUANTITY => Quantity::format((float) $value),
            ReportColumn::PERCENT => number_format((float) $value, 1).'%',
            ReportColumn::INTEGER => number_format((int) $value),
            default => (string) $value,
        };
    }

    private function shop(): Shop
    {
        return $this->currentShop->getOrFail();
    }
}
