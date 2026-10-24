<?php

namespace App\Jobs;

use App\Services\HealthService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/** Queued every minute by the scheduler: when it runs, the queue worker is alive (System health). */
class QueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Cache::forever(HealthService::QUEUE_HEARTBEAT, now()->getTimestamp());
    }
}
