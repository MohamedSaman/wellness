<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Shop;
use Illuminate\Console\Command;

/**
 * Deletes each shop's audit log entries older than its
 * audit_log_retention_days (Administration → Security; 0 = keep all). The
 * one exception to "audit logs are never deleted" (docs/ARCHITECTURE.md,
 * rule 6), and it never deletes cost revaluations or rebuilds: the cost
 * rebuild (pos:stock-rebuild --costs) replays them. Nightly from the scheduler.
 */
class PosAuditPrune extends Command
{
    /** Entries the system reads back to rebuild figures: kept whatever their age. */
    public const KEPT_EVENTS = ['cost_revalued', 'cost_rebuilt'];

    protected $signature = 'pos:audit-prune {--dry-run : Only count what would be deleted}';

    protected $description = 'Delete audit log entries older than each shop\'s retention period';

    public function handle(): int
    {
        foreach (Shop::query()->get() as $shop) {
            $days = (int) $shop->setting('audit_log_retention_days');

            if ($days <= 0) {
                continue;
            }

            $old = AuditLog::query()
                ->where('shop_id', $shop->getKey())
                ->where('created_at', '<', now()->subDays($days))
                ->whereNotIn('event', self::KEPT_EVENTS);

            $count = $this->option('dry-run') ? $old->count() : $old->delete();

            $this->components->info(sprintf('%s: %s %d audit log entr%s older than %d days.', $shop->name, $this->option('dry-run') ? 'would delete' : 'deleted', $count, $count === 1 ? 'y' : 'ies', $days));
        }

        return self::SUCCESS;
    }
}
