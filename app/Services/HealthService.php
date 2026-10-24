<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Sale;
use App\Models\User;
use App\Notifications\HealthAlert;
use App\Services\Backup\BackupService;
use App\Services\Backup\BackupStatus;
use App\Support\CurrentShop;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * What the System health page and pos:health check (docs/deploy/CLOUD.md,
 * Monitoring): the release, debug off, HTTPS, the database and its size, the
 * disk, the queue worker and scheduler (heartbeats in the cache), the last
 * backup and its check, the owners' two-factor authentication, mail for
 * alerts, and each till's last sync, clock and offline sales waiting.
 *
 * @phpstan-type Check array{key: string, label: string, status: 'ok'|'warn'|'fail'|'info', value: string, detail: string|null}
 * @phpstan-type Till array{code: string, name: string, location: string|null, last_seen: CarbonImmutable|null, last_sale_synced: CarbonImmutable|null, pending: int|null, reported_at: CarbonImmutable|null, clock_offset: int|null, status: 'ok'|'warn'|'info'}
 */
class HealthService
{
    public const QUEUE_HEARTBEAT = 'pos:health:queue';

    public const SCHEDULER_HEARTBEAT = 'pos:health:scheduler';

    public function __construct(private readonly BackupStatus $backups) {}

    /** @return list<Check> */
    public function checks(): array
    {
        return [
            $this->check('version', 'Version', 'info', (string) config('pos.version'), 'Environment: '.app()->environment().'.'),
            $this->debug(),
            $this->https(),
            $this->database(),
            $this->disk(),
            $this->queue(),
            $this->scheduler(),
            $this->lastBackup(),
            $this->lastVerify(),
            $this->twoFactor(),
            $this->mail(),
            $this->reboot(),
        ];
    }

    /** @return list<Check> the checks that failed */
    public function failures(): array
    {
        return array_values(array_filter($this->checks(), fn (array $check): bool => $check['status'] === 'fail'));
    }

    /**
     * The current shop's tills: when each last talked to the server, its
     * last offline sale received, and what it last reported (offline sales
     * waiting to upload, its clock against the server's).
     *
     * @return list<Till>
     */
    public function tills(): array
    {
        $tolerance = 60 * (int) (app(CurrentShop::class)->get()?->setting('till_clock_tolerance_minutes') ?? 10);
        $lastSync = Sale::query()->whereNotNull('synced_at')->groupBy('device_id')->selectRaw('device_id, MAX(synced_at) as last_synced')->pluck('last_synced', 'device_id');

        return array_values(Device::query()
            ->with('location')
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->map(function (Device $device) use ($lastSync, $tolerance): array {
                $pending = $device->pending_uploads === null ? null : (int) $device->pending_uploads;
                $offset = $device->clock_offset_seconds === null ? null : (int) $device->clock_offset_seconds;
                $synced = $lastSync[$device->getKey()] ?? null;

                return [
                    'code' => (string) $device->code,
                    'name' => (string) $device->name,
                    'location' => $device->location?->name,
                    'last_seen' => $device->last_seen_at === null ? null : CarbonImmutable::parse($device->last_seen_at),
                    'last_sale_synced' => $synced === null ? null : CarbonImmutable::parse($synced),
                    'pending' => $pending,
                    'reported_at' => $device->reported_at === null ? null : CarbonImmutable::parse($device->reported_at),
                    'clock_offset' => $offset,
                    'status' => match (true) {
                        $device->last_seen_at === null => 'info',
                        ($pending ?? 0) > 0, $offset !== null && abs($offset) > $tolerance => 'warn',
                        default => 'ok',
                    },
                ];
            })
            ->all());
    }

    /**
     * Sends an alert to the owners and the alert address, at most once per
     * check every alert_every_hours (unless $force: a backup that just failed).
     */
    public function alert(HealthAlert $alert, bool $force = false): bool
    {
        $hours = max(1, (int) config('pos.health.alert_every_hours', 12));

        if (! $force && ! Cache::add("pos:health:alerted:{$alert->check}", true, now()->addHours($hours))) {
            return false;
        }

        try {
            $owners = User::query()->role('owner')->where('is_active', true)->get();
            Notification::send($owners, $alert);

            if (filled(config('pos.health.alert_email'))) {
                Notification::route('mail', (string) config('pos.health.alert_email'))->notify($alert);
            }
        } catch (Throwable $exception) {
            // Alerts must never break the backup or the check that raised them.
            report($exception);

            return false;
        }

        return true;
    }

