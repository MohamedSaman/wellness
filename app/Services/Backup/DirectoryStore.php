<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\File;

/**
 * A directory as the backup store (POS_BACKUP_REMOTE starting with "/"): a
 * mounted volume or a second disk. Not off-site on its own; the tests use
 * it in place of object storage.
 */
class DirectoryStore implements BackupStore
{
    public function __construct(private readonly string $directory) {}

    public function describe(): string
    {
        return $this->directory;
    }

    public function put(string $localFile, string $name): void
    {
        File::ensureDirectoryExists($this->directory, 0700);

        if (! @copy($localFile, $this->path($name))) {
            throw new BackupException("Could not copy {$name} to {$this->directory}.");
        }
    }

    public function get(string $name, string $localFile): void
    {
        if (! is_file($this->path($name)) || ! @copy($this->path($name), $localFile)) {
            throw new BackupException("{$name} is not in {$this->directory}.");
        }
    }

    public function list(): array
    {
        return is_dir($this->directory)
            ? array_values(array_map(fn (\SplFileInfo $file): string => $file->getFilename(), File::files($this->directory)))
            : [];
    }

    public function delete(string $name): void
    {
        File::delete($this->path($name));
    }

    private function path(string $name): string
    {
        return rtrim($this->directory, '/').'/'.basename($name);
    }
}
