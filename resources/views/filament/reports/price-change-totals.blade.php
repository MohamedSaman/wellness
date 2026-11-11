{{--
    Totals row for every price change in the filtered range (not just this
    page), aligned with the visible columns. Rendered inside Filament's
    <tfoot><tr>, so it must be table cells. Inline styles: Filament's CSS is
    precompiled.
--}}
@php
    $names = collect($columns)->map(fn ($column) => $column->getName())->values();
@endphp
@foreach ($names as $index => $name)
    <td
        class="fi-ta-cell"
        style="padding: 0.75rem 0.75rem; font-weight: 600; white-space: {{ $index === 0 ? 'normal' : 'nowrap' }}; {{ $name === 'line_difference' ? 'text-align: end;' : '' }}"
        @if ($index === 0) data-test="price-change-totals" @endif
    >
        @if ($index === 0)
            Total ({{ number_format($count) }} {{ $count === 1 ? 'line' : 'lines' }})
        @elseif ($name === 'line_difference')
            {{ $difference }}
        @endif
    </td>
@endforeach
