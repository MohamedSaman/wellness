{{-- A print of barcode labels (labels module): each page is exactly one label (a roll) or one sheet, for any label printer driver. --}}
@php
    /** @var \App\Models\LabelTemplate $template */
    $sheet = $template->isSheet();
    $pageWidth = $sheet ? (float) $template->page_width : (float) $template->label_width;
    $pageHeight = $sheet ? (float) $template->page_height : (float) $template->label_height;
    $mm = fn (float $value): string => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Labels · {{ $shop->name }}</title>
    @include('labels.partials.styles')
    <style>
        @page { size: {{ $mm($pageWidth) }}mm {{ $mm($pageHeight) }}mm; margin: 0; }
        html, body { margin: 0; padding: 0; }
        body { background: #e5e7eb; font: 13px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #111; }
        .toolbar { position: sticky; top: 0; z-index: 10; display: flex; flex-wrap: wrap; align-items: center; gap: 8px 16px; padding: 10px 16px; background: #fff; border-bottom: 1px solid #d1d5db; }
        .toolbar form { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin: 0; }
        .toolbar button, .toolbar select, .toolbar input, .toolbar a.button { font: inherit; padding: 5px 10px; }
        .toolbar button.primary { font-weight: 600; cursor: pointer; }
        .toolbar input[type=number] { width: 5em; }
        .toolbar .muted, .tips { color: #555; }
        .tips { padding: 8px 16px 0; max-width: 960px; }
        .problems { margin: 8px 16px 0; padding: 8px 12px; border: 1px solid #b91c1c; background: #fef2f2; color: #7f1d1d; max-width: 960px; }
        .problems ul { margin: 4px 0 0; padding-left: 18px; }
        .pages { padding: 16px; }
        .page { position: relative; overflow: hidden; background: #fff; margin: 0 auto 16px; box-shadow: 0 1px 3px rgba(0, 0, 0, .35); break-after: page; page-break-after: always; }
        .page:last-child { break-after: auto; page-break-after: auto; }
        .empty { padding: 32px; text-align: center; }
        @media screen { body.zoomed .pages { zoom: 2; } }
        @media print {
            body { background: #fff; }
            .toolbar, .tips, .problems, .empty { display: none !important; }
            .pages { padding: 0; }
            .page { margin: 0; box-shadow: none; }
        }
    </style>
</head>
<body class="{{ $sheet ? '' : 'zoomed' }}">
    <div class="toolbar">
        <button type="button" class="primary" onclick="window.print()">Print {{ $labels }} {{ \Illuminate\Support\Str::plural('label', $labels) }}</button>
        <button type="button" onclick="document.body.classList.toggle('zoomed')" title="On screen only: labels print at their real size">Zoom</button>
        <form method="get" action="{{ url()->current() }}">
            <input type="hidden" name="back" value="{{ $back }}">
            <label>Template
                <select name="template" onchange="this.form.submit()">
                    @foreach ($templates as $id => $name)
                        <option value="{{ $id }}" @selected($id === (int) $template->getKey())>{{ $name }}</option>
                    @endforeach
                </select>
            </label>
            @if ($sheet)
                <label>Start at label
                    <input type="number" name="start" min="1" max="{{ $template->perPage() }}" value="{{ $skip + 1 }}">
                </label>
                <button type="submit">Apply</button>
            @endif
        </form>
        <span class="muted">{{ $print->template_name !== $template->name ? 'Printed before with '.$print->template_name.'. ' : '' }}{{ $template->sizeLabel() }}</span>
        <a class="button" href="{{ $back }}" onclick="if (window.opener) { window.close(); return false; }">Back</a>
    </div>

    <p class="tips">
        In the print dialog choose the {{ $sheet ? 'printer and A4 paper' : 'label printer and a paper size of '.$mm((float) $template->label_width).' × '.$mm((float) $template->label_height).' mm' }},
        margins <strong>None</strong>, scale <strong>100%</strong> (actual size), and no headers and footers.
        @if ($sheet) To use a part-used sheet, set where to start: label 1 is top left, counted across. @endif
    </p>

    @if ($problems !== [])
        <div class="problems" data-test="label-problems">
            <strong>Some labels can't be printed as they should:</strong>
            <ul>
                @foreach ($problems as $problem)
                    <li>{{ $problem }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="pages">
        @forelse ($pages as $page)
            <div class="page" style="width: {{ $mm($pageWidth) }}mm; height: {{ $mm($pageHeight) }}mm;">
                @foreach ($page as $index => $label)
                    @continue($label === null)
                    @php
                        $column = $sheet ? $index % max(1, $template->columns) : 0;
                        $row = $sheet ? intdiv($index, max(1, $template->columns)) : 0;
                    @endphp
                    @include('labels.partials.label', [
                        'label' => $label,
                        'template' => $template,
                        'left' => $sheet ? $mm((float) $template->margin_left + $column * ((float) $template->label_width + (float) $template->gap_x)) : 0,
                        'top' => $sheet ? $mm((float) $template->margin_top + $row * ((float) $template->label_height + (float) $template->gap_y)) : 0,
                    ])
                @endforeach
            </div>
        @empty
            <p class="empty">Nothing to print: the items on this print no longer exist.</p>
        @endforelse
    </div>

    @if ($autoPrint && $labels > 0)
        <script>window.addEventListener('load', () => window.print());</script>
    @endif
</body>
</html>
