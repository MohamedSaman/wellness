{{--
    The dry run's result on the import page: counts, a note when this file
    was imported before, and the rows with errors or warnings. Inline
    styles: Filament's CSS is precompiled.
--}}
@php
    $tiles = [
        ['Rows', $counts['rows'] ?? 0, 'gray'],
        ['To create', $counts['created'] ?? 0, 'success'],
        ['To update', $counts['updated'] ?? 0, 'info'],
        ['Skipped', $counts['skipped'] ?? 0, 'gray'],
        ['With errors', $counts['errors'] ?? 0, ($counts['errors'] ?? 0) > 0 ? 'danger' : 'gray'],
        ['With warnings', $counts['warnings'] ?? 0, ($counts['warnings'] ?? 0) > 0 ? 'warning' : 'gray'],
        ...(($counts['expiry_months'] ?? 0) > 0 ? [['Expiry read as month', $counts['expiry_months'], 'warning']] : []),
    ];
@endphp
<div data-test="import-preview" style="display: flex; flex-direction: column; gap: 1rem;">
    <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
        @foreach ($tiles as [$label, $value, $color])
            <x-filament::badge :color="$color" size="lg">{{ $label }}: {{ number_format($value) }}</x-filament::badge>
        @endforeach
    </div>

    @if (($counts['errors'] ?? 0) > 0)
        <p style="font-weight: 600; color: rgb(220 38 38);">
            {{ $counts['errors'] }} {{ $counts['errors'] === 1 ? 'row has' : 'rows have' }} errors, so nothing can be imported yet: a file goes in whole or not at all.
            Correct them in your file (the error report lists them, next to your own columns) and upload it again.
        </p>
    @elseif (($counts['created'] ?? 0) + ($counts['updated'] ?? 0) === 0)
        <p style="font-weight: 600;">There is nothing to import: every row is already on file or skipped.</p>
    @else
        <p>Ready to import. Warnings don't stop the import; check them first.</p>
    @endif

    @if (($counts['expiry_months'] ?? 0) > 0)
        <p data-test="expiry-months" style="color: rgb(180 83 9);">
            {{ $counts['expiry_months'] }} {{ $counts['expiry_months'] === 1 ? 'row has an expiry' : 'rows have expiries' }} that Excel stored as a date on the 1st of a month, read as the end of that month (03/2027 → 31/03/2027).
            Check them in the list below or in the error report; if a pack really shows the 1st, type the full date as text (01/03/2027).
        </p>
    @endif

    @if ($before)
        <p style="color: rgb(180 83 9);">{{ $before }}</p>
    @endif

    @if ($issues === null)
        @if ($reportUrl)
            <p>The rows with errors and warnings are in the <x-filament::link :href="$reportUrl">error report</x-filament::link>, or check the file again to list them here.</p>
        @endif
    @elseif ($issues !== [])
        <div style="overflow-x: auto;">
            <table data-test="import-issues" style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                <thead>
                    <tr style="text-align: start; border-bottom: 1px solid rgb(156 163 175 / 0.4);">
                        <th style="padding: 0.5rem; text-align: start; width: 4rem;">Row</th>
                        <th style="padding: 0.5rem; text-align: start;">Errors</th>
                        <th style="padding: 0.5rem; text-align: start;">Warnings</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($issues as $issue)
                        <tr style="border-bottom: 1px solid rgb(156 163 175 / 0.2); vertical-align: top;">
                            <td style="padding: 0.5rem; font-variant-numeric: tabular-nums;">{{ $issue['row'] }}</td>
                            <td style="padding: 0.5rem; color: rgb(220 38 38);">
                                @foreach ($issue['errors'] as $error)
                                    <div>{{ $error }}</div>
                                @endforeach
                            </td>
                            <td style="padding: 0.5rem; color: rgb(180 83 9);">
                                @foreach ($issue['warnings'] as $warning)
                                    <div>{{ $warning }}</div>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if (count($issues) >= $limit && $reportUrl)
            <p>Only the first {{ $limit }} rows with problems are listed; the <x-filament::link :href="$reportUrl">error report</x-filament::link> has them all.</p>
        @endif
    @endif
</div>
