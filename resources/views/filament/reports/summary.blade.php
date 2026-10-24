{{--
    The tables of a summary report on screen (SummaryReportPage), each with
    its total row; the exports and the print page show the same tables.
    Inline styles: Filament's CSS is precompiled.
--}}
<div style="display: grid; gap: 1.5rem;" data-test="summary-report">
    @foreach ($tables as $table)
        <x-filament::section :heading="$table->title" compact>
            @if ($table->rows === [])
                <p style="opacity: .7; margin: 0;">Nothing in this period.</p>
            @else
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse; font-size: .875rem;">
                        <thead>
                            <tr>
                                @foreach ($table->columns as $column)
                                    <th style="padding: .5rem .75rem; border-bottom: 1px solid rgba(127,127,127,.35); font-weight: 600; white-space: nowrap; text-align: {{ $column->isNumeric() ? 'end' : 'start' }};">{{ $column->label }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($table->rows as $row)
                                <tr>
                                    @foreach ($table->columns as $column)
                                        <td style="padding: .5rem .75rem; border-bottom: 1px solid rgba(127,127,127,.15); {{ $column->isNumeric() ? 'text-align: end; white-space: nowrap; font-variant-numeric: tabular-nums;' : '' }}">{{ $exporter->display($column, $row[$column->key] ?? null) }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                            @if ($table->total !== null)
                                <tr data-test="summary-total">
                                    @foreach ($table->columns as $column)
                                        <td style="padding: .5rem .75rem; border-top: 2px solid rgba(127,127,127,.5); font-weight: 600; {{ $column->isNumeric() ? 'text-align: end; white-space: nowrap; font-variant-numeric: tabular-nums;' : '' }}">{{ $exporter->display($column, $table->total[$column->key] ?? null) }}</td>
                                    @endforeach
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            @endif
            @if ($table->note !== null)
                <p style="opacity: .7; margin: .75rem 0 0; font-size: .8125rem;">{{ $table->note }}</p>
            @endif
        </x-filament::section>
    @endforeach
</div>
