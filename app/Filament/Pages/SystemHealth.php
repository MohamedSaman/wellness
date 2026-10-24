<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Observers\AuditObserver;
use App\Services\Backup\BackupService;
use App\Services\HealthService;
use App\Support\CurrentShop;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use UnitEnum;

/**
 * Administration → System health (system.health, the owner): the checks of
 * HealthService (release, debug, HTTPS, database, disk, queue, scheduler,
 * last backup and its check, two-factor, mail) and each till's last sync,
 * clock and offline sales waiting; back up now, and download the newest
 * backup (encrypted: it opens only with the offline recovery key).
 */
class SystemHealth extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 90;

    protected static ?string $navigationLabel = 'System health';

    protected static ?string $title = 'System health';

    protected static ?string $slug = 'system-health';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('system.health');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Checks')
                ->description('Also checked every hour on the server: a failing check is mailed to the owners.')
                ->schema([View::make('filament.health.checks')->viewData(fn (): array => ['checks' => app(HealthService::class)->checks()])]),
            Section::make('Tills')
                ->description('What each till last told the server. Offline sales waiting upload when the till is back online; a till away for long, or with sales waiting, needs a look.')
                ->schema([View::make('filament.health.tills')->viewData(fn (): array => ['tills' => app(HealthService::class)->tills()])]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backupNow')
                ->label('Back up now')
                ->icon(Heroicon::OutlinedCloudArrowUp)
                ->requiresConfirmation()
                ->modalDescription('Makes a backup of everything now, in the background (a few minutes). It replaces nothing.')
                ->action(function (): void {
                    Artisan::queue('pos:backup', ['--label' => 'manual', '--no-prune' => true]);
                    Notification::make()->title('Backup started')->body('Refresh this page in a few minutes to see it.')->success()->send();
                }),
            Action::make('downloadBackup')
                ->label('Download latest backup')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('backups.download') ?? false)
                ->disabled(fn (): bool => app(BackupService::class)->latestLocal() === null)
                ->action(fn (): BinaryFileResponse => $this->download()),
        ];
    }

    /** The newest backup on this server, encrypted as it is; the download is audited. */
    private function download(): BinaryFileResponse
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->can('backups.download'), 403);

        $file = app(BackupService::class)->latestLocal();
        abort_if($file === null, 404);

        app(AuditObserver::class)->record('backup_downloaded', app(CurrentShop::class)->getOrFail(), [], ['backup' => basename($file)], (int) $user->getKey());

        return response()->download($file, basename($file), ['Content-Type' => 'application/octet-stream']);
    }
}
