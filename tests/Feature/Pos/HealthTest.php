<?php

namespace Tests\Feature\Pos;

use App\Filament\Pages\SystemHealth;
use App\Filament\Widgets\BackupStats;
use App\Jobs\QueueHeartbeat;
use App\Notifications\HealthAlert;
use App\Services\Backup\BackupStatus;
use App\Services\HealthService;
use App\Services\ModuleService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\UsesBackupSandbox;

/**
 * Monitoring (docs/deploy/CLOUD.md): /up for the uptime monitor, the checks
 * of System health and pos:health (backups, verify, disk, queue, scheduler,
 * debug, HTTPS), the alerts, the dashboard tiles and what each till reports.
 */
class HealthTest extends AdminTestCase
{
    use UsesBackupSandbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBackupSandbox(withAge: false);
        config(['queue.default' => 'database']);
    }

    /** @return array<string, array{key: string, label: string, status: string, value: string, detail: string|null}> */
    private function checks(): array
    {
        return collect(app(HealthService::class)->checks())->keyBy('key')->all();
    }

    private function heartbeats(): void
    {
        HealthService::schedulerRan();
        (new QueueHeartbeat)->handle();
    }

    public function test_up_answers_for_the_uptime_monitor(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_no_backup_yet_and_no_heartbeats_are_failures(): void
    {
        $checks = $this->checks();

        $this->assertSame('fail', $checks['backup']['status']);
        $this->assertSame('warn', $checks['verify']['status']);
        $this->assertSame('fail', $checks['queue']['status']);
        $this->assertSame('fail', $checks['scheduler']['status']);
        $this->assertSame('ok', $checks['database']['status']);
        $this->artisan('pos:health')->assertFailed();
    }

    public function test_a_recent_uploaded_backup_and_running_workers_are_healthy(): void
    {
        app(BackupStatus::class)->record('backup', true, ['name' => 'pos-backup-x.tar.gz.age', 'size' => 2048, 'uploaded' => true, 'message' => 'Uploaded.']);
        app(BackupStatus::class)->record('verify', true, ['name' => 'pos-backup-x.tar.gz.age', 'level' => 'restore', 'tables' => 40, 'rows' => 900, 'message' => 'Restored.']);
        $this->heartbeats();

        $checks = $this->checks();

        foreach (['backup', 'verify', 'queue', 'scheduler'] as $key) {
            $this->assertSame('ok', $checks[$key]['status'], $key.': '.$checks[$key]['detail']);
        }
    }

    public function test_no_backup_in_26_hours_or_one_kept_only_on_the_server_fails(): void
    {
        app(BackupStatus::class)->record('backup', true, ['size' => 1, 'uploaded' => true]);
        $this->travel(27)->hours();
        $this->assertSame('fail', $this->checks()['backup']['status']);
        $this->assertStringContainsString('None in 26 hours', (string) $this->checks()['backup']['detail']);

        app(BackupStatus::class)->record('backup', true, ['size' => 1, 'uploaded' => false]);
        $this->assertSame('fail', $this->checks()['backup']['status']);
    }

    public function test_stale_heartbeats_fail_after_five_minutes(): void
    {
        $this->heartbeats();
        $this->travel(6)->minutes();

        $this->assertSame('fail', $this->checks()['queue']['status']);
        $this->assertSame('fail', $this->checks()['scheduler']['status']);
    }

    public function test_debug_pages_and_plain_http_fail_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['app.debug' => true, 'app.url' => 'http://clinic.example.test']);

        $this->assertSame('fail', $this->checks()['debug']['status']);
        $this->assertSame('fail', $this->checks()['https']['status']);

        config(['app.debug' => false, 'app.url' => 'https://clinic.example.test']);
        $this->assertSame('ok', $this->checks()['debug']['status']);
        $this->assertSame('ok', $this->checks()['https']['status']);
    }

    public function test_a_waiting_server_restart_is_shown(): void
    {
        config(['pos.health.reboot_required_file' => "{$this->sandbox}/reboot-required"]);
        $this->assertSame('ok', $this->checks()['reboot']['status']);

        file_put_contents("{$this->sandbox}/reboot-required", '*** System restart required ***');
        $this->assertSame('warn', $this->checks()['reboot']['status']);
    }

    public function test_alerts_go_to_the_owners_and_the_alert_address_once_per_period(): void
    {
        Notification::fake();
        config(['pos.health.alert_email' => 'ops@webxkey.test']);

        $this->artisan('pos:health --alert')->assertFailed();
        $this->artisan('pos:health --alert')->assertFailed();

        // backup, queue and scheduler fail: one alert each, not one per run.
        Notification::assertSentToTimes($this->owner, HealthAlert::class, 3);
        Notification::assertNotSentTo($this->cashier, HealthAlert::class);
        Notification::assertSentOnDemand(HealthAlert::class, fn (HealthAlert $alert, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'ops@webxkey.test');

        $this->travel(13)->hours();
        $this->artisan('pos:health --alert')->assertFailed();
        Notification::assertSentToTimes($this->owner, HealthAlert::class, 6);
    }

    public function test_the_health_page_is_the_owners_and_shows_the_tills(): void
    {
        $this->till->forceFill(['last_seen_at' => now()->subMinutes(3), 'pending_uploads' => 4, 'clock_offset_seconds' => -125, 'reported_at' => now()->subMinutes(3)])->saveQuietly();

        $this->actingAs($this->owner)->get(SystemHealth::getUrl())
            ->assertOk()
            ->assertSee('data-check="backup"', false)
            ->assertSee('data-till="T1"', false)
            ->assertSeeText('Behind 2m 5s');

        $till = collect(app(HealthService::class)->tills())->firstWhere('code', 'T1');
        $this->assertSame(4, $till['pending']);
        $this->assertSame('warn', $till['status'], 'Offline sales waiting.');

        $this->actingAs($this->cashier)->get(SystemHealth::getUrl())->assertForbidden();
    }

    public function test_back_up_now_queues_a_backup_and_download_is_audited(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(SystemHealth::class)->callAction('backupNow');
        $this->assertSame(1, DB::table('jobs')->count());

        // Nothing on the server yet: the download is disabled.
        Livewire::test(SystemHealth::class)->assertActionDisabled('downloadBackup');

        @mkdir("{$this->sandbox}/backups", 0700, true);
        file_put_contents("{$this->sandbox}/backups/pos-backup-20261020-210000.tar.gz.age", 'age-encryption.org/v1 …');

        Livewire::test(SystemHealth::class)->callAction('downloadBackup')->assertFileDownloaded('pos-backup-20261020-210000.tar.gz.age');
        $this->assertDatabaseHas('audit_logs', ['event' => 'backup_downloaded', 'user_id' => $this->owner->getKey()]);
    }

    public function test_the_dashboard_shows_the_backup_tiles_to_the_owner_only(): void
    {
        $this->actingAs($this->owner);
        $this->assertTrue(BackupStats::canView());
        Livewire::test(BackupStats::class)->assertSeeText('Last backup')->assertSeeText('System health');

        $this->actingAs($this->cashier);
        $this->assertFalse(BackupStats::canView());
    }

    public function test_a_till_reports_its_waiting_sales_and_clock_with_each_sync(): void
    {
        $this->shop->update(['settings' => $this->shop->settings]);
        app(ModuleService::class)->enable($this->shop, 'offline_sync');
        $token = $this->till->issueSyncToken('test')->plainTextToken;

        $this->withToken($token)
            ->withHeaders(['X-Till-Pending' => '7', 'X-Till-Time' => now()->addMinutes(3)->toIso8601String()])
            ->getJson(route('pos.sync.catalog'))
            ->assertOk();

        $this->till->refresh();
        $this->assertSame(7, $this->till->pending_uploads);
        $this->assertEqualsWithDelta(180, $this->till->clock_offset_seconds, 2);
        $this->assertNotNull($this->till->reported_at);

        // An older till sends neither: the last report stands.
        $this->withToken($token)->getJson(route('pos.sync.catalog'))->assertOk();
        $this->assertSame(7, $this->till->refresh()->pending_uploads);
    }

    public function test_the_scheduler_runs_the_backups_and_checks(): void
    {
        Artisan::call('schedule:list');
        $schedule = Artisan::output();

        foreach (['pos:backup ', 'pos:backup:verify', 'pos:health --alert', 'pos:audit-prune', 'pos:queue-heartbeat', 'pos:scheduler-heartbeat'] as $task) {
            $this->assertStringContainsString($task, $schedule);
        }

        $this->assertNull(Cache::get(HealthService::SCHEDULER_HEARTBEAT));
    }
}
