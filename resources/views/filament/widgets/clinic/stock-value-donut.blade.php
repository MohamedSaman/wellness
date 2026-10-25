{{-- Stock value (App\Filament\Widgets\Clinic\StockValueDonut): stock at cost per selling location. cost.view only. --}}
@php
    $circumference = 2 * M_PI * 42;
    $offset = 0.0;
    $largest = collect($locations)->sortByDesc('value')->first();
@endphp
<x-filament-widgets::widget>
    <div class="pos-card flex flex-col gap-4" data-test="stock-value">
        <div>
            <h3 class="pos-card-title">Stock value</h3>
            <p class="pos-muted text-sm">At cost (weighted average)</p>
        </div>

        <div class="relative mx-auto size-36">
            <svg viewBox="0 0 100 100" class="size-full -rotate-90" aria-hidden="true" focusable="false">
                <circle cx="50" cy="50" r="42" fill="none" stroke-width="12" class="pos-ring-track" />
                @if ($total > 0)
                    @foreach ($locations as $index => $row)
                        @php($length = $circumference * $row['value'] / $total)
                        <circle cx="50" cy="50" r="42" fill="none" stroke-width="12"
                            class="{{ $index === 0 ? 'pos-ring-1' : 'pos-ring-2' }}"
                            stroke-dasharray="{{ round($length, 3) }} {{ round($circumference, 3) }}"
                            stroke-dashoffset="{{ round(-$offset, 3) }}" />
                        @php($offset += $length)
                    @endforeach
                @endif
            </svg>
            <div class="absolute inset-0 flex flex-col items-center justify-center text-center">
                @if ($total > 0 && $largest)
                    <span class="text-xl font-semibold text-gray-950 tabular-nums dark:text-white">{{ number_format($largest['share'], 0) }}%</span>
                    <span class="pos-muted text-xs">{{ $largest['code'] }}</span>
                @else
                    <span class="pos-muted text-xs">No stock yet</span>
                @endif
            </div>
        </div>

        <dl class="grid grid-cols-1 gap-2 text-sm">
            @foreach ($locations as $index => $row)
                <div class="pos-soft-primary flex items-center justify-between gap-2 rounded-lg px-3 py-2">
                    <dt class="flex min-w-0 items-center gap-2">
                        <span class="{{ $index === 0 ? 'pos-swatch-1' : 'pos-swatch-2' }} size-2.5 shrink-0 rounded-full" aria-hidden="true"></span>
                        <span class="truncate text-gray-950 dark:text-white">{{ $row['code'] }}</span>
                    </dt>
                    <dd class="font-medium text-gray-950 tabular-nums dark:text-white">{{ \App\Filament\Support\ShopMoney::format($row['value']) }}</dd>
                </div>
            @endforeach
            <div class="pos-soft-primary flex items-center justify-between gap-2 rounded-lg px-3 py-2">
                <dt class="text-gray-950 dark:text-white">Items in stock</dt>
                <dd class="font-medium text-gray-950 tabular-nums dark:text-white">{{ number_format($skus) }}</dd>
            </div>
        </dl>
    </div>
</x-filament-widgets::widget>
