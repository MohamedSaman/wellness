<?php

namespace App\Services\Backup;

/**
 * Dumps a database connection to an SQL file and loads one back, for
 * pos:backup / verify / restore. MySQL in production (mysqldump), SQLite for
 * development and the test suite.
 */
interface DatabaseDumper
{
    /** Writes the whole database of $connection to $file, as one consistent snapshot. */
    public function dump(string $connection, string $file): void;

    /** Replaces everything in $connection's database with $file: every table there is dropped first. */
    public function restore(string $connection, string $file): void;

    /**
     * A connection to an empty scratch database to restore into (pos:backup:verify).
     * Returns its connection name.
     */
    public function scratch(string $connection): string;

    /** Drops the scratch database again. */
    public function dropScratch(string $scratch): void;

    /** @return list<string> the tables of $connection's database */
    public function tables(string $connection): array;

    /** The database's size on disk in bytes, when it can be told. */
    public function size(string $connection): ?int;
}
