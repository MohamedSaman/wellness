{{-- System health: HealthService::checks(). Inline styles: Filament's CSS is precompiled. --}}
@php
    $colors = ['ok' => 'success', 'warn' => 'warning', 'fail' => 'danger', 'info' => 'gray'];
    $labels = ['ok' => 'OK', 'warn' => 'Check', 'fail' => 'Problem', 'info' => 'Info'];
@endphp
<table data-test="health-checks" style="width: 100%; font-size: 0.875rem; border-collapse: collapse;">
    <tbody>
        @foreach ($checks as $check)
            <tr data-check="{{ $check['key'] }}" style="border-top: 1px solid rgb(156 163 175 / 0.3); vertical-align: top;">
                <td style="padding: 0.5rem 1rem 0.5rem 0; font-weight: 500; white-space: nowrap;">{{ $check['label'] }}</td>
                <td style="padding: 0.5rem 1rem 0.5rem 0;"><x-filament::badge :color="$colors[$check['status']]">{{ $labels[$check['status']] }}</x-filament::badge></td>
                <td style="padding: 0.5rem 1rem 0.5rem 0;">{{ $check['value'] }}</td>
                <td style="padding: 0.5rem 0; color: rgb(107 114 128);">{{ $check['detail'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
