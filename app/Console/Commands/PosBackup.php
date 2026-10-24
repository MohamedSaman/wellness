<?php

namespace App\Console\Commands;

use App\Notifications\HealthAlert;
use App\Services\Backup\BackupException;
use App\Services\Backup\BackupService;
use App\Services\HealthService;
use Illuminate\Console\Command;

/**
 * Makes a backup now (database + storage/app, encrypted with age), uploads
 * it to the backup store and applies retention (14 daily, 12 monthly).
 * Nightly from the scheduler; deploys run it first with --label=pre-deploy.
 * A failure is mailed to the owners and the alert address at once.
 */
class PosBackup extends Command
{
    protected $signature = 'pos:backup {--label= : A word added to the backup\'s name (pre-deploy, go-live …)} {--no-prune : Keep every backup in the store this time}';

    protected $description = 'Back up the database and stored files, encrypted, to the backup store';

    public function handle(BackupService $backups, HealthService $health): int
    {
        try {
            $name = $backups->create($this->option('label') === null ? null : (string) $this->option('label'));
        } catch (BackupException $exception) {
            $this->components->error('Backup failed: '.$exception->getMessage());
            $health->alert(new HealthAlert('backup_failed', 'The backup failed', $exception->getMessage()), force: true);

            return self::FAILURE;
        }

        $this->components->info("Backup {$name} made.");

        if (! $this->option('no-prune')) {
            try {
                $deleted = $backups->prune();
            } catch (BackupException $exception) {
                $this->components->warn('The backup is safe, but old backups could not be pruned: '.$exception->getMessage());

                return self::SUCCESS;
            }

            if ($deleted !== []) {
                $this->components->info('Pruned '.count($deleted).' old backup(s): '.implode(', ', $deleted).'.');
            }
        }

        return self::SUCCESS;
    }
}
