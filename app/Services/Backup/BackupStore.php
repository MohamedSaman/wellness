<?php

namespace App\Services\Backup;

/** Where backups are kept off the server: object storage through rclone, or a directory. */
interface BackupStore
{
    /** A human description of where, for messages ("b2:pos-backups/clinic"). */
    public function describe(): string;

    public function put(string $localFile, string $name): void;

    public function get(string $name, string $localFile): void;

    /** @return list<string> the file names stored, in no particular order */
    public function list(): array;

    public function delete(string $name): void;
}
