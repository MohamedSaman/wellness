{{-- Stock alerts (App\Filament\Widgets\Clinic\StockAlerts): expiring and expired batches, items out of stock. --}}
<x-filament-widgets::widget>
    <div class="pos-card pos-card-alert flex flex-col gap-4" data-test="stock-alerts">
        <div class="flex items-center justify-between gap-3">
            <h3 class="pos-card-title">Stock alerts</h3>
            @if ($allUrl)
                <x-filament::link :href="$allUrl" size="sm">View all{{ $total > count($alerts) ? ' ('.$total.')' : '' }}</x-filament::link>
            @endif
        </div>

        @forelse ($alerts as $alert)
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium text-gray-950 dark:text-white">{{ $alert['item'] }}</p>
                    <p class="pos-muted text-xs">{{ $alert['detail'] }} · {{ $alert['location'] }}</p>
                </div>
                <span @class(['pos-chip shrink-0', 'pos-chip-danger' => $alert['priority'] === 'High', 'pos-chip-warning' => $alert['priority'] !== 'High'])>{{ $alert['priority'] }}</span>
            </div>
        @empty
            <p class="pos-muted text-sm">Nothing expiring within {{ \App\Services\ClinicDashboardService::EXPIRY_DAYS }} days and nothing out of stock.</p>
        @endforelse
    </div>
</x-filament-widgets::widget>
