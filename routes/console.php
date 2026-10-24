<?php

use App\Jobs\QueueHeartbeat;
use App\Services\HealthService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| The server runs the scheduler every minute: a systemd timer per client
| (scripts/add-client.sh, docs/deploy/CLOUD.md), or in development
|   php artisan schedule:work
| The expiry digest checks every hour and goes out once a day per person,
| from 07:00 at each shop (docs/PHARMACY.md §9, branch 10).
*/
Schedule::command('pos:expiry-digest')->hourly()->withoutOverlapping();

/*
| Backups and health (docs/deploy/CLOUD.md). Times are the clinic's
| (POS_SCHEDULE_TIMEZONE), when nobody bills: the nightly backup and
| retention, the weekly restore check, the audit log retention, the hourly
| alerts, and the heartbeats System health reads for the scheduler and the
| queue worker.
*/
$timezone = (string) config('pos.schedule_timezone');

// In the background: a backup takes minutes, and the minute's other tasks must not wait for it.
Schedule::command('pos:backup')->dailyAt('02:30')->timezone($timezone)->withoutOverlapping(120)->runInBackground();
Schedule::command('pos:backup:verify')->weeklyOn(0, '04:00')->timezone($timezone)->withoutOverlapping(120)->runInBackground();
Schedule::command('pos:audit-prune')->dailyAt('03:30')->timezone($timezone);
Schedule::command('pos:health --alert')->hourlyAt(15);
Schedule::call(fn () => HealthService::schedulerRan())->everyMinute()->name('pos:scheduler-heartbeat');
Schedule::job(new QueueHeartbeat)->everyMinute()->name('pos:queue-heartbeat');
