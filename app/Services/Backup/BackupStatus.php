<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\File;

/**
 * The last backup, verify and restore, and the last failure of each, in
 * <local_path>/status.json. A file, not a table: restoring the database
 * must not take the backup history back in time with it, and the health
 * page must still say when the last backup was if the database is down.
 *
 * @phpstan-type Entry array{at: string, ok: bool, name?: string|null, message?: string|null, size?: int|null, uploaded?: bool, level?: string, tables?: int, rows?: int}
 */
class BackupStatus
{
    public const KINDS = ['backup', 'verify', 'restore'];

    /** @return array<string, mixed> */
    public function all(): array
    {
        $file = $this->file();

        if (! is_file($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }

    /**
     * The last run of a kind ('backup', 'verify', 'restore'), ok or not.
     *
     * @return array<string, mixed>|null
     */
    public function last(string $kind): ?array
    {
        $entry = $this->all()[$kind] ?? null;

        return is_array($entry) ? $entry : null;
    }

    /**
     * The last run of a kind that worked.
     *
     * @return array<string, mixed>|null
     */
    public function lastOk(string $kind): ?array
    {
        $entry = $this->all()["{$kind}_ok"] ?? null;

        return is_array($entry) ? $entry : null;
    }

    /** @param  array<string, mixed>  $entry */
    public function record(string $kind, bool $ok, array $entry = []): void
    {
        $data = $this->all();
        $entry = ['at' => now()->toIso8601String(), 'ok' => $ok, ...$entry];
        $data[$kind] = $entry;

        if ($ok) {
            $data["{$kind}_ok"] = $entry;
        }

        $file = $this->file();
        File::ensureDirectoryExists(dirname($file), 0700);
        $temporary = $file.'.'.getmypid().'.tmp';
        file_put_contents($temporary, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        rename($temporary, $file);
    }

    private function file(): string
    {
        return rtrim((string) config('pos.backup.local_path'), '/').'/status.json';
    }
}
