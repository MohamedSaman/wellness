{{-- Printable documents (purchasing, the A4 sale invoice): A4, black on white, the browser's own print dialog. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · {{ $shop->name }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px; font: 13px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #111; background: #fff; }
        .page { max-width: 800px; margin: 0 auto; }
        header { display: flex; justify-content: space-between; gap: 24px; border-bottom: 2px solid #111; padding-bottom: 12px; margin-bottom: 16px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        h2 { font-size: 16px; margin: 0; text-align: right; text-transform: uppercase; letter-spacing: .04em; }
        .muted { color: #555; }
        .meta { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
        .meta dl { margin: 0; display: grid; grid-template-columns: max-content 1fr; gap: 2px 12px; }
        .meta dt { color: #555; }
        .meta dd { margin: 0; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        th, td { padding: 6px 8px; border-bottom: 1px solid #ccc; text-align: left; vertical-align: top; }
        th { font-size: 11px; text-transform: uppercase; letter-spacing: .03em; color: #555; border-bottom: 1px solid #111; }
        .num { text-align: right; white-space: nowrap; }
        .small { font-size: 11px; }
        .totals { margin-left: auto; width: 320px; }
        .totals td { border: 0; padding: 3px 8px; }
        .totals tr.grand td { border-top: 2px solid #111; font-weight: 700; font-size: 15px; }
        .banner { border: 2px solid #111; padding: 8px 12px; margin-bottom: 16px; font-weight: 700; }
        .notes { white-space: pre-line; margin-bottom: 16px; }
        .signatures { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; margin-top: 48px; }
        .signatures div { border-top: 1px solid #111; padding-top: 4px; color: #555; font-size: 11px; }
        .toolbar { max-width: 800px; margin: 0 auto 16px; text-align: right; }
        .toolbar button { font: inherit; padding: 6px 14px; cursor: pointer; }
        @media print {
            body { padding: 0; }
            .toolbar { display: none; }
            @page { size: A4; margin: 14mm; }
        }
    </style>
</head>
<body>
    <div class="toolbar"><button type="button" onclick="window.print()">Print</button></div>
    <div class="page">
        <header>
            <div>
                <h1>{{ $shop->name }}</h1>
                <div class="muted">
                    @if ($shop->address) {{ $shop->address }}<br> @endif
                    @if ($shop->phone) {{ $shop->phone }} @endif
                    @if ($shop->email) · {{ $shop->email }} @endif
                    @if ($shop->tax_number) <br>Tax no. {{ $shop->tax_number }} @endif
                </div>
            </div>
            <div>
                <h2>@yield('title')</h2>
                <div class="muted" style="text-align: right">@yield('number')</div>
            </div>
        </header>

        @yield('content')
    </div>
    @if ($autoPrint)
        <script>window.addEventListener('load', () => window.print());</script>
    @endif
</body>
</html>
