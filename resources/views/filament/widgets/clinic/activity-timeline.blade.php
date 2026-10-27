{{-- Activity (App\Filament\Widgets\Clinic\ActivityTimeline): the latest sales and stock events. --}}
<x-filament-widgets::widget>
    <div class="pos-card flex flex-col gap-4" data-test="activity">
        <h3 class="pos-card-title">Activity</h3>

        @forelse ($events as $event)
            <div class="flex gap-3">
                <div class="flex flex-col items-center" aria-hidden="true">
                    <span @class(['mt-1 size-2.5 shrink-0 rounded-full', 'pos-dot-danger' => $event['kind'] === 'void', 'pos-dot-primary' => $event['kind'] !== 'void'])></span>
                    @unless ($loop->last)
                        <span class="pos-timeline-line mt-1 w-px flex-1"></span>
                    @endunless
                </div>
                <div class="flex min-w-0 flex-1 items-start justify-between gap-3 pb-1">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-gray-950 dark:text-white">{{ $event['title'] }}</p>
                        @if ($event['detail'] !== '')
                            <p class="pos-muted truncate text-xs">{{ $event['detail'] }}</p>
                        @endif
                    </div>
                    <time class="pos-muted shrink-0 text-xs tabular-nums" datetime="{{ $event['at'] }}">{{ $event['time'] }}</time>
                </div>
            </div>
        @empty
            <p class="pos-muted text-sm">Nothing has happened yet: sales, stock received and transfers show here.</p>
        @endforelse
    </div>
</x-filament-widgets::widget>
