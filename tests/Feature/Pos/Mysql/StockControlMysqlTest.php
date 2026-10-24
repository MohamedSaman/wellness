<?php

namespace Tests\Feature\Pos\Mysql;

use App\Enums\AdjustmentReason;
use App\Enums\StockMovementType;
use App\Models\Sale;
use App\Models\StockBatch;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\StockRequest;
use App\Services\Data\CartLine;
use App\Services\Data\FulfilLine;
use App\Services\Data\RequestLine;
use App\Services\Data\WriteOffLine;
use App\Services\ExpiryService;
use App\Services\StockCountService;
use App\Services\StockRequestService;
use App\Services\StockWriteOffService;
use PHPUnit\Framework\Attributes\Group;

/**
 * Stock control on MySQL (docs/PHARMACY.md §9, branch 10): a count posted
 * while tills sell (its variances are counted less the snapshot taken when
 * each line was counted, so sales are not variances, and nothing deadlocks), a request fulfilled while General
 * Pharmacy sells, a write-off racing sales of the same batch, and the expiry
 * lists' day boundaries on MySQL's DATE.
 */
#[Group('mysql')]
class StockControlMysqlTest extends MysqlTestCase
{
    public function test_a_line_counted_then_sold_from_posts_counted_less_sold_with_no_phantom_gain(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'A', '2027-06-30', 50);
        $this->receive($panadol, 'B', '2027-09-30', 30);
        $counts = app(StockCountService::class);
        $count = $counts->start($this->general, $this->owner);
        $counts->enter($count->lines()->where('batch_no', 'A')->sole(), 48, null, $this->owner); // two missing
        $counts->enter($count->lines()->where('batch_no', 'B')->sole(), 30, null, $this->owner);

        // Tills sell from A after it was counted, while the owner posts.
        $outcomes = $this->race(7, function (int $worker) use ($count, $panadol): void {
            if ($worker === 0) {
                app(StockCountService::class)->post(StockCount::query()->findOrFail($count->getKey()), $this->owner);

                return;
            }

            $this->sell([new CartLine($panadol->getKey(), 1)]);
        });

        $this->assertSame(array_fill(0, 7, 'ok'), $outcomes);
        $sold = (float) Sale::query()->count();
        $this->assertSame(6.0, $sold);

