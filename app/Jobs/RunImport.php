<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\ImportRun;
use App\Models\Shop;
use App\Services\Import\ImportService;
use App\Support\CurrentShop;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Writes a confirmed import (ImportService::run()): all of it in one
 * transaction, or nothing. Queued because thousands of rows take longer
 * than a web request may; the import page shows its progress.
 */
class RunImport implements ShouldQueue
{
    use Queueable;

    /** One attempt: a failed import is looked at and confirmed again, never retried blind. */
    public int $tries = 1;

    public int $timeout = 3600;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $shopId, public readonly int $runId)
    {
        $this->onQueue((string) config('pos.imports.queue', 'default'));
    }

    public function handle(ImportService $imports, CurrentShop $currentShop): void
    {
        $currentShop->runAs(Shop::query()->findOrFail($this->shopId), function () use ($imports): void {
            $run = ImportRun::query()->find($this->runId);

            if ($run !== null) {
                $imports->run($run);
            }
        });
    }

    /** Killed (a timeout, the worker stopped): the transaction was never committed, so nothing was written. */
    public function failed(?\Throwable $exception): void
    {
        $shop = Shop::query()->find($this->shopId);

        if ($shop === null) {
            return;
        }

        app(CurrentShop::class)->runAs($shop, function () use ($exception): void {
            ImportRun::query()
                ->whereKey($this->runId)
                ->whereIn('status', [ImportStatus::Queued->value, ImportStatus::Running->value])
                ->update([
                    'status' => ImportStatus::Failed->value,
                    'message' => 'The import stopped before it finished ('.($exception?->getMessage() ?: 'no reason given').'). Nothing was imported; confirm it again.',
                    'finished_at' => now(),
                ]);
        });
    }
}
