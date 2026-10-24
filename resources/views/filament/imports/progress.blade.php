{{--
    An import being written (polled every 2 seconds while it is queued or
    running), and then its result. Inline styles: Filament's CSS is
    precompiled.
--}}
@php
    use App\Enums\ImportStatus;

    $active = $run?->status->isActive() ?? false;
    $total = max(1, (int) ($run?->total_rows ?? 0));
    $percent = min(100, (int) floor($done * 100 / $total));
@endphp
<div data-test="import-progress" @if ($active) wire:poll.2s="refreshProgress" @endif style="display: flex; flex-direction: column; gap: 0.75rem;">
    @if ($run?->status === ImportStatus::Queued)
        <p>Waiting for the import to start. It runs in the background: this page updates by itself, and you can leave it and come back from the import log.</p>
        <p style="font-size: 0.875rem; color: rgb(107 114 128);">If it stays here, the queue worker is not running on the server (<code>php artisan queue:work</code>).</p>
    @elseif ($run?->status === ImportStatus::Running)
        <p>Importing row {{ number_format(min($done, $total)) }} of {{ number_format($total) }}…</p>
        <div style="height: 0.5rem; border-radius: 9999px; background: rgb(156 163 175 / 0.3); overflow: hidden;">
            <div style="height: 100%; width: {{ $percent }}%; background: rgb(245 158 11);"></div>
        </div>
        <p style="font-size: 0.875rem; color: rgb(107 114 128);">Nothing is saved until the last row is in: if it stops, nothing was imported.</p>
    @elseif ($run?->status === ImportStatus::Completed)
        <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
            <x-filament::badge color="success" size="lg">Created: {{ number_format($counts['created'] ?? 0) }}</x-filament::badge>
            <x-filament::badge color="info" size="lg">Updated: {{ number_format($counts['updated'] ?? 0) }}</x-filament::badge>
            <x-filament::badge color="gray" size="lg">Skipped: {{ number_format($counts['skipped'] ?? 0) }}</x-filament::badge>
        </div>
        <p>Imported {{ $run->finished_at?->diffForHumans() }} by {{ $run->user->name ?? 'someone' }}.</p>
        @if ($reportUrl)
            <p>The <x-filament::link :href="$reportUrl">error report</x-filament::link> lists the rows skipped and the warnings.</p>
        @endif
    @elseif ($run?->status === ImportStatus::Failed)
        <p style="font-weight: 600; color: rgb(220 38 38);">{{ $run->message }}</p>
        <p>Check the file again above (or upload a corrected one), then confirm.</p>
    @endif
</div>
