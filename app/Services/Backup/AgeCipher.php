<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Process;

/**
 * Encrypts and decrypts backups with age (https://age-encryption.org), the
 * `age` binary installed by scripts/provision-server.sh. A backup is
 * encrypted to every recipient (public keys, age1…): the offline recovery
 * key, whose private half never touches the server, and the server's verify
 * key, so the weekly verify can open it (docs/deploy/CLOUD.md, Backups).
 */
class AgeCipher
{
    /** @param  list<string>  $recipients */
    public function encrypt(string $plain, string $encrypted, array $recipients): void
    {
        if ($recipients === []) {
            throw new BackupException('No backup recipients: set POS_BACKUP_AGE_RECIPIENTS to the recovery key\'s public key (age1…).');
        }

        $command = [$this->binary(), '--encrypt'];

        foreach ($recipients as $recipient) {
            $command[] = '--recipient';
            $command[] = $recipient;
        }

        $this->run([...$command, '--output', $encrypted, $plain], 'encrypt the backup');
    }

    public function decrypt(string $encrypted, string $plain, string $identityFile): void
    {
        if (! is_readable($identityFile)) {
            throw new BackupException("The key file {$identityFile} cannot be read.");
        }

        $this->run([$this->binary(), '--decrypt', '--identity', $identityFile, '--output', $plain, $encrypted], 'decrypt the backup (is this the right key?)');
    }

    /** @param  list<string>  $command */
    private function run(array $command, string $what): void
    {
        $result = Process::timeout(3600)->run($command);

        if ($result->failed()) {
            throw new BackupException("Could not {$what}: ".trim($result->errorOutput() ?: $result->output()));
        }
    }

    private function binary(): string
    {
        return (string) config('pos.backup.binaries.age', 'age');
    }
}
