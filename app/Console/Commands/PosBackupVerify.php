<?php

namespace App\Console\Commands;

use App\Notifications\HealthAlert;
use App\Services\Backup\BackupException;
use App\Services\Backup\BackupService;
use App\Services\HealthService;
use Illuminate\Console\Command;

/**
 * Downloads the newest backup from the store, checks its checksum, and with
 * the server's verify key restores it into the scratch database and checks
 * every table's row count against the backup's manifest. Weekly from the
 * scheduler; a failure is mailed at once.
 */
class PosBackupVerify extends Command
{
    protected $signature = 'pos:backup:verify {name? : A backup\'s name (default: the newest)}';

    protected $description = 'Restore the newest backup into a scratch database and check it';

    public function handle(BackupService $backups, HealthService $health): int
    {
        try {
            $result = $backups->verify($this->argument('name') === null ? null : (string) $this->argument('name'));
        } catch (BackupException $exception) {
            $this->components->error('Verify failed: '.$exception->getMessage());
            $health->alert(new HealthAlert('verify_failed', 'The backup check failed', $exception->getMessage()), force: true);

            return self::FAILURE;
        }

        $result['level'] === 'restore'
            ? $this->components->info("{$result['name']}: restored into the scratch database, {$result['tables']} tables and {$result['rows']} rows as recorded.")
            : $this->components->warn("{$result['name']}: checksum good. Not restored: there is no verify key on this server (POS_BACKUP_AGE_IDENTITY).");

        return self::SUCCESS;
    }
}
