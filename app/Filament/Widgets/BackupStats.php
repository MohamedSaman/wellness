<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\SystemHealth;
use App\Models\User;
use App\Services\HealthService;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The last backup and its check on the dashboard (system.health, the owner;
 * docs/deploy/CLOUD.md): red when there is none in 26 hours or the check
 * failed, and how many health checks fail. Each opens System health. Last,
 * under the figures.
 */
class BackupStats extends StatsOverviewWidget
{
    protected static ?int $sort = 20;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('system.health');
    }

    protected function getColumns(): int|array
    {
        return ['default' => 1, 'sm' => 3];
    }

    protected function getStats(): array
    {
        $checks = collect(app(HealthService::class)->checks())->keyBy('key');
        $url = SystemHealth::getUrl();
        $color = fn (string $status): string => ['ok' => 'success', 'warn' => 'warning', 'fail' => 'danger'][$status] ?? 'gray';
        $failing = $checks->where('status', 'fail')->count();

        return [
            Stat::make('Last backup', $checks['backup']['value'])
                ->description($checks['backup']['status'] === 'ok' ? 'Encrypted, off the server' : (string) $checks['backup']['detail'])
                ->descriptionIcon(Heroicon::OutlinedCloudArrowUp)
                ->color($color($checks['backup']['status']))
                ->url($url),
            Stat::make('Last backup check', $checks['verify']['value'])
                ->description($checks['verify']['status'] === 'ok' ? 'Restored and counted' : (string) $checks['verify']['detail'])
                ->descriptionIcon(Heroicon::OutlinedShieldCheck)
                ->color($color($checks['verify']['status']))
                ->url($url),
            Stat::make('System health', $failing === 0 ? 'All good' : "{$failing} problem".($failing === 1 ? '' : 's'))
                ->description($failing === 0 ? 'Disk, queue, scheduler, settings' : $checks->where('status', 'fail')->pluck('label')->implode(', '))
                ->descriptionIcon(Heroicon::OutlinedHeart)
                ->color($failing === 0 ? 'success' : 'danger')
                ->url($url),
        ];
    }
}
