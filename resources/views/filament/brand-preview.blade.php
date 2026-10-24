{{-- Shop settings → Branding: the shade scale a brand colour gives (BrandPalette), with samples as the panel and the till draw them. Inline styles: Filament's CSS has no classes for this. --}}
@if ($palette === null)
    <p style="font-size: 0.875rem; opacity: 0.75;">No brand colour: the admin panel keeps its standard amber and the till its own colours.</p>
@else
    <div data-test="brand-preview" style="display: grid; gap: 0.75rem;">
        <div style="display: grid; grid-template-columns: repeat(11, minmax(0, 1fr)); gap: 0.25rem;">
            @foreach ($palette->shades as $shade => $hex)
                <div style="min-width: 0; text-align: center;">
                    <div title="{{ $shade }}: {{ $hex }}" style="height: 2rem; border-radius: 0.25rem; background-color: {{ $hex }};{{ $shade === $palette->anchor ? ' outline: 2px solid currentColor; outline-offset: 2px;' : '' }}"></div>
                    <div style="margin-top: 0.25rem; font-size: 0.625rem; opacity: 0.7; overflow: hidden; text-overflow: ellipsis;">{{ $shade }}</div>
                </div>
            @endforeach
        </div>
        <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; font-size: 0.875rem; font-weight: 600;">
            <span style="padding: 0.375rem 0.75rem; border-radius: 0.5rem; background-color: {{ $palette->shades[600] }}; color: {{ $palette->shades[50] }};">Button</span>
            <span style="padding: 0.375rem 0.75rem; border-radius: 0.5rem; background-color: {{ \App\Support\BrandPalette::DARK_SURFACE }}; color: {{ $palette->shades[400] }};">Dark mode text</span>
            <span style="padding: 0.375rem 0.75rem; border-radius: 0.5rem; background-color: {{ $palette->shades[400] }}; color: {{ $palette->shades[950] }};">Till button, dark mode</span>
        </div>
        <p style="font-size: 0.875rem; opacity: 0.75;">
            Your colour is shade {{ $palette->anchor }} (outlined).
            @if ($palette->adjusted === [])
                Every shade reads clearly as it is.
            @else
                Adjusted so text stays readable (WCAG AA, 4.5:1): {{ collect($palette->adjusted)->map(fn (int $shade): string => 'shade '.$shade)->join(', ', ' and ') }}.
            @endif
        </p>
    </div>
@endif
