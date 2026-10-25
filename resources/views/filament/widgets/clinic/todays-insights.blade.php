{{-- Today's insights (App\Filament\Widgets\Clinic\TodaysInsights): plain rules over today's figures. --}}
<x-filament-widgets::widget>
    <div class="pos-card flex flex-col gap-4" data-test="todays-insights">
        <div class="flex items-center justify-between gap-3">
            <h3 class="pos-card-title">Today's insights</h3>
            <x-filament::link :href="$reportsUrl" size="sm">View reports</x-filament::link>
        </div>

        <ul class="space-y-3">
            @foreach ($insights as $insight)
                <li class="flex gap-3 text-sm text-gray-950 dark:text-white">
                    <span @class([
                        'mt-1.5 size-2 shrink-0 rounded-full',
                        'pos-dot-danger' => $insight['tone'] === 'danger',
                        'pos-dot-warning' => $insight['tone'] === 'warning',
                        'pos-dot-primary' => $insight['tone'] === 'primary',
                    ]) aria-hidden="true"></span>
                    <span>{{ $insight['text'] }}</span>
                </li>
            @endforeach
        </ul>
    </div>
</x-filament-widgets::widget>
