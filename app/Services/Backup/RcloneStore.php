<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Process;

/**
 * Object storage at another provider (Backblaze B2, Cloudflare R2, S3)
 * through rclone: `remote` is an rclone remote and path from the client's
 * rclone.conf (POS_BACKUP_RCLONE_CONFIG), such as b2:webxkey-pos/clinic.
 */
class RcloneStore implements BackupStore
{
    public function __construct(private readonly string $remote, private readonly ?string $configFile = null) {}

    public function describe(): string
    {
        return $this->remote;
    }

    public function put(string $localFile, string $name): void
    {
        $this->rclone(['copyto', $localFile, $this->path($name)], "upload {$name}");
    }

    public function get(string $name, string $localFile): void
    {
        $this->rclone(['copyto', $this->path($name), $localFile], "download {$name}");
    }

    public function list(): array
    {
        $output = $this->rclone(['lsf', '--files-only', rtrim($this->remote, '/').'/'], 'list the backups');

        return array_values(array_filter(array_map('trim', explode("\n", $output))));
    }

    public function delete(string $name): void
    {
        $this->rclone(['deletefile', $this->path($name)], "delete {$name}");
    }

    private function path(string $name): string
    {
        return rtrim($this->remote, '/').'/'.$name;
    }

    /** @param  list<string>  $arguments */
    private function rclone(array $arguments, string $what): string
    {
        $command = [(string) config('pos.backup.binaries.rclone', 'rclone')];

        if ($this->configFile !== null && $this->configFile !== '') {
            $command[] = '--config';
            $command[] = $this->configFile;
        }

        $result = Process::timeout(3600)->run([...$command, ...$arguments]);

        if ($result->failed()) {
            throw new BackupException("Could not {$what} at {$this->remote}: ".trim($result->errorOutput() ?: $result->output()));
        }

        return $result->output();
    }
}
