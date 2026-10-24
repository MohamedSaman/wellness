<?php

namespace Tests\Feature\Pos\Concerns;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Backups in a temporary directory: the local copies, the store (a
 * DirectoryStore standing in for object storage) and storage/app, with a
 * fresh age key pair. Needs the age binaries (apt install age), found on the
 * PATH or through POS_BACKUP_AGE_BINARY; without them the test is skipped,
 * unless POS_BACKUP_TESTS_REQUIRED=1 (CI), which makes it a failure.
 */
trait UsesBackupSandbox
{
    protected string $sandbox;

    protected string $identityFile;

    protected string $recipient;

    protected function setUpBackupSandbox(bool $withAge = true): void
    {
        $this->sandbox = sys_get_temp_dir().'/pos-backup-test-'.Str::random(10);
        File::ensureDirectoryExists("{$this->sandbox}/storage/app/public/logos");
        File::ensureDirectoryExists("{$this->sandbox}/storage/app/private");
        file_put_contents("{$this->sandbox}/storage/app/public/logos/logo.png", 'PNG-BYTES');
        file_put_contents("{$this->sandbox}/storage/app/private/import.csv", "sku,name\nA1,Helmet\n");
        file_put_contents("{$this->sandbox}/.env", "APP_KEY=base64:test-key\n");

        config([
            'pos.backup.local_path' => "{$this->sandbox}/backups",
            'pos.backup.storage_root' => "{$this->sandbox}/storage/app",
            'pos.backup.remote' => "{$this->sandbox}/remote",
            'pos.backup.env_file' => "{$this->sandbox}/.env",
            // Restores go into maintenance mode: never the developer's own storage/framework/down.
            'app.maintenance.driver' => 'cache',
            'app.maintenance.store' => 'array',
        ]);

        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->sandbox));

        if ($withAge) {
            $this->setUpAge();
        }
    }

    private function setUpAge(): void
    {
        $age = (string) (getenv('POS_BACKUP_AGE_BINARY') ?: trim((string) shell_exec('command -v age 2>/dev/null')));

        if ($age === '' || ! is_executable($age)) {
            $why = 'The age binary is not installed (apt install age, or POS_BACKUP_AGE_BINARY).';
            filter_var(getenv('POS_BACKUP_TESTS_REQUIRED'), FILTER_VALIDATE_BOOL) ? $this->fail($why) : $this->markTestSkipped($why);
        }

        $keygen = dirname($age).'/age-keygen';
        $this->identityFile = "{$this->sandbox}/server-verify.key";
        $result = Process::run([$keygen, '-o', $this->identityFile]);
        $this->assertTrue($result->successful(), $result->errorOutput());
        $this->recipient = trim((string) preg_replace('/^.*(age1[0-9a-z]+).*$/s', '$1', (string) file_get_contents($this->identityFile)));

        config([
            'pos.backup.binaries.age' => $age,
            'pos.backup.recipients' => [$this->recipient],
            'pos.backup.identity' => $this->identityFile,
        ]);
    }

    /** @return list<string> */
    protected function storedFiles(): array
    {
        $files = array_map(fn (\SplFileInfo $file): string => $file->getFilename(), File::files("{$this->sandbox}/remote"));
        sort($files);

        return $files;
    }
}
