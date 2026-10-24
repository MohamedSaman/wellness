<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;

class PosBackupList extends Command
{
    protected $signature = 'pos:backup:list';

    protected $description = 'List the backups in the backup store, newest first';

    public function handle(BackupService $backups): int
    {
        $names = $backups->backups();

        if ($names === []) {
            $this->components->warn('There are no backups yet.');

            return self::SUCCESS;
        }

        $this->table(['Backup', 'Made (UTC)'], array_map(fn (string $name): array => [$name, BackupService::timeOf($name)?->format('Y-m-d H:i:s')], $names));

        return self::SUCCESS;
    }
}
