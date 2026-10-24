<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;

/**
 * SQLite (development and the test suite): the schema and every row
 * written as SQL by PHP, one statement per line, so an in-memory database
 * dumps too. Loading it back drops every table first and defers foreign
 * keys to the end of the transaction, so rows can come in any order.
 */
class SqliteDumper implements DatabaseDumper
{
    public function dump(string $connection, string $file): void
    {
        $db = DB::connection($connection);
        $out = fopen($file, 'wb');

        if ($out === false) {
            throw new BackupException("Could not write {$file}.");
        }

        try {
            $schema = $db->select("SELECT type, name, tbl_name, sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END, name");

            foreach ($schema as $object) {
                if ($object->type === 'table') {
                    fwrite($out, self::oneLine((string) $object->sql).";\n");
                }
            }

            foreach ($schema as $object) {
                if ($object->type !== 'table') {
                    continue;
                }

                $table = '"'.str_replace('"', '""', (string) $object->name).'"';

                foreach ($db->table((string) $object->name)->cursor() as $row) {
                    $values = array_map(self::literal(...), array_values((array) $row));
                    $columns = array_map(fn (string $column): string => '"'.str_replace('"', '""', $column).'"', array_keys((array) $row));

                    fwrite($out, "INSERT INTO {$table} (".implode(', ', $columns).') VALUES ('.implode(', ', $values).");\n");
                }
            }

            foreach ($schema as $object) {
                if ($object->type !== 'table') {
                    fwrite($out, self::oneLine((string) $object->sql).";\n");
                }
            }
        } finally {
            fclose($out);
        }
    }

    public function restore(string $connection, string $file): void
    {
        $db = DB::connection($connection);
        $in = fopen($file, 'rb');

        if ($in === false) {
            throw new BackupException("Could not read {$file}.");
        }

        try {
            $db->transaction(function () use ($db, $connection, $in): void {
                $db->statement('PRAGMA defer_foreign_keys = ON');

                foreach ($this->dropOrder($connection) as $table) {
                    $db->statement('DROP TABLE IF EXISTS "'.str_replace('"', '""', $table).'"');
                }

                // One statement a line, as dump() writes them (no bindings to prepare).
                $pdo = $db->getPdo();

                while (($line = fgets($in)) !== false) {
                    if (trim($line) !== '') {
                        $pdo->exec(rtrim($line, "\n"));
                    }
                }
            });
        } finally {
            fclose($in);
        }
    }

    /**
     * The tables, those that reference others first: SQLite refuses to drop
     * a table whose foreign key names a table already dropped.
     *
     * @return list<string>
     */
    private function dropOrder(string $connection): array
    {
        $db = DB::connection($connection);
        $parents = [];

        foreach ($this->tables($connection) as $table) {
            $parents[$table] = array_values(array_diff(array_unique(array_map(
                fn (mixed $key): string => (string) ((array) $key)['table'],
                $db->select('SELECT "table" FROM pragma_foreign_key_list(?)', [$table]),
            )), [$table]));
        }

        $order = [];

        while ($parents !== []) {
            $referenced = array_merge(...array_values($parents));
            $leaves = array_values(array_filter(array_keys($parents), fn (string $table): bool => ! in_array($table, $referenced, true)));

            // A cycle: drop what is left as it comes.
            $next = $leaves === [] ? array_keys($parents) : $leaves;

            foreach ($next as $table) {
                $order[] = $table;
                unset($parents[$table]);
            }
        }

        return $order;
    }

    public function scratch(string $connection): string
    {
        config(['database.connections.backup_scratch' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge('backup_scratch');

        return 'backup_scratch';
    }

    public function dropScratch(string $scratch): void
    {
        DB::purge($scratch);
    }

    public function tables(string $connection): array
    {
        return array_values(DB::connection($connection)->table('sqlite_master')
            ->where('type', 'table')
            ->where('name', 'not like', 'sqlite_%')
            ->orderBy('name')
            ->pluck('name')
            ->map(fn (mixed $name): string => (string) $name)
            ->all());
    }

    public function size(string $connection): ?int
    {
        $path = (string) config("database.connections.{$connection}.database");

        return $path !== ':memory:' && is_file($path) ? (int) filesize($path) : null;
    }

    /** A value as an SQL literal on one line (newlines in text become char(10)). */
    private static function literal(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            is_float($value) => is_finite($value) ? sprintf('%.17g', $value) : 'NULL',
            ! mb_check_encoding((string) $value, 'UTF-8') => "X'".bin2hex((string) $value)."'",
            default => "'".strtr(str_replace("'", "''", (string) $value), ["\r" => "' || char(13) || '", "\n" => "' || char(10) || '"])."'",
        };
    }

    private static function oneLine(string $sql): string
    {
        return trim((string) preg_replace('/\s*[\r\n]+\s*/', ' ', $sql));
    }
}
