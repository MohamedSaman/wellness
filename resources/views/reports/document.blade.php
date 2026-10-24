{{--
    A report as a document (docs/PHARMACY.md §9, branch 11): the print page
    and, rendered by dompdf, the PDF. Tables only, no flex or grid, so both
    lay it out the same; black on white; table headings repeat on each page.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $document->title }} · {{ $shop->name }}</title>
    <style>
        @page { size: A4 {{ $landscape ? 'landscape' : 'portrait' }}; margin: 12mm; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: {{ $pdf ? '0' : '24px' }}; font-family: {{ $pdf ? '"DejaVu Sans", sans-serif' : 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif' }}; font-size: {{ $pdf ? '9px' : '12px' }}; line-height: 1.4; color: #111; background: #fff; }
        .page { max-width: {{ $landscape ? '1100px' : '800px' }}; margin: 0 auto; }
        table.head { width: 100%; border-bottom: 2px solid #111; margin-bottom: 10px; }
        table.head td { vertical-align: top; padding: 0 0 8px; }
        h1 { font-size: {{ $pdf ? '15px' : '19px' }}; margin: 0 0 2px; }
        h2 { font-size: {{ $pdf ? '11px' : '14px' }}; margin: 16px 0 6px; }
        .muted { color: #555; }
        table.meta { margin-bottom: 8px; }
        table.meta td { padding: 1px 12px 1px 0; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        table.data thead { display: table-header-group; }
        table.data tr { page-break-inside: avoid; }
        table.data th, table.data td { padding: {{ $pdf ? '3px 5px' : '5px 7px' }}; border-bottom: 1px solid #ccc; text-align: left; vertical-align: top; }
        table.data th { font-size: {{ $pdf ? '8px' : '10px' }}; text-transform: uppercase; letter-spacing: .03em; color: #444; border-bottom: 1px solid #111; }
        table.data td.num, table.data th.num { text-align: right; white-space: nowrap; }
        table.data tr.total td { border-top: 2px solid #111; border-bottom: 0; font-weight: bold; }
        .note { margin: 4px 0 10px; }
        .empty { padding: 6px 0 10px; }
        .toolbar { max-width: {{ $landscape ? '1100px' : '800px' }}; margin: 0 auto 12px; text-align: right; }
        .toolbar button { font: inherit; padding: 6px 14px; cursor: pointer; }
        @media print {
            body { padding: 0; }
            .toolbar { display: none; }
        }
    </style>
</head>
<body>
    @unless ($pdf)
        <div class="toolbar"><button type="button" onclick="window.print()">Print</button></div>
    @endunless
    <div class="page">
        <table class="head">
            <tr>
                <td>
                    <h1>{{ $document->title }}</h1>
                    <div class="muted">{{ $shop->name }}@if ($shop->address) · {{ $shop->address }}@endif</div>
                </td>
                <td style="text-align: right" class="muted">
                    Printed {{ $generatedAt }}<br>
                    Amounts in {{ $shop->currency }}
                </td>
            </tr>
        </table>

        @if ($document->meta !== [])
            <table class="meta">
                @foreach ($document->meta as [$label, $value])
                    <tr><td class="muted">{{ $label }}</td><td>{{ $value }}</td></tr>
                @endforeach
            </table>
        @endif

        @foreach ($document->tables as $table)
            @if ($table->title !== null)
                <h2>{{ $table->title }}</h2>
            @endif

            @if ($table->rows === [])
                <div class="empty muted">Nothing in this period.</div>
            @else
                @php
                    $rows = $maxRows === null ? $table->rows : array_slice($table->rows, 0, $maxRows);
                @endphp
                <table class="data">
                    <thead>
                        <tr>
                            @foreach ($table->columns as $column)
                                <th @class(['num' => $column->isNumeric()])>{{ $column->label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                @foreach ($table->columns as $column)
                                    <td @class(['num' => $column->isNumeric()])>{{ $exporter->display($column, $row[$column->key] ?? null) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                        @if ($table->total !== null)
                            <tr class="total">
                                @foreach ($table->columns as $column)
                                    <td @class(['num' => $column->isNumeric()])>{{ $exporter->display($column, $table->total[$column->key] ?? null) }}</td>
                                @endforeach
                            </tr>
                        @endif
                    </tbody>
                </table>
                @if ($maxRows !== null && count($table->rows) > $maxRows)
                    <div class="note muted">The first {{ number_format($maxRows) }} of {{ number_format(count($table->rows)) }} rows; the total covers them all. Export to Excel for every row.</div>
                @endif
            @endif

            @if ($table->note !== null)
                <div class="note muted">{{ $table->note }}</div>
            @endif
        @endforeach
    </div>
    @if ($autoPrint)
        <script>window.addEventListener('load', () => window.print());</script>
    @endif
</body>
</html>
