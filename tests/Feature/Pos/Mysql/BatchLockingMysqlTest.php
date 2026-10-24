<?php

namespace Tests\Feature\Pos\Mysql;

use App\Models\Sale;
use App\Models\SaleItemBatch;
use App\Models\StockBatch;
use App\Models\StockLevel;
use App\Services\Data\CartLine;
use App\Services\Data\TransferLine;
use App\Services\OfflineSaleSyncService;
use App\Services\StockTransferService;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;

/**
 * Row locking on MySQL (docs/PHARMACY.md §6.2, §6.3): the stock level row is
 * the mutex for an item at a location, so tills selling at the same moment
 * never take the same units of a batch, never take a batch below zero, and
 * opposite transfers never deadlock.
 */
#[Group('mysql')]
class BatchLockingMysqlTest extends MysqlTestCase
{
    public function test_allocation_waits_for_the_stock_level_lock(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'A', '03/2027', 10);
        $levelId = StockLevel::query()->where('product_variant_id', $panadol->getKey())->value('id');

        // Another till holds the item's stock level at General Pharmacy.
        $other = DB::connection('mysql_other');
        $other->beginTransaction();
        $other->select('SELECT id FROM stock_levels WHERE id = ? FOR UPDATE', [$levelId]);

        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $this->sell([new CartLine($panadol->getKey(), 1)]);
            $this->fail('A sale took batches without the stock level lock.');
        } catch (QueryException|DeadlockException $exception) {
            // Laravel retries the checkout on a lock timeout, then gives up with DeadlockException.
            $this->assertStringContainsString('Lock wait timeout', $exception->getMessage());
        } finally {
            $other->rollBack();
        }

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(10.0, (float) $this->batch($panadol, 'A')->quantity);

        $this->sell([new CartLine($panadol->getKey(), 1)]);
        $this->assertSame(9.0, (float) $this->batch($panadol, 'A')->fresh()?->quantity);
    }

    public function test_tills_selling_at_once_never_oversell_a_batch(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'A', '03/2027', 5);

        $outcomes = $this->race(8, fn () => $this->sell([new CartLine($panadol->getKey(), 1)]));

        $this->assertSame(5, count(array_keys($outcomes, 'ok', true)), implode("\n", $outcomes));
        foreach (array_diff($outcomes, ['ok']) as $refused) {
            $this->assertStringContainsString('Not enough stock', $refused);
        }

        $this->assertSame(5, Sale::query()->count());
        $this->assertSame(0.0, (float) $this->batch($panadol, 'A')->quantity);
        $this->assertSame(0.0, $panadol->stockAt($this->general));
        $this->assertLedgerMatches();
    }

    public function test_concurrent_sales_split_first_expiry_first_without_going_below_zero(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'SOON', '11/2026', 10);
        $this->receive($panadol, 'LATER', '03/2027', 10);

        $outcomes = $this->race(6, fn () => $this->sell([new CartLine($panadol->getKey(), 3)]));

        $this->assertSame(array_fill(0, 6, 'ok'), $outcomes);
        $this->assertSame(['LATER' => 2.0], $this->batchQuantities($panadol));
        $this->assertSame(['SOON' => 10.0, 'LATER' => 8.0], SaleItemBatch::query()->get()->groupBy('batch_no')
            ->map(fn ($pieces): float => (float) $pieces->sum('quantity'))->sortKeys()->reverse()->all());

        // Whoever took LATER did so only once SOON had run out.
        foreach (SaleItemBatch::query()->get()->groupBy('sale_item_id') as $pieces) {
            $this->assertSame(3.0, (float) $pieces->sum('quantity'));
        }

        $this->assertLedgerMatches();
    }

    public function test_offline_replays_racing_online_sales_only_oversell_into_the_unallocated_batch(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'A', '03/2027', 3);
        app(OfflineSaleSyncService::class)->reserveInvoiceBlock($this->till);

        $outcomes = $this->race(5, function (int $worker) use ($panadol): void {
            if ($worker < 3) {
                $this->sell([new CartLine($panadol->getKey(), 1)]);

                return;
            }

            $result = app(OfflineSaleSyncService::class)->sync($this->till, [[
                'uuid' => (string) Str::uuid(),
                'invoice_no' => sprintf('T1-%06d', $worker + 1),
                'sold_at' => now()->subMinutes(5)->toIso8601String(),
                'cashier_id' => $this->cashier->getKey(),
                'lines' => [['variant_id' => $panadol->getKey(), 'quantity' => 2, 'unit_price' => 2_000]],
                'payments' => [['method' => 'cash', 'amount' => 4_000]],
                'grand_total' => 4_000,
            ]])[0];

            if ($result['status'] !== 'synced') {
                throw new \RuntimeException("Offline sale not synced: {$result['status']} {$result['message']}");
            }
        });

        // A paid offline sale is never refused; online sales can be, once stock runs out.
        $this->assertSame(['ok', 'ok'], array_slice($outcomes, 3));
        $sold = (float) SaleItemBatch::query()->sum('quantity');
        $this->assertSame(0.0, (float) $this->batch($panadol, 'A')->quantity);
        $this->assertSame(-($sold - 3), (float) $this->unallocated($panadol)?->quantity);
        $this->assertSame(1, DatabaseNotification::query()->count(), 'One alert when the unallocated batch first went below zero.');
        $this->assertLedgerMatches();
    }

    public function test_opposite_transfers_at_the_same_time_do_not_deadlock(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'A', '03/2027', 50);
        $this->receive($panadol, 'A', '03/2027', 50, $this->doctors);

        $outcomes = $this->race(6, fn (int $worker) => app(StockTransferService::class)->create(
            $worker % 2 === 0 ? $this->general : $this->doctors,
            $worker % 2 === 0 ? $this->doctors : $this->general,
            [new TransferLine($panadol->getKey(), 5)],
            $this->owner,
        ));

        $this->assertSame(array_fill(0, 6, 'ok'), $outcomes);
        $this->assertSame(['A' => 50.0], $this->batchQuantities($panadol));
        $this->assertSame(['A' => 50.0], $this->batchQuantities($panadol, $this->doctors));
        $this->assertSame(2, StockBatch::query()->count());
        $this->assertLedgerMatches();
    }
}
