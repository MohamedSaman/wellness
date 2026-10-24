<?php

namespace Tests\Feature\Pos\Mysql;

use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Exceptions\PosException;
use App\Models\StockBatch;
use App\Services\BatchService;
use App\Services\CheckoutService;
use App\Services\Data\BatchPick;
use App\Services\Data\CartLine;
use App\Services\Data\TransferLine;
use App\Services\ReturnService;
use App\Services\StockService;
use App\Services\StockTransferService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Group;

/**
 * FEFO, expiry dates and batch quantities on MySQL: DATE and DECIMAL
 * columns, the batch identity index, and the shop-time expiry day, which
 * SQLite treats loosely.
 */
#[Group('mysql')]
class FefoMysqlTest extends MysqlTestCase
{
    public function test_fefo_split_return_void_and_transfer_on_mysql(): void
    {
        $panadol = $this->medicine();
        $this->foundExpired($panadol, 'GONE', '09/2026', 7);
        $this->receive($panadol, 'SOON', '11/2026', 10, cost: '933.3333');
        $later = $this->receive($panadol, 'LATER', '03/2027', 200);

        $sale = $this->sell([new CartLine($panadol->getKey(), 1, unitId: $this->boxOf($panadol)), new CartLine($panadol->getKey(), 5)]);
        $this->assertSame(['SOON' => 10.0], $sale->items[0]->batches()->pluck('quantity', 'batch_no')->map(fn ($q) => (float) $q)->all());
        $this->assertSame(['LATER' => 5.0], $sale->items[1]->batches()->pluck('quantity', 'batch_no')->map(fn ($q) => (float) $q)->all());
        $this->assertSame('2026-11-30', $sale->items[0]->batches()->sole()->expiry_date?->toDateString());

        try {
            $this->sell([new CartLine($panadol->getKey(), 1, batches: [new BatchPick($this->batch($panadol, 'GONE')->getKey(), 1)])], cashier: $this->owner);
            $this->fail('An expired batch was picked.');
        } catch (PosException $exception) {
            $this->assertStringContainsString('had expired', $exception->getMessage());
        }

        app(ReturnService::class)->process($sale, [$sale->items[1]->getKey() => 2], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);
        app(CheckoutService::class)->void($this->sell([new CartLine($panadol->getKey(), 4)]), $this->owner, 'Wrong item');
        app(StockTransferService::class)->create($this->general, $this->doctors, [new TransferLine($panadol->getKey(), 20)], $this->owner);
        app(StockTransferService::class)->create($this->general, $this->doctors, [new TransferLine($panadol->getKey(), 3)], $this->owner);

        $this->assertSame(['GONE' => 7.0, 'LATER' => 174.0], $this->batchQuantities($panadol));
        $this->assertSame(['LATER' => 23.0], $this->batchQuantities($panadol, $this->doctors));
        $this->assertSame(1, StockBatch::query()->where('location_id', $this->doctors->getKey())->count(), 'The identity index keeps one LATER at DOC.');
        $this->assertSame($later->expiry_date?->toDateString(), $this->batch($panadol, 'LATER', $this->doctors)->expiry_date?->toDateString());

        // A correction renames the batch at both locations; past sale lines keep what they printed.
        app(BatchService::class)->correct($this->batch($panadol, 'LATER'), 'LATER-2', '04/2027', 'Typo', $this->owner);
        $this->assertSame(['GONE' => 7.0, 'LATER-2' => 174.0], $this->batchQuantities($panadol));
        $this->assertSame(['LATER-2' => 23.0], $this->batchQuantities($panadol, $this->doctors));
        $this->assertSame('2027-04-30', $this->batch($panadol, 'LATER-2', $this->doctors)->expiry_date?->toDateString());
        $this->assertSame('LATER', $sale->items[1]->batches()->sole()->batch_no);

        $this->assertSame(0, app(StockService::class)->rebuildLevels());
        $this->assertSame(0, app(StockService::class)->rebuildBatches());
        $this->assertSame([], app(StockService::class)->rebuildCosts());
        $this->assertLedgerMatches();
    }

    public function test_the_expiry_day_ends_at_midnight_shop_time(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'OCT', '10/2026', 2);

        $this->travelTo(CarbonImmutable::parse('2026-10-31 18:29:00', 'UTC')); // 23:59 on 31 October in Colombo
        $this->sell([new CartLine($panadol->getKey(), 1)]);

        $this->travelTo(CarbonImmutable::parse('2026-10-31 18:31:00', 'UTC')); // 00:01 on 1 November in Colombo
        $this->expectException(PosException::class);
        $this->sell([new CartLine($panadol->getKey(), 1)]);
    }
}
