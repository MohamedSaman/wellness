<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * mysqldump --single-transaction (InnoDB: a consistent snapshot without
 * locking the tills out) and the mysql client to load it back. The
 * credentials go in a temporary option file (0600), never on the command
 * line. The scratch database for verify is <database>_verify unless
 * pos.backup.verify_database names another; scripts/add-client.sh grants
 * the client's MySQL user that database too.
 */
class MysqlDumper implements DatabaseDumper
{
    public function dump(string $connection, string $file): void
    {
        $config = $this->config($connection);

        $this->withOptionFile($config, fn (string $options) => $this->run([
            (string) config('pos.backup.binaries.mysqldump', 'mysqldump'),
            "--defaults-extra-file={$options}",
            '--single-transaction',
            '--quick',
            '--triggers',
            '--no-tablespaces',
            '--hex-blob',
            '--default-character-set=utf8mb4',
            '--set-gtid-purged=OFF',
            "--result-file={$file}",
            (string) $config['database'],
        ], 'dump the database'));
    }

    public function restore(string $connection, string $file): void
    {
        $this->dropTables($connection);
        $config = $this->config($connection);

        $this->withOptionFile($config, fn (string $options) => $this->run([
            (string) config('pos.backup.binaries.mysql', 'mysql'),
            "--defaults-extra-file={$options}",
            '--default-character-set=utf8mb4',
            (string) $config['database'],
            '--execute=source '.$file,
        ], 'load the database'));

        DB::connection($connection)->reconnect();
    }

    public function scratch(string $connection): string
    {
        $config = $this->config($connection);
        $database = (string) (config('pos.backup.verify_database') ?: $config['database'].'_verify');

        if ($database === $config['database']) {
            throw new BackupException('The verify database must not be the live database.');
        }

        DB::connection($connection)->statement('CREATE DATABASE IF NOT EXISTS `'.str_replace('`', '', $database).'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        config(['database.connections.backup_scratch' => [...$config, 'database' => $database]]);
        DB::purge('backup_scratch');
        $this->dropTables('backup_scratch');

        return 'backup_scratch';
    }

    public function dropScratch(string $scratch): void
    {
        $database = (string) config("database.connections.{$scratch}.database");
        DB::connection($scratch)->statement('DROP DATABASE IF EXISTS `'.str_replace('`', '', $database).'`');
        DB::purge($scratch);
    }

    public function tables(string $connection): array
    {
        return array_values(DB::connection($connection)->table('information_schema.tables')
            ->whereRaw('table_schema = DATABASE()')
            ->where('table_type', 'BASE TABLE')
            ->orderByRaw('table_name')
            ->selectRaw('table_name as name')
            ->pluck('name')
            ->map(fn (mixed $name): string => (string) $name)
            ->all());
    }

    public function size(string $connection): ?int
    {
        $size = DB::connection($connection)->table('information_schema.tables')
            ->whereRaw('table_schema = DATABASE()')
            ->selectRaw('SUM(data_length + index_length) as size')
            ->value('size');

        return is_numeric($size) ? (int) $size : null;
    }

    private function dropTables(string $connection): void
    {
        $db = DB::connection($connection);
        $db->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($this->tables($connection) as $table) {
                $db->statement('DROP TABLE IF EXISTS `'.str_replace('`', '', $table).'`');
            }
        } finally {
            $db->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /** @return array<string, mixed> */
    private function config(string $connection): array
    {
        /** @var array<string, mixed> $config */
        $config = config("database.connections.{$connection}");

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  callable(string): void  $callback
     */
    private function withOptionFile(array $config, callable $callback): void
    {
        $file = tempnam(sys_get_temp_dir(), 'pos-my');

        if ($file === false) {
            throw new BackupException('Could not create a temporary file for the database credentials.');
        }

        chmod($file, 0600);
        $quote = fn (mixed $value): string => '"'.addcslashes((string) $value, '"\\').'"';

        file_put_contents($file, "[client]\n"
            .'user='.$quote($config['username'] ?? '')."\n"
            .'password='.$quote($config['password'] ?? '')."\n"
            .(filled($config['unix_socket'] ?? null)
                ? 'socket='.$quote($config['unix_socket'])."\n"
                : 'host='.$quote($config['host'] ?? '127.0.0.1')."\nport=".(int) ($config['port'] ?? 3306)."\n"));

        try {
            $callback($file);
        } finally {
            @unlink($file);
        }
    }

    /** @param  list<string>  $command */
    private function run(array $command, string $what): void
    {
        $result = Process::timeout(3600)->run($command);

        if ($result->failed()) {
            throw new BackupException("Could not {$what}: ".trim($result->errorOutput() ?: $result->output()));
        }
    }
}
