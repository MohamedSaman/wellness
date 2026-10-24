<?php

namespace App\Services\Import;

use App\Models\ImportRun;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Rows written so far by a running import, for the progress bar. Kept in a
 * cache store outside the database (config pos.imports.progress_store,
 * "file" by default): the import writes inside one transaction, so a
 * database row would not show anything until the very end.
 */
final class ImportProgress
{
    /** Write every this many rows (and the last). */
    private const EVERY = 25;

    public static function set(ImportRun $run, int $done, bool $force = false): void
    {
        if ($force || $done % self::EVERY === 0 || $done >= $run->total_rows) {
            self::store()->put(self::key($run), $done, now()->addDay());
        }
    }

    public static function get(ImportRun $run): int
    {
        return (int) self::store()->get(self::key($run), 0);
    }

    public static function forget(ImportRun $run): void
    {
        self::store()->forget(self::key($run));
    }

    private static function key(ImportRun $run): string
    {
        return "pos-import-progress:{$run->uuid}";
    }

    private static function store(): Repository
    {
        return Cache::store((string) config('pos.imports.progress_store', 'file'));
    }
}
