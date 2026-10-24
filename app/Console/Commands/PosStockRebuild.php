<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\StockService;
use App\Support\CurrentShop;
use Illuminate\Console\Command;

/**
 * Integrity check: recompute cached stock levels, and each batch's quantity,
 * from the stock ledger, and report batch-tracked items whose batches do not
 * add up to their level. With --costs, rebuild each location's average and
 * last cost from the ledger and report where they differ (--fix writes them,
 * audited). Safe to run any time (e.g. nightly from the scheduler).
 */
class PosStockRebuild extends Command
{
    protected $signature = 'pos:stock-rebuild
        {--costs : Also rebuild average and last costs from the ledger and report differences}
        {--fix : With --costs, write the rebuilt costs (each change is audited)}';

    protected $description = 'Recalculate stock levels and batches from the stock movement ledger';

    public function handle(StockService $stock, CurrentShop $currentShop): int
    {
        $clean = true;

        foreach (Shop::query()->cursor() as $shop) {
            $currentShop->runAs($shop, function () use ($stock, $shop, &$clean): void {
                $fixed = $stock->rebuildLevels();

                $fixed === 0
                    ? $this->components->info("{$shop->name}: all stock levels match the ledger.")
                    : $this->components->warn("{$shop->name}: corrected {$fixed} stock level(s).");

                $batches = $stock->rebuildBatches();

                if ($batches > 0) {
                    $this->components->warn("{$shop->name}: corrected {$batches} batch quantity(ies).");
                }

                foreach ($stock->batchMismatches() as $mismatch) {
                    $clean = false;
                    $this->components->error(sprintf(
                        '%s: %s at %s has %s on hand but %s in batches. Count its batches to fix it.',
                        $shop->name, $mismatch['sku'], $mismatch['location'], $mismatch['level'], $mismatch['batches'],
                    ));
                }

                if (! $this->option('costs')) {
                    return;
                }

                $differences = $stock->rebuildCosts((bool) $this->option('fix'));

                if ($differences === []) {
                    $this->components->info("{$shop->name}: every average cost matches the ledger.");

                    return;
                }

                $this->table(
                    ['Item', 'Location', 'Average now', 'Rebuilt', 'Last cost now', 'Rebuilt'],
                    array_map(fn (array $row): array => [
                        $row['sku'], $row['location'], $row['stored_average'], $row['rebuilt_average'], $row['stored_last'] ?? '-', $row['rebuilt_last'] ?? '-',
                    ], $differences),
                );

                if ($this->option('fix')) {
                    $this->components->warn("{$shop->name}: rebuilt ".count($differences).' cost(s) from the ledger (audited as cost_rebuilt).');
                } else {
                    $clean = false;
                    $this->components->warn("{$shop->name}: ".count($differences).' cost(s) differ from the ledger. Run with --costs --fix to write them.');
                }
            });
        }

        return $clean ? self::SUCCESS : self::FAILURE;
    }
}
