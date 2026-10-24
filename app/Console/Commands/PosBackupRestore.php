<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupException;
use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

use function Laravel\Prompts\text;

/**
 * Replaces this install's database and stored files with a backup
 * (docs/deploy/CLOUD.md, Restore). Asks for the database's name to be typed
 * (or --confirm=<database> without a terminal), makes a safety backup first,
 * puts the app in maintenance while it works, then migrates (a backup older
 * than the code is brought up to date) and comes back up.
 *
 *   php artisan pos:backup:restore                         the newest backup in the store
 *   php artisan pos:backup:restore pos-backup-20261020-210000.tar.gz.age
 *   php artisan pos:backup:restore --file=/root/usb/pos-backup-….tar.gz.age --identity=/root/usb/recovery-key.txt
 */
class PosBackupRestore extends Command
{
    protected $signature = 'pos:backup:restore
        {name? : A backup in the store (default: the newest)}
        {--file= : An .age backup file on this server instead}
        {--identity= : The age key file that opens it (default: the server\'s verify key)}
        {--confirm= : The database\'s name, to restore without being asked}
        {--no-safety-backup : Do not back up what is here now first (only when that backup cannot be made)}';

    protected $description = 'Restore the database and stored files from a backup (asks for confirmation)';

    public function handle(BackupService $backups): int
    {
        $file = $this->option('file');
        $source = is_string($file) ? $file : ($this->argument('name') ?? $backups->backups()[0] ?? null);
        $identity = (string) ($this->option('identity') ?: config('pos.backup.identity'));
        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (! is_string($source) || $source === '') {
            $this->components->error('There is no backup to restore.');

            return self::FAILURE;
        }

        if (is_string($file) && ! is_file($file)) {
            $this->components->error("{$file} does not exist.");

            return self::FAILURE;
        }

        if ($identity === '') {
            $this->components->error('No key to open the backup: pass --identity=<the recovery key file>.');

            return self::FAILURE;
        }

        $this->components->warn("This replaces EVERYTHING in the database {$database} and in storage/app with {$source}. Sales and changes made since that backup are lost (a safety backup of what is here now is made first).");

        $typed = $this->option('confirm') ?? ($this->input->isInteractive() ? text("Type the database's name ({$database}) to restore") : null);

        if ($typed !== $database) {
            $this->components->error('Not restored: the database name was not confirmed.');

            return self::FAILURE;
        }

        if (! $this->option('no-safety-backup')) {
            try {
                $this->components->info('Safety backup '.$backups->create('pre-restore').' made.');
            } catch (BackupException $exception) {
                $this->components->error('Not restored: the safety backup failed ('.$exception->getMessage().'). Fix it, or restore with --no-safety-backup.');

                return self::FAILURE;
            }
        }

        $wasDown = app()->isDownForMaintenance();

        if (! $wasDown) {
            Artisan::call('down', ['--retry' => 60]);
        }

        try {
            $result = $backups->restore($source, $identity);
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('optimize:clear');
        } catch (BackupException $exception) {
            $this->components->error('Restore failed: '.$exception->getMessage().' The app stays in maintenance mode; restore the safety backup or fix the problem, then run php artisan up.');

            return self::FAILURE;
        }

        if (! $wasDown) {
            Artisan::call('up');
        }

        Artisan::call('queue:restart');
        $this->components->info("Restored {$result['name']} (made ".($result['manifest']['created_at'] ?? '?').', version '.($result['manifest']['version'] ?? '?').'). Anything recorded after that backup is not in it: check each till\'s recent sales against the paper receipts.');

        return self::SUCCESS;
    }
}
