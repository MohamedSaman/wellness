<?php

namespace App\Services\Backup;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Backups of one install (docs/deploy/CLOUD.md, Backups): its database and
 * storage/app in one archive, encrypted with age, kept on the server
 * (the newest keep_local) and in the backup store at another provider.
 *
 *   pos-backup-20261022-210000[-label].tar.gz.age   the archive
 *   pos-backup-20261022-210000[-label].json         its checksum, size and version (not secret)
 *
 * Inside the archive: database.sql, manifest.json (the version, the tables
 * and their row counts), app/ (storage/app) and env/.env (the install's
 * settings and APP_KEY, for a new server; a restore leaves the .env alone). Retention keeps the newest
 * backup of each of the last keep_daily days and keep_monthly months.
 */
class BackupService
{
    public const PREFIX = 'pos-backup-';

    public const SUFFIX = '.tar.gz.age';

    public function __construct(
        private readonly AgeCipher $cipher,
        private readonly BackupStatus $status,
    ) {}

    /**
     * Makes a backup now and uploads it. Returns its name.
     *
     * @param  string|null  $label  a word added to the name: pre-restore, pre-deploy, go-live …
     */
    public function create(?string $label = null): string
    {
        $name = self::PREFIX.now('UTC')->format('Ymd-His').($label !== null && $label !== '' ? '-'.Str::slug($label) : '').self::SUFFIX;

        try {
            $work = $this->workDirectory();

            try {
                $connection = (string) config('database.default');
                $dumper = $this->dumper($connection);
                $before = $this->counts($connection, $dumper);
                $dumper->dump($connection, "{$work}/database.sql");
                $after = $this->counts($connection, $dumper);

                $tables = [];

                foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $table) {
                    $tables[$table] = [min($before[$table] ?? 0, $after[$table] ?? 0), max($before[$table] ?? 0, $after[$table] ?? 0)];
                }

                ksort($tables);
                $manifest = [
                    'format' => 1,
                    'name' => $name,
                    'app' => config('app.name'),
                    'url' => config('app.url'),
                    'version' => config('pos.version'),
                    'created_at' => now()->toIso8601String(),
                    'driver' => DB::connection($connection)->getDriverName(),
                    'database' => config("database.connections.{$connection}.database"),
                    // Each table's row count before and after the dump: the dump holds a count in between.
                    'tables' => $tables,
                ];
                file_put_contents("{$work}/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                $this->tar($work, "{$work}/backup.tar.gz");
                $local = $this->localPath($name);
                $this->cipher->encrypt("{$work}/backup.tar.gz", $local, $this->recipients());
                chmod($local, 0600);
            } finally {
                File::deleteDirectory($work);
            }

            $sidecar = [
                'name' => $name,
                'sha256' => hash_file('sha256', $local),
                'size' => filesize($local),
                'created_at' => $manifest['created_at'],
                'version' => $manifest['version'],
                'label' => $label,
            ];
            file_put_contents($this->localPath(self::sidecarName($name)), json_encode($sidecar, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $store = $this->store();
            $store?->put($local, $name);
            $store?->put($this->localPath(self::sidecarName($name)), self::sidecarName($name));

            $this->pruneLocal();
            $this->status->record('backup', true, ['name' => $name, 'size' => $sidecar['size'], 'uploaded' => $store !== null, 'message' => $store === null ? 'Kept on this server only: no backup store (POS_BACKUP_REMOTE).' : 'Uploaded to '.$store->describe().'.']);

            return $name;
        } catch (Throwable $exception) {
            $this->status->record('backup', false, ['name' => $name, 'message' => $exception->getMessage()]);

            throw $exception instanceof BackupException ? $exception : new BackupException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * The backups in the store (else on this server), newest first.
     *
     * @return list<string>
     */
    public function backups(): array
    {
        $store = $this->store();
        $names = $store === null ? $this->localNames() : $store->list();
        $names = array_values(array_filter($names, fn (string $name): bool => self::timeOf($name) !== null));
        usort($names, fn (string $a, string $b): int => strcmp($b, $a));

        return $names;
    }

    /**
     * Deletes the backups in the store that retention does not keep: the
     * newest of each of the last keep_daily days and of the last
     * keep_monthly months (UTC). Returns the names deleted.
     *
     * @return list<string>
     */
    public function prune(): array
    {
        $store = $this->store();

        if ($store === null) {
            return [];
        }

        $names = $this->backups();
        $keep = self::retained($names, (int) config('pos.backup.keep_daily', 14), (int) config('pos.backup.keep_monthly', 12));
        $deleted = [];

        foreach (array_diff($names, $keep) as $name) {
            $store->delete($name);
            $store->delete(self::sidecarName($name));
            $deleted[] = $name;
        }

        return $deleted;
    }

    /**
     * Which of these backups retention keeps: the newest of each of the
     * $daily most recent days that have one, and of the $monthly most recent months.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function retained(array $names, int $daily, int $monthly): array
    {
        usort($names, fn (string $a, string $b): int => strcmp($b, $a));
        $days = [];
        $months = [];
        $keep = [];

        foreach ($names as $name) {
            $time = self::timeOf($name);

            if ($time === null) {
                continue;
            }

            $day = $time->format('Y-m-d');
            $month = $time->format('Y-m');

            if (! isset($days[$day]) && count($days) < $daily) {
                $days[$day] = true;
                $keep[$name] = true;
            }

            if (! isset($months[$month]) && count($months) < $monthly) {
                $months[$month] = true;
                $keep[$name] = true;
            }
        }

        return array_keys($keep);
    }

    /**
     * Restores the newest backup into the scratch database and checks it:
     * the checksum against its sidecar, then (with the server's verify key)
     * every table there with the row count the manifest says. Returns what
     * was checked; throws when anything is wrong.
     *
     * @return array{name: string, level: string, tables: int, rows: int}
     */
    public function verify(?string $name = null): array
    {
        $name ??= $this->backups()[0] ?? null;

        try {
            if ($name === null) {
                throw new BackupException('There is no backup to verify.');
            }

            $work = $this->workDirectory();

            try {
                $encrypted = $this->fetch($name, $work);
                $identity = (string) config('pos.backup.identity');

                if ($identity === '') {
                    $result = ['name' => $name, 'level' => 'checksum', 'tables' => 0, 'rows' => 0];
                    $this->status->record('verify', true, [...$result, 'message' => 'Checksum only: no verify key on this server (POS_BACKUP_AGE_IDENTITY), so the backup was not restored.']);

                    return $result;
                }

                [$manifest, $sql] = $this->unpack($encrypted, $identity, $work);
                $connection = (string) config('database.default');
                $dumper = $this->dumper($connection);
                $scratch = $dumper->scratch($connection);

                try {
                    $dumper->restore($scratch, $sql);
                    $rows = $this->checkCounts($manifest, $scratch, $dumper);
                } finally {
                    $dumper->dropScratch($scratch);
                }
            } finally {
                File::deleteDirectory($work);
            }

            $result = ['name' => $name, 'level' => 'restore', 'tables' => count($manifest['tables']), 'rows' => $rows];
            $this->status->record('verify', true, [...$result, 'message' => "Restored into a scratch database: {$result['tables']} tables, {$rows} rows, as the backup recorded."]);

            return $result;
        } catch (Throwable $exception) {
            $this->status->record('verify', false, ['name' => $name, 'message' => $exception->getMessage()]);

            throw $exception instanceof BackupException ? $exception : new BackupException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * Replaces the database and storage/app with a backup. The caller makes
     * the safety backup and puts the app in maintenance first
     * (pos:backup:restore). $source is a backup name (from the store or this
     * server) or the path of an .age file.
     *
     * @return array{name: string, manifest: array<string, mixed>}
     */
    public function restore(string $source, string $identity): array
    {
        $name = basename($source);

        try {
            $work = $this->workDirectory();

            try {
                $encrypted = is_file($source) ? $source : $this->fetch($source, $work);
                [$manifest, $sql] = $this->unpack($encrypted, $identity, $work);

                $connection = (string) config('database.default');
                $this->dumper($connection)->restore($connection, $sql);
                $this->restoreFiles("{$work}/extract/app");
            } finally {
                File::deleteDirectory($work);
            }

            $this->status->record('restore', true, ['name' => $name, 'message' => 'Restored the backup of '.($manifest['created_at'] ?? '?').' (version '.($manifest['version'] ?? '?').').']);

            return ['name' => $name, 'manifest' => $manifest];
        } catch (Throwable $exception) {
            $this->status->record('restore', false, ['name' => $name, 'message' => $exception->getMessage()]);

            throw $exception instanceof BackupException ? $exception : new BackupException($exception->getMessage(), 0, $exception);
        }
    }

    /** The newest backup kept on this server (for the owner's download), or null. */
    public function latestLocal(): ?string
    {
        $names = $this->localNames();
        usort($names, fn (string $a, string $b): int => strcmp($b, $a));

        return isset($names[0]) ? $this->localPath($names[0]) : null;
    }

    public function store(): ?BackupStore
    {
        $remote = trim((string) config('pos.backup.remote'));

        return match (true) {
            $remote === '' => null,
            str_starts_with($remote, '/') => new DirectoryStore($remote),
            default => new RcloneStore($remote, config('pos.backup.rclone_config')),
        };
    }

    public function dumper(string $connection): DatabaseDumper
    {
        return match ($driver = DB::connection($connection)->getDriverName()) {
            'mysql', 'mariadb' => new MysqlDumper,
            'sqlite' => new SqliteDumper,
            default => throw new BackupException("Backups do not support the {$driver} database driver."),
        };
    }

    /** When a backup was made, from its name, or null if it is not a backup's name. */
    public static function timeOf(string $name): ?CarbonImmutable
    {
        if (! preg_match('/^'.preg_quote(self::PREFIX, '/').'(\d{8}-\d{6})(-[a-z0-9-]+)?'.preg_quote(self::SUFFIX, '/').'$/', $name, $match)) {
            return null;
        }

        return CarbonImmutable::createFromFormat('Ymd-His', $match[1], 'UTC') ?: null;
    }

    public static function sidecarName(string $name): string
    {
        return Str::beforeLast($name, self::SUFFIX).'.json';
    }

    /** @return list<string> */
    private function recipients(): array
    {
        return array_values(array_filter((array) config('pos.backup.recipients', []), fn (mixed $recipient): bool => is_string($recipient) && $recipient !== ''));
    }

    /**
     * Every table's row count, except the volatile ones (sessions, cache, queue).
     *
     * @return array<string, int>
     */
    private function counts(string $connection, DatabaseDumper $dumper): array
    {
        $volatile = (array) config('pos.backup.volatile_tables', []);
        $counts = [];

        foreach ($dumper->tables($connection) as $table) {
            if (! in_array($table, $volatile, true)) {
                $counts[$table] = (int) DB::connection($connection)->table($table)->count();
            }
        }

        return $counts;
    }

    /**
     * Checks the restored tables against the manifest. Returns the rows counted.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function checkCounts(array $manifest, string $scratch, DatabaseDumper $dumper): int
    {
        $restored = $this->counts($scratch, $dumper);
        $problems = [];
        $rows = 0;

        /** @var array<string, array{0: int, 1: int}> $tables */
        $tables = $manifest['tables'];

        foreach ($tables as $table => [$low, $high]) {
            $count = $restored[$table] ?? null;

            if ($count === null) {
                $problems[] = "{$table} is missing";
            } elseif ($count < $low || $count > $high) {
                $problems[] = "{$table} has {$count} rows, expected ".($low === $high ? $low : "{$low} to {$high}");
            }

            $rows += $count ?? 0;
        }

        if ($problems !== []) {
            throw new BackupException('The restored backup does not match what was backed up: '.implode('; ', $problems).'.');
        }

        return $rows;
    }

    /** Downloads a backup and its sidecar into $work and checks the checksum. Returns the archive's path. */
    private function fetch(string $name, string $work): string
    {
        $encrypted = "{$work}/{$name}";
        $sidecarFile = "{$work}/".self::sidecarName($name);
        $store = $this->store();

        if ($store !== null) {
            $store->get($name, $encrypted);
            $store->get(self::sidecarName($name), $sidecarFile);
        } elseif (is_file($this->localPath($name))) {
            copy($this->localPath($name), $encrypted);
            copy($this->localPath(self::sidecarName($name)), $sidecarFile);
        } else {
            throw new BackupException("Backup {$name} not found.");
        }

        $sidecar = json_decode((string) file_get_contents($sidecarFile), true);

        if (! is_array($sidecar) || ! isset($sidecar['sha256'])) {
            throw new BackupException("The checksum file of {$name} is missing or unreadable.");
        }

        if (! hash_equals((string) $sidecar['sha256'], (string) hash_file('sha256', $encrypted))) {
            throw new BackupException("{$name} is damaged: its checksum does not match the one recorded when it was made.");
        }

        return $encrypted;
    }

    /**
     * Decrypts and unpacks an archive into $work/extract. Returns its manifest and the SQL file.
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function unpack(string $encrypted, string $identity, string $work): array
    {
        $this->cipher->decrypt($encrypted, "{$work}/backup.tar.gz", $identity);
        File::ensureDirectoryExists("{$work}/extract", 0700);

        $result = Process::timeout(3600)->run([(string) config('pos.backup.binaries.tar', 'tar'), '-xzf', "{$work}/backup.tar.gz", '-C', "{$work}/extract"]);

        if ($result->failed()) {
            throw new BackupException('Could not unpack the backup: '.trim($result->errorOutput()));
        }

        $manifest = json_decode((string) @file_get_contents("{$work}/extract/manifest.json"), true);

        if (! is_array($manifest) || ! is_array($manifest['tables'] ?? null) || ! is_file("{$work}/extract/database.sql")) {
            throw new BackupException('This is not a complete backup: manifest.json or database.sql is missing.');
        }

        $driver = DB::connection((string) config('database.default'))->getDriverName();

        if (($manifest['driver'] ?? null) !== $driver) {
            throw new BackupException('This backup is of a '.($manifest['driver'] ?? 'unknown')." database; this install runs {$driver}.");
        }

        return [$manifest, "{$work}/extract/database.sql"];
    }

    /** The database dump, the manifest and storage/app, in one tar.gz. */
    private function tar(string $work, string $archive): void
    {
        $storage = rtrim((string) config('pos.backup.storage_root', storage_path('app')), '/');
        $env = (string) config('pos.backup.env_file');
        $files = ['database.sql', 'manifest.json'];

        if ($env !== '' && is_readable($env)) {
            File::ensureDirectoryExists("{$work}/env", 0700);
            copy($env, "{$work}/env/.env");
            $files[] = 'env';
        }

        $command = [(string) config('pos.backup.binaries.tar', 'tar'), '-czf', $archive, '-C', $work, ...$files];

        if (is_dir($storage)) {
            $command = [...$command, '-C', dirname($storage), basename($storage)];
        }

        $result = Process::timeout(3600)->run($command);

        if ($result->failed()) {
            throw new BackupException('Could not pack the backup: '.trim($result->errorOutput()));
        }
    }

    /** Puts the backup's storage/app in place of the current one (moved aside until the new one is in). */
    private function restoreFiles(string $extracted): void
    {
        $storage = rtrim((string) config('pos.backup.storage_root', storage_path('app')), '/');

        if (! is_dir($extracted)) {
            return;
        }

        $aside = $storage.'.before-restore-'.now()->format('YmdHis');

        if (is_dir($storage) && ! @rename($storage, $aside)) {
            throw new BackupException("Could not move {$storage} aside to restore the files.");
        }

        if (! File::moveDirectory($extracted, $storage) && ! File::copyDirectory($extracted, $storage)) {
            if (is_dir($aside)) {
                @rename($aside, $storage);
            }

            throw new BackupException("Could not put the backup's files in {$storage}.");
        }

        File::deleteDirectory($aside);
    }

    private function pruneLocal(): void
    {
        $names = $this->localNames();
        usort($names, fn (string $a, string $b): int => strcmp($b, $a));

        foreach (array_slice($names, max(1, (int) config('pos.backup.keep_local', 2))) as $name) {
            File::delete([$this->localPath($name), $this->localPath(self::sidecarName($name))]);
        }
    }

    /** @return list<string> */
    private function localNames(): array
    {
        $directory = (string) config('pos.backup.local_path');

        return is_dir($directory)
            ? array_values(array_filter(array_map(fn (\SplFileInfo $file): string => $file->getFilename(), File::files($directory)), fn (string $name): bool => self::timeOf($name) !== null))
            : [];
    }

    private function localPath(string $name): string
    {
        $directory = rtrim((string) config('pos.backup.local_path'), '/');
        File::ensureDirectoryExists($directory, 0700);

        return "{$directory}/".basename($name);
    }

    private function workDirectory(): string
    {
        $directory = rtrim((string) config('pos.backup.local_path'), '/').'/work/'.Str::random(16);
        File::ensureDirectoryExists($directory, 0700);

        return $directory;
    }
}
