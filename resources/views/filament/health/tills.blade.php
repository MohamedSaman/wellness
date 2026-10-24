{{-- System health: HealthService::tills(). Inline styles: Filament's CSS is precompiled. --}}
@php
    $colors = ['ok' => 'success', 'warn' => 'warning', 'info' => 'gray'];
    $offset = function (?int $seconds): string {
        if ($seconds === null) {
            return '–';
        }
        if (abs($seconds) < 60) {
            return 'On time';
        }

        return ($seconds > 0 ? 'Ahead ' : 'Behind ').\Carbon\CarbonInterval::seconds(abs($seconds))->cascade()->forHumans(['short' => true, 'parts' => 2]);
    };
@endphp
@if ($tills === [])
    <p style="color: rgb(107 114 128);">No tills.</p>
@else
    <table data-test="health-tills" style="width: 100%; font-size: 0.875rem; border-collapse: collapse;">
        <thead>
            <tr style="text-align: left; color: rgb(107 114 128);">
                <th style="padding: 0.25rem 1rem 0.25rem 0; font-weight: 500;">Till</th>
                <th style="padding: 0.25rem 1rem 0.25rem 0; font-weight: 500;">Last sync</th>
                <th style="padding: 0.25rem 1rem 0.25rem 0; font-weight: 500;">Last offline sale received</th>
                <th style="padding: 0.25rem 1rem 0.25rem 0; font-weight: 500;">Offline sales waiting</th>
                <th style="padding: 0.25rem 0; font-weight: 500;">Clock</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($tills as $till)
                <tr data-till="{{ $till['code'] }}" style="border-top: 1px solid rgb(156 163 175 / 0.3);">
                    <td style="padding: 0.5rem 1rem 0.5rem 0;">
                        <x-filament::badge :color="$colors[$till['status']]">{{ $till['code'] }}</x-filament::badge>
                        {{ $till['name'] }} <span style="color: rgb(107 114 128);">{{ $till['location'] }}</span>
                    </td>
                    <td style="padding: 0.5rem 1rem 0.5rem 0;">{{ $till['last_seen']?->diffForHumans() ?? 'Never (not registered for offline sales)' }}</td>
                    <td style="padding: 0.5rem 1rem 0.5rem 0;">{{ $till['last_sale_synced']?->diffForHumans() ?? '–' }}</td>
                    <td style="padding: 0.5rem 1rem 0.5rem 0;">
                        {{ $till['pending'] ?? '–' }}
                        @if ($till['reported_at'])
                            <span style="color: rgb(107 114 128);">({{ $till['reported_at']->diffForHumans() }})</span>
                        @endif
                    </td>
                    <td style="padding: 0.5rem 0;">{{ $offset($till['clock_offset']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
