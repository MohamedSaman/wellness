<?php

namespace Tests\Feature\Pos;

use App\Models\Product;
use App\Notifications\HealthAlert;
use App\Services\Backup\AgeCipher;
use App\Services\Backup\BackupService;
use App\Services\Backup\BackupStatus;
use App\Services\Backup\RcloneStore;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Tests\Feature\Pos\Concerns\UsesBackupSandbox;

/**
 * pos:backup, pos:backup:verify and pos:backup:restore (docs/deploy/CLOUD.md,
 * Backups): the database and storage/app in one archive encrypted with age,
 * a checksum beside it, uploaded to the store (a directory here, in place of
 * object storage), retention of 14 daily and 12 monthly, and a restore that
 * needs the database's name typed and makes a safety backup first.
 */
class BackupTest extends PosTestCase
{
    use UsesBackupSandbox;

    protected function setUp(): void
    {
        parent::setUp();

        // A restore replaces the database this test runs in, inside RefreshDatabase's
        // transaction: SQLite keeps DDL in it, MySQL commits it. The MySQL round trip
        // is Mysql/BackupMysqlTest (the MySQL group, with truncation instead).
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite only; on MySQL see Mysql/BackupMysqlTest.');
        }

        $this->setUpBackupSandbox();
    }

    private function backups(): BackupService
    {
        return app(BackupService::class);
    }

    public function test_backup_verify_restore_round_trip(): void
    {
        $this->artisan('pos:backup')->assertSuccessful();

        [$name] = $this->backups()->backups();
        $this->assertSame([BackupService::sidecarName($name), $name], $this->storedFiles());

        // Encrypted with age: nothing of the database or the files is readable in the store.
        $stored = (string) file_get_contents("{$this->sandbox}/remote/{$name}");
        $this->assertStringStartsWith('age-encryption.org/v1', $stored);
        $this->assertStringNotContainsString('owner@pos.test', $stored);
        $this->assertStringNotContainsString('CREATE TABLE', $stored);

        $sidecar = json_decode((string) file_get_contents("{$this->sandbox}/remote/".BackupService::sidecarName($name)), true);
        $this->assertSame(hash('sha256', $stored), $sidecar['sha256']);

        // Inside: the dump, the manifest, storage/app and the .env (for a new server) ...
        app(AgeCipher::class)->decrypt("{$this->sandbox}/remote/{$name}", "{$this->sandbox}/plain.tar.gz", $this->identityFile);
        $listing = Process::run(['tar', '-tzf', "{$this->sandbox}/plain.tar.gz"])->output();
        foreach (['database.sql', 'manifest.json', 'env/.env', 'app/public/logos/logo.png', 'app/private/import.csv'] as $entry) {
            $this->assertStringContainsString($entry, $listing);
        }

        // ... and a restore leaves the .env in use alone.
        file_put_contents("{$this->sandbox}/.env", "APP_KEY=base64:changed-since\n");

        // Verify: restored into the scratch database and every table counted.
        $this->artisan('pos:backup:verify')->assertSuccessful();
        $verify = app(BackupStatus::class)->lastOk('verify');
        $this->assertSame('restore', $verify['level']);
        $this->assertGreaterThan(20, $verify['tables']);
        $this->assertSame($name, $verify['name']);

        // Something changes after the backup ...
        $products = Product::query()->count();
        Product::query()->whereKey(Product::query()->value('id'))->update(['name' => 'Changed after the backup']);
        unlink("{$this->sandbox}/storage/app/public/logos/logo.png");

        // ... and the restore brings back the database and the files.
        $this->artisan('pos:backup:restore', ['--confirm' => ':memory:'])->assertSuccessful();

        $this->assertSame($products, Product::query()->count());
        $this->assertSame(0, Product::query()->where('name', 'Changed after the backup')->count());
        $this->assertSame('PNG-BYTES', file_get_contents("{$this->sandbox}/storage/app/public/logos/logo.png"));
        $this->assertSame("sku,name\nA1,Helmet\n", file_get_contents("{$this->sandbox}/storage/app/private/import.csv"));
        $this->assertTrue(app(BackupStatus::class)->last('restore')['ok']);
        $this->assertSame("APP_KEY=base64:changed-since\n", file_get_contents("{$this->sandbox}/.env"));
        $this->assertFalse(app()->isDownForMaintenance());

        // A safety backup of what was there was made first.
        $this->assertCount(1, array_filter($this->backups()->backups(), fn (string $backup): bool => str_contains($backup, '-pre-restore')));
    }

    public function test_restore_refuses_without_the_database_name_and_changes_nothing(): void
    {
        $this->artisan('pos:backup')->assertSuccessful();
        Product::query()->update(['name' => 'Kept']);

        $this->artisan('pos:backup:restore', ['--confirm' => 'yes'])->assertFailed();
        $this->artisan('pos:backup:restore')->expectsQuestion("Type the database's name (:memory:) to restore", 'no')->assertFailed();

        $this->assertSame(Product::query()->count(), Product::query()->where('name', 'Kept')->count());
        $this->assertCount(1, $this->backups()->backups(), 'No safety backup without a confirmed restore.');
        $this->assertNull(app(BackupStatus::class)->last('restore'));
    }

    public function test_restore_with_the_offline_recovery_key_from_a_file(): void
    {
        $name = $this->backups()->create();
        $copy = "{$this->sandbox}/usb-{$name}";
        copy("{$this->sandbox}/remote/{$name}", $copy);
        Product::query()->update(['name' => 'Lost']);

        $this->artisan('pos:backup:restore', ['--file' => $copy, '--identity' => $this->identityFile, '--confirm' => ':memory:', '--no-safety-backup' => true])->assertSuccessful();

        $this->assertSame(0, Product::query()->where('name', 'Lost')->count());
    }

    public function test_a_damaged_backup_fails_verify_and_alerts_the_owner(): void
    {
        Notification::fake();
        $name = $this->backups()->create();

        $file = "{$this->sandbox}/remote/{$name}";
        $bytes = (string) file_get_contents($file);
        $bytes[strlen($bytes) - 10] = chr(ord($bytes[strlen($bytes) - 10]) ^ 0xFF);
        file_put_contents($file, $bytes);

        $this->artisan('pos:backup:verify')->assertFailed();

        $this->assertFalse(app(BackupStatus::class)->last('verify')['ok']);
        $this->assertStringContainsString('damaged', (string) app(BackupStatus::class)->last('verify')['message']);
        Notification::assertSentTo($this->owner, HealthAlert::class, fn (HealthAlert $alert): bool => $alert->check === 'verify_failed');
    }

    public function test_without_the_servers_verify_key_verify_checks_the_checksum_only(): void
    {
        $this->backups()->create();
        config(['pos.backup.identity' => null]);

        $this->artisan('pos:backup:verify')->assertSuccessful();

        $this->assertSame('checksum', app(BackupStatus::class)->lastOk('verify')['level']);
    }

    public function test_a_failed_backup_is_recorded_and_alerted_at_once(): void
    {
        Notification::fake();
        config(['pos.backup.recipients' => []]);

        $this->artisan('pos:backup')->assertFailed();

        $this->assertFalse(app(BackupStatus::class)->last('backup')['ok']);
        $this->assertNull(app(BackupStatus::class)->lastOk('backup'));
        Notification::assertSentTo($this->owner, HealthAlert::class, fn (HealthAlert $alert): bool => $alert->check === 'backup_failed');
        $this->assertSame([], glob("{$this->sandbox}/backups/*.age"), 'Nothing unencrypted or half made is left.');
        $this->assertSame([], glob("{$this->sandbox}/backups/work/*/*"));
    }

    public function test_only_the_newest_backups_stay_on_the_server(): void
    {
        foreach (range(1, 3) as $day) {
            $this->travel(1)->days();
            $this->backups()->create();
        }

        $this->assertCount(2, glob("{$this->sandbox}/backups/*.age"));
        $this->assertCount(3, $this->backups()->backups());
        $this->assertSame(basename((string) $this->backups()->latestLocal()), $this->backups()->backups()[0]);
    }

    public function test_retention_keeps_the_newest_of_14_days_and_12_months(): void
    {
        $names = [];

        // Two backups a day for 400 days.
        for ($day = 0; $day < 400; $day++) {
            foreach (['020000', '150000'] as $time) {
                $names[] = BackupService::PREFIX.now('UTC')->startOfDay()->subDays($day)->format('Ymd').'-'.$time.BackupService::SUFFIX;
            }
        }

        $kept = BackupService::retained($names, 14, 12);

        // The 14 most recent days (their afternoon backup) ...
        foreach (range(0, 13) as $day) {
            $this->assertContains(BackupService::PREFIX.now('UTC')->startOfDay()->subDays($day)->format('Ymd').'-150000'.BackupService::SUFFIX, $kept);
        }

        // ... and the newest of each of the 12 most recent months, nothing else.
        $months = array_unique(array_map(fn (string $name): string => (string) BackupService::timeOf($name)?->format('Y-m'), $kept));
        $this->assertCount(12, $months);
        $this->assertLessThanOrEqual(14 + 12, count($kept));
        $this->assertNotContains(BackupService::PREFIX.now('UTC')->startOfDay()->subDays(14)->format('Ymd').'-020000'.BackupService::SUFFIX, $kept);
    }

    public function test_prune_deletes_what_retention_does_not_keep_from_the_store(): void
    {
        config(['pos.backup.keep_daily' => 2, 'pos.backup.keep_monthly' => 1]);

        foreach (range(1, 4) as $day) {
            $this->travel(1)->days();
            $this->backups()->create();
        }

        $deleted = $this->backups()->prune();

        $this->assertCount(2, $deleted);
        $this->assertCount(2, $this->backups()->backups());
        $this->assertCount(4, $this->storedFiles(), 'Each kept backup keeps its checksum file.');
    }

    public function test_the_rclone_store_runs_rclone_with_the_clients_config(): void
    {
        Process::fake([
            '*lsf*' => Process::result("pos-backup-20261020-210000.tar.gz.age\npos-backup-20261020-210000.json\n"),
            '*' => Process::result(),
        ]);
        config(['pos.backup.binaries.rclone' => 'rclone']);
        $store = new RcloneStore('b2:webxkey-pos/clinic-production', '/srv/pos/clinic/rclone.conf');

        $store->put('/tmp/a.age', 'a.age');
        $this->assertSame(['pos-backup-20261020-210000.tar.gz.age', 'pos-backup-20261020-210000.json'], $store->list());
        $store->delete('a.age');

        Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['rclone', '--config', '/srv/pos/clinic/rclone.conf', 'copyto', '/tmp/a.age', 'b2:webxkey-pos/clinic-production/a.age']);
        Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['rclone', '--config', '/srv/pos/clinic/rclone.conf', 'deletefile', 'b2:webxkey-pos/clinic-production/a.age']);
    }
}
