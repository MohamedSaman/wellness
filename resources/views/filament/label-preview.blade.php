{{-- Label templates: sample labels as LabelLayout lays them out with the form's values, at twice their size. Inline styles: Filament's CSS has no classes for this. --}}
@php
    $width = (float) $template->label_width;
    $height = (float) $template->label_height;
    $number = fn (float $value, int $decimals = 2): string => rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.');
@endphp
@include('labels.partials.styles')
<div data-test="label-preview" style="display: flex; flex-direction: column; gap: 1rem;">
    @if ($problems !== [])
        <div data-test="label-preview-problems" style="padding: 0.5rem 0.75rem; border: 1px solid #dc2626; border-radius: 0.25rem; color: #b91c1c; font-size: 0.875rem;">
            <strong>Can't be saved like this:</strong>
            <ul style="margin: 0.25rem 0 0; padding-left: 1.25rem; list-style: disc;">
                @foreach ($problems as $problem)
                    <li>{{ $problem }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    @if ($width >= 5 && $height >= 5)
        <div style="display: flex; flex-wrap: wrap; gap: 1.5rem; align-items: flex-start;">
            @foreach ($labels as $label)
                <figure style="margin: 0;">
                    <div style="position: relative; width: {{ $width * 2 }}mm; height: {{ $height * 2 }}mm; background: #fff;">
                        <div style="position: absolute; left: 0; top: 0; width: {{ $width }}mm; height: {{ $height }}mm; transform: scale(2); transform-origin: top left;">
                            @include('labels.partials.label', ['label' => $label, 'template' => $template, 'left' => 0, 'top' => 0])
                        </div>
                    </div>
                    <figcaption style="margin-top: 0.5rem; font-size: 0.75rem; color: #6b7280; max-width: {{ max(60, $width * 2) }}mm;">
                        @if ($label['module'] !== null)
                            Bars {{ $number($label['module'], 3) }} mm ({{ (int) round($label['module'] / $dot) }} dots at {{ $template->dpi }} dpi), {{ $number($label['bar_width'], 1) }} × {{ $number($label['bar_height'], 1) }} mm.
                        @endif
                        Smallest text {{ $number($label['smallest_pt'], 1) }} pt.
                    </figcaption>
                </figure>
            @endforeach
        </div>
    @endif
</div>