    /** Called every minute by the scheduler. */
    public static function schedulerRan(): void
    {
        Cache::forever(self::SCHEDULER_HEARTBEAT, now()->getTimestamp());
    }

    /** @return Check */
    private function debug(): array
    {
        $debug = (bool) config('app.debug');

        return app()->isProduction() && $debug
            ? $this->check('debug', 'Debug pages', 'fail', 'On', 'APP_DEBUG=true in production shows code and settings to anyone who hits an error. Set APP_DEBUG=false.')
            : $this->check('debug', 'Debug pages', $debug ? 'info' : 'ok', $debug ? 'On (not production)' : 'Off', null);
    }

    /** @return Check */
    private function https(): array
    {
        $https = str_starts_with((string) config('app.url'), 'https://');

        return $this->check('https', 'HTTPS', $https ? 'ok' : (app()->isProduction() ? 'fail' : 'info'), (string) config('app.url'), $https ? null : 'APP_URL must start with https:// on a server on the internet.');
    }

    /** @return Check */
    private function database(): array
    {
        try {
            $connection = (string) config('database.default');
            DB::connection($connection)->select('select 1');
            $size = app(BackupService::class)->dumper($connection)->size($connection);

            return $this->check('database', 'Database', 'ok', $size === null ? 'Connected' : self::bytes($size), DB::connection($connection)->getDriverName().' · '.config("database.connections.{$connection}.database"));
        } catch (Throwable $exception) {
            return $this->check('database', 'Database', 'fail', 'Unreachable', $exception->getMessage());
        }
    }

    /** @return Check */
    private function disk(): array
    {
        $total = @disk_total_space(storage_path());
        $free = @disk_free_space(storage_path());

        if ($total === false || $free === false || $total <= 0) {
            return $this->check('disk', 'Disk', 'warn', 'Unknown', 'The free space could not be read.');
        }

        $used = (int) round(100 * ($total - $free) / $total);
        $limit = (int) config('pos.health.disk_warn_percent', 85);

        return $this->check('disk', 'Disk', $used >= $limit ? 'fail' : 'ok', "{$used}% used", self::bytes((int) $free).' free of '.self::bytes((int) $total).($used >= $limit ? ". Over {$limit}%: free space (old releases, logs) or resize the disk." : '.'));
    }

    /** @return Check */
    private function queue(): array
    {
        if (config('queue.default') === 'sync') {
            return $this->check('queue', 'Queue worker', 'info', 'Not used', 'QUEUE_CONNECTION=sync: jobs run in the request.');
        }

        return $this->heartbeat('queue', 'Queue worker', self::QUEUE_HEARTBEAT, (int) config('pos.health.queue_stale_minutes', 5), 'Imports wait until it runs: systemctl status pos-<client>-queue.');
    }

    /** @return Check */
    private function scheduler(): array
    {
        return $this->heartbeat('scheduler', 'Scheduler', self::SCHEDULER_HEARTBEAT, (int) config('pos.health.scheduler_stale_minutes', 5), 'No backups, checks or expiry digest without it: systemctl status pos-<client>-scheduler.timer.');
    }

    /** @return Check */
    private function heartbeat(string $key, string $label, string $cacheKey, int $staleMinutes, string $fix): array
    {
        $last = Cache::get($cacheKey);

        if (! is_int($last)) {
            return $this->check($key, $label, 'fail', 'Never seen', $fix);
        }

        $at = CarbonImmutable::createFromTimestamp($last);
        $stale = $at->lt(now()->subMinutes($staleMinutes));

        return $this->check($key, $label, $stale ? 'fail' : 'ok', 'Last ran '.$at->diffForHumans(), $stale ? $fix : null);
    }