        $a = $count->lines()->where('batch_no', 'A')->sole();
        $b = $count->lines()->where('batch_no', 'B')->sole();
        $this->assertSame(-2.0, (float) $a->variance, 'Only what the count found missing: the sales are not variances.');
        $this->assertSame(0.0, (float) $b->variance);
        $this->assertSame(48.0 - $sold, (float) StockBatch::query()->whereKey($a->stock_batch_id)->value('quantity'), 'Counted less what was sold since counting.');
        $this->assertSame(30.0, (float) StockBatch::query()->whereKey($b->stock_batch_id)->value('quantity'));
        $this->assertSame(-2.0, (float) StockMovement::query()->where('reference_type', (new StockCount)->getMorphClass())->sum('quantity'));
        $this->assertLedgerMatches();
    }

    public function test_a_count_posted_while_tills_sell_two_items_never_deadlocks(): void
    {
        $panadol = $this->medicine();
        $amoxil = $this->medicine('Amoxil 250 mg Capsules');
        $this->receive($panadol, 'A', '2027-06-30', 50);
        $this->receive($amoxil, 'M', '2027-06-30', 50);
        $counts = app(StockCountService::class);
        $count = $counts->start($this->general, $this->owner);

        foreach ($count->lines as $line) {
            $counts->enter($line, 50, null, $this->owner);
        }

        $outcomes = $this->race(7, function (int $worker) use ($count, $panadol, $amoxil): void {
            if ($worker === 0) {
                app(StockCountService::class)->post(StockCount::query()->findOrFail($count->getKey()), $this->owner);

                return;
            }

            // Lines locked in the other order than the count posts them in.
            $this->sell([new CartLine($amoxil->getKey(), 1), new CartLine($panadol->getKey(), 1)]);
        });

        $this->assertSame(array_fill(0, 7, 'ok'), $outcomes);

        foreach ($count->fresh()?->lines ?? [] as $line) {
            $this->assertSame(0.0, (float) $line->variance);
            $this->assertSame(44.0, (float) StockBatch::query()->whereKey($line->stock_batch_id)->value('quantity'));
        }

        $this->assertLedgerMatches();
    }

    public function test_a_request_sent_while_general_pharmacy_sells_never_oversells(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'SOON', '2026-12-31', 6);
        $this->receive($panadol, 'LATER', '2027-06-30', 9);
        $request = app(StockRequestService::class)->create($this->general, $this->doctors, [new RequestLine($panadol->getKey(), 10)], $this->owner);
        $itemId = (int) $request->items->sole()->getKey();

        $outcomes = $this->race(5, function (int $worker) use ($request, $itemId, $panadol): void {
            if ($worker === 0) {
                app(StockRequestService::class)->fulfil(StockRequest::query()->findOrFail($request->getKey()), [new FulfilLine($itemId, 10)], $this->owner);

                return;
            }

            $this->sell([new CartLine($panadol->getKey(), 2)]);
        });

        foreach ($outcomes as $outcome) {
            $this->assertTrue($outcome === 'ok' || str_contains($outcome, 'Not enough stock'), $outcome);
        }

        $sent = $outcomes[0] === 'ok' ? 10.0 : 0.0;
        $sold = 2.0 * (count(array_keys($outcomes, 'ok', true)) - ($outcomes[0] === 'ok' ? 1 : 0));
        $this->assertSame(15.0 - $sent - $sold, $panadol->stockAt($this->general));
        $this->assertSame($sent, $panadol->stockAt($this->doctors));
        $this->assertSame($sent, (float) $request->items->sole()->fresh()?->sent_base_quantity);
        $this->assertLedgerMatches();
    }

    public function test_a_write_off_racing_sales_of_the_same_batch_is_all_or_nothing(): void
    {
        $panadol = $this->medicine();
        $batch = $this->receive($panadol, 'SOON', '2026-11-30', 5);

        $outcomes = $this->race(4, function (int $worker) use ($batch, $panadol): void {
            if ($worker === 0) {
                app(StockWriteOffService::class)->writeOff([new WriteOffLine((int) $batch->getKey(), 5)], AdjustmentReason::Expired, $this->owner);

                return;
            }

            $this->sell([new CartLine($panadol->getKey(), 1)]);
        });

        $writtenOff = (float) abs((float) StockMovement::query()->where('type', StockMovementType::AdjustmentOut)->sum('quantity'));
        $sold = (float) Sale::query()->count();

        $this->assertTrue($writtenOff === 5.0 || $writtenOff === 0.0, implode("\n", $outcomes));
        $this->assertSame(5.0 - $writtenOff - $sold, (float) $batch->fresh()?->quantity);
        $this->assertGreaterThanOrEqual(0.0, (float) $batch->fresh()?->quantity);
        $this->assertLedgerMatches();
    }

    public function test_expiry_lists_on_mysql_dates(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'D90', '2027-01-16', 10);
        $this->receive($panadol, 'D91', '2027-01-17', 10);
        $this->foundExpired($panadol, 'TODAY', '2026-10-18', 1);
        $this->foundExpired($panadol, 'YDAY', '2026-10-17', 1);
        $expiry = app(ExpiryService::class);

        $this->assertSame(['TODAY', 'D90'], $expiry->query(ExpiryService::SOON)->pluck('batch_no')->all());
        $this->assertSame(['YDAY'], $expiry->query(ExpiryService::EXPIRED)->pluck('batch_no')->all());
        $this->assertSame(2, $expiry->summary()['soon']['batches']);
    }
}
