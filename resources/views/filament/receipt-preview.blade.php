{{-- Receipt settings: the sample receipts as the thermal printer prints them (ReceiptLayout lines). Inline styles: Filament's CSS has no classes for this. --}}
<div style="display: flex; flex-wrap: wrap; gap: 1.5rem;" data-test="receipt-preview">
    @foreach ($receipts as $title => $lines)
        <figure style="margin: 0;">
            <figcaption style="margin-bottom: 0.5rem; font-size: 0.875rem; font-weight: 500;">{{ $title }}</figcaption>
            <pre style="margin: 0; padding: 0.75rem; overflow-x: auto; border: 1px solid #d1d5db; border-radius: 0.25rem; background: #fff; color: #000; font-family: 'Courier New', 'DejaVu Sans Mono', monospace; font-size: 12px; line-height: 1.35; width: calc({{ $columns }}ch + 1.5rem + 2px); white-space: pre;">@foreach ($lines as $line)<span style="{{ $line['bold'] ? 'font-weight: 700;' : '' }}{{ $line['tall'] ? ' display: inline-block; transform: scaleY(1.6); transform-origin: top; margin-bottom: 0.6em;' : '' }}">{{ $line['text'] }}</span>
@endforeach</pre>
        </figure>
    @endforeach
</div>
