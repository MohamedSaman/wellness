{{-- Location performance (App\Filament\Widgets\Clinic\LocationPerformance): this month's share per counter and the top categories. --}}
<x-filament-widgets::widget>
    <div class="pos-card flex flex-col gap-5" data-test="location-performance">
        <div>
            <h3 class="pos-card-title">Location performance</h3>
            <p class="pos-muted text-sm">This month's net sales: {{ \App\Filament\Support\ShopMoney::format($total) }}</p>
        </div>

        <div class="space-y-4">
            @forelse ($locations as $row)
                <div class="space-y-1.5">
                    <div class="flex items-baseline justify-between gap-3 text-sm">
                        <span class="truncate font-medium text-gray-950 dark:text-white">{{ $row['code'] }} · {{ $row['location'] }}</span>
                        <span class="pos-muted shrink-0 tabular-nums">{{ number_format($row['share'], 1) }}%</span>
                    </div>
                    <div class="pos-bar" role="progressbar" aria-valuenow="{{ $row['share'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ $row['location'] }}"><span style="width: {{ $row['share'] }}%"></span></div>
                    <p class="pos-muted text-xs tabular-nums">{{ \App\Filament\Support\ShopMoney::format($row['net']) }}</p>
                </div>
            @empty
                <p class="pos-muted text-sm">No selling counters.</p>
            @endforelse
        </div>

        <div class="space-y-3 border-t border-gray-950/5 pt-4 dark:border-white/10">
            <h4 class="text-sm font-semibold text-gray-950 dark:text-white">Top categories</h4>

            @forelse ($categories as $row)
                <div class="space-y-1.5">
                    <div class="flex items-baseline justify-between gap-3 text-sm">
                        <span class="truncate text-gray-950 dark:text-white">{{ $row['name'] }}</span>
                        <span class="pos-muted shrink-0 tabular-nums">{{ number_format($row['share'], 1) }}%</span>
                    </div>
                    <div class="pos-bar" role="progressbar" aria-valuenow="{{ $row['share'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ $row['name'] }}"><span style="width: {{ $row['share'] }}%"></span></div>
                </div>
            @empty
                <p class="pos-muted text-sm">No sales this month yet.</p>
            @endforelse
        </div>
    </div>
</x-filament-widgets::widget>
