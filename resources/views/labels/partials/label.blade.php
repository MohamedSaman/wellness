{{-- One label. $label: LabelLayout::lay(); $template: the LabelTemplate; $left / $top: its place on the page in mm. --}}
<div class="pos-label pos-label-outline" data-test="label"
     style="left: {{ $left }}mm; top: {{ $top }}mm; width: {{ (float) $template->label_width }}mm; height: {{ (float) $template->label_height }}mm; padding: {{ (float) $template->padding }}mm;">
    @foreach ($label['blocks'] as $block)
        @if ($block['type'] === 'text')
            <div class="lbl-text lbl-{{ $block['key'] }} {{ $block['bold'] ? 'lbl-bold' : '' }}" style="height: {{ round($block['height'], 3) }}mm; font-size: {{ $block['pt'] }}pt;">
                @foreach ($block['lines'] as $line)
                    <div class="lbl-line">{{ $line }}</div>
                @endforeach
            </div>
        @elseif ($block['type'] === 'barcode')
            <div class="lbl-barcode" style="height: {{ round($block['height'], 3) }}mm;">
                {!! $block['svg'] !!}
                @if ($block['text'] !== null)
                    <div class="lbl-hri" style="font-size: {{ $block['text_pt'] }}pt;">{{ $block['text'] }}</div>
                @endif
            </div>
        @else
            <div class="lbl-split" style="height: {{ round($block['height'], 3) }}mm;">
                <span style="font-size: {{ $block['left_pt'] }}pt;">{{ $block['left'] }}</span>
                @if ($block['right'] !== null)
                    <span class="lbl-right" style="font-size: {{ $block['right_pt'] }}pt;">{{ $block['right'] }}</span>
                @endif
            </div>
        @endif
    @endforeach
</div>
