{{--
    Totals row for every movement in the filtered range (not just this page),
    aligned with the visible columns. Rendered inside Filament's <tfoot><tr>,
    so it must be table cells. Inline styles: Filament's CSS is precompiled.
--}}
@php
    $names = collect($columns)->map(fn ($column) => $column->getName())->values();
    $showsValue = $value !== null && $names->contains('value');
@endphp
@foreach ($names as $index => $name)
    <td
        class="fi-ta-cell"
        style="padding: 0.75rem 0.75rem; font-weight: 600; white-space: {{ $index === 0 ? 'normal' : 'nowrap' }}; {{ in_array($name, ['quantity', 'value'], true) ? 'text-align: end;' : '' }}"
        @if ($index === 0) data-test="movement-totals" @endif
    >
        @if ($index === 0)
            Total ({{ number_format($count) }} {{ $count === 1 ? 'movement' : 'movements' }})
            @if ($value !== null && ! $showsValue)
                <div style="font-weight: 400;">Value at cost {{ $value }}</div>
            @endif
        @elseif ($name === 'quantity')
            <span title="Net change">{{ $quantity }}</span>
        @elseif ($name === 'value' && $value !== null)
            {{ $value }}
        @endif
    </td>
@endforeach
