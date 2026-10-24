<?php

namespace App\Console\Commands;

use App\Notifications\HealthAlert;
use App\Services\HealthService;
use Illuminate\Console\Command;

/**
 * The System health checks on the command line; with --alert (hourly from
 * the scheduler) each failing check is mailed to the owners and the alert
 * address, at most once every alert_every_hours. Fails when a check fails.
 */
class PosHealth extends Command
{
    protected $signature = 'pos:health {--alert : Send an alert for each failing check}';

    protected $description = 'Check backups, disk, queue, scheduler and settings';

    public function handle(HealthService $health): int
    {
        $checks = $health->checks();

        $this->table(['Check', 'Status', 'Value', 'Detail'], array_map(fn (array $check): array => [
            $check['label'], strtoupper($check['status']), $check['value'], $check['detail'] ?? '',
        ], $checks));

        $failures = array_values(array_filter($checks, fn (array $check): bool => $check['status'] === 'fail'));

        if ($this->option('alert')) {
            foreach ($failures as $check) {
                $sent = $health->alert(new HealthAlert($check['key'], "{$check['label']}: {$check['value']}", (string) ($check['detail'] ?? '')));

                if ($sent) {
                    $this->components->warn("Alert sent: {$check['label']}.");
                }
            }
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }
}