    /** @return Check */
    private function lastBackup(): array
    {
        $ok = $this->backups->lastOk('backup');
        $last = $this->backups->last('backup');
        $hours = (int) config('pos.health.backup_stale_hours', 26);

        if ($ok === null) {
            return $this->check('backup', 'Last backup', 'fail', 'None', $last !== null ? 'Last attempt failed: '.$last['message'] : 'No backup has been made yet: php artisan pos:backup.');
        }

        $at = CarbonImmutable::parse((string) $ok['at']);
        $problems = [];

        if ($at->lt(now()->subHours($hours))) {
            $problems[] = "None in {$hours} hours.";
        }

        if ($last !== null && $last['ok'] === false) {
            $problems[] = 'The last attempt failed: '.$last['message'];
        }

        if (($ok['uploaded'] ?? false) !== true) {
            $problems[] = 'Kept on this server only: set POS_BACKUP_REMOTE to object storage at another provider.';
        }

        return $this->check('backup', 'Last backup', $problems === [] ? 'ok' : 'fail', $at->diffForHumans().' · '.self::bytes((int) ($ok['size'] ?? 0)), $problems === [] ? ($ok['message'] ?? null) : implode(' ', $problems));
    }

    /** @return Check */
    private function lastVerify(): array
    {
        $ok = $this->backups->lastOk('verify');
        $last = $this->backups->last('verify');
        $days = (int) config('pos.health.verify_stale_days', 8);

        if ($last !== null && $last['ok'] === false) {
            return $this->check('verify', 'Last backup check', 'fail', 'Failed '.CarbonImmutable::parse((string) $last['at'])->diffForHumans(), (string) $last['message']);
        }

        if ($ok === null) {
            return $this->check('verify', 'Last backup check', 'warn', 'Never', 'Restore the newest backup into the scratch database: php artisan pos:backup:verify (weekly from the scheduler).');
        }

        $at = CarbonImmutable::parse((string) $ok['at']);

        return match (true) {
            $at->lt(now()->subDays($days)) => $this->check('verify', 'Last backup check', 'fail', $at->diffForHumans(), "No check in {$days} days."),
            ($ok['level'] ?? null) !== 'restore' => $this->check('verify', 'Last backup check', 'warn', $at->diffForHumans().' (checksum only)', (string) $ok['message']),
            default => $this->check('verify', 'Last backup check', 'ok', $at->diffForHumans(), (string) $ok['message']),
        };
    }

    /** @return Check */
    private function twoFactor(): array
    {
        try {
            $without = User::query()->role((array) config('pos.security.two_factor_roles', []))->where('is_active', true)->whereNull('two_factor_confirmed_at')->pluck('email')->all();
        } catch (Throwable) {
            return $this->check('two_factor', 'Owner two-factor', 'warn', 'Unknown', null);
        }

        return $without === []
            ? $this->check('two_factor', 'Owner two-factor', 'ok', 'On for every owner', null)
            : $this->check('two_factor', 'Owner two-factor', 'warn', count($without).' without', implode(', ', $without).(config('pos.security.require_two_factor') ? ': blocked until they turn it on.' : ': not enforced on this install (POS_REQUIRE_2FA).'));
    }

    /** @return Check */
    private function mail(): array
    {
        $mailer = (string) config('mail.default');

        return in_array($mailer, ['log', 'array'], true)
            ? $this->check('mail', 'Mail for alerts', 'warn', 'Not set up', "MAIL_MAILER={$mailer}: alerts are written to the log and shown in the bell only.")
            : $this->check('mail', 'Mail for alerts', 'ok', $mailer, filled(config('pos.health.alert_email')) ? 'Alerts also go to '.config('pos.health.alert_email').'.' : 'Alerts go to the owners.');
    }

    /**
     * Security updates install every night without a reboot (provision-server.sh);
     * a kernel or library update asks for one here.
     *
     * @return Check
     */
    private function reboot(): array
    {
        $file = (string) config('pos.health.reboot_required_file', '/var/run/reboot-required');

        return is_file($file)
            ? $this->check('reboot', 'Server restart', 'warn', 'Needed', 'Security updates are waiting for a restart: sudo reboot after the clinic closes (tills keep billing offline meanwhile).')
            : $this->check('reboot', 'Server restart', 'ok', 'Not needed', null);
    }

    /**
     * @param  'ok'|'warn'|'fail'|'info'  $status
     * @return Check
     */
    private function check(string $key, string $label, string $status, string $value, ?string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'value' => $value, 'detail' => $detail];
    }

    public static function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? min(count($units) - 1, (int) floor(log($bytes, 1024))) : 0;

        return round($bytes / (1024 ** $power), $power === 0 ? 0 : 1).' '.$units[$power];
    }
}
