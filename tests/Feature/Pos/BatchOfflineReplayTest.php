<?php

namespace Tests\Feature\Pos;

use App\Enums\AdjustmentReason;
use App\Enums\StockMovementType;
use App\Exceptions\PosException;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Services\CheckoutService;
use App\Services\Data\BatchInput;
use App\Services\Data\CartLine;
use App\Services\Data\TransferLine;
use App\Services\LocationService;
use App\Services\OfflineSaleSyncService;
use App\Services\ReportService;
use App\Services\StockService;
use App\Services\StockTransferService;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

/**
 * Offline sales of batch-tracked items replayed on the server
 * (docs/PHARMACY.md §6.4, §7): the till's proposed batches are kept where
 * still valid, the rest re-allocated, every difference is a review reason,
 * and a paid sale is never rejected. What the batches can't cover goes to
 * the unallocated batch, which raises an alert and is settled by the next
 * receipt.
 */
class BatchOfflineReplayTest extends BatchTestCase
{
    private int $invoice = 0;

    protected function setUp(): void
    {
        parent::setUp();

        app(OfflineSaleSyncService::class)->reserveInvoiceBlock($this->till);
    }

    /**
     * One offline sale of $quantity tablets, as the till uploads it.
     *
     * @param  list<array{batch_id: int, quantity: float|int}>|null  $batches  the till's FEFO proposal
     * @return array<string, mixed>
     */
    private function payload(ProductVariant $variant, float $quantity, ?array $batches = null, ?string $soldAt = null, array $line = []): array
    {
        $this->invoice++;
        $total = (int) (($line['unit_price'] ?? 2_000) * $quantity);

        return [
            'uuid' => (string) Str::uuid(),
            'invoice_no' => sprintf('T1-%06d', $this->invoice),
            'sold_at' => $soldAt ?? now()->subMinutes(30)->toIso8601String(),
            'cashier_id' => $this->cashier->getKey(),
            'lines' => [[
                'variant_id' => $variant->getKey(),
                'quantity' => $quantity,
                'unit_price' => 2_000,
                ...($batches === null ? [] : ['batches' => $batches]),
                ...$line,
            ]],
            'payments' => [['method' => 'cash', 'amount' => $total]],
            'grand_total' => $total,
        ];
    }

    /** @param  array<string, mixed>  $payload */
    private function replay(array $payload): Sale
    {
        $result = app(OfflineSaleSyncService::class)->sync($this->till, [$payload])[0];

        $this->assertSame('synced', $result['status'], (string) $result['message']);

        return Sale::query()->where('uuid', $payload['uuid'])->firstOrFail();
    }

    /** @return list<string> */
    private function reviewTypes(Sale $sale): array
    {
        return array_column($sale->review_reasons ?? [], 'type');
    }

    public function test_a_valid_proposal_is_kept_even_when_it_is_not_first_expiry(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'SOON', '11/2026', 10);
        $later = $this->receive($panadol, 'LATER', '03/2027', 10);

        // The pharmacist handed over the later batch: that is what left the shelf.
        $sale = $this->replay($this->payload($panadol, 4, [['batch_id' => $later->getKey(), 'quantity' => 4]]));

        $this->assertFalse($sale->needs_review);
        $this->assertSame('LATER', $sale->items->sole()->batches()->sole()->batch_no);
        $this->assertNull($sale->items->sole()->till_batches, 'Nothing changed, so nothing to keep.');
        $this->assertSame(['SOON' => 10.0, 'LATER' => 6.0], $this->batchQuantities($panadol));
    }

    public function test_an_older_till_without_batches_is_allocated_first_expiry_first(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'SOON', '11/2026', 3);
        $this->receive($panadol, 'LATER', '03/2027', 10);

        $sale = $this->replay($this->payload($panadol, 5));

        $this->assertFalse($sale->needs_review);
        $this->assertSame(['SOON' => 3.0, 'LATER' => 2.0], $sale->items->sole()->batches()->get()->pluck('quantity', 'batch_no')->map(fn ($q) => (float) $q)->all());
    }

    public function test_a_proposal_that_no_longer_fits_is_re_allocated_and_kept_as_evidence(): void
    {
        $panadol = $this->medicine();
        $soon = $this->receive($panadol, 'SOON', '11/2026', 3);
        $this->receive($panadol, 'LATER', '03/2027', 10);

        // The till still thought SOON had 5 (another till sold from it meanwhile).
        $proposal = [['batch_id' => $soon->getKey(), 'quantity' => 5]];
        $sale = $this->replay($this->payload($panadol, 5, $proposal));
        $line = $sale->items->sole();

        $this->assertSame(['batch'], $this->reviewTypes($sale));
        $this->assertStringContainsString('Only 3 Tablet left in batch SOON', $sale->review_reasons[0]['message']);
        $this->assertStringContainsString('Recorded as SOON', $sale->review_reasons[0]['message']);
        $this->assertSame([['batch_id' => $soon->getKey(), 'quantity' => 5]], $line->till_batches);
        $this->assertSame(['SOON' => 3.0, 'LATER' => 2.0], $line->batches()->get()->pluck('quantity', 'batch_no')->map(fn ($q) => (float) $q)->all());
        $this->assertLedgerMatches();
    }

    public function test_a_batch_that_had_expired_at_the_sale_is_flagged(): void
    {
        $panadol = $this->medicine();
        $expired = $this->foundExpired($panadol, 'GONE', '09/2026', 10);
        $this->receive($panadol, 'GOOD', '03/2027', 10);

        $sale = $this->replay($this->payload($panadol, 2, [['batch_id' => $expired->getKey(), 'quantity' => 2]]));

        $this->assertSame(['batch', 'expired_sold'], $this->reviewTypes($sale));
        $this->assertSame('GOOD', $sale->items->sole()->batches()->sole()->batch_no, 'An expired batch is never allocated.');
        $this->assertSame(10.0, (float) $expired->fresh()?->quantity);
    }

    public function test_the_sale_date_decides_expiry_not_the_upload_date(): void
    {
        $panadol = $this->medicine();
        $october = $this->receive($panadol, 'OCT', '10/2026', 10); // last day 31/10/2026

        // Sold on 30 October, uploaded on 2 November: the batch was still good.
        $this->travelTo(CarbonImmutable::parse('2026-11-02 04:30:00', 'UTC'));
        $sale = $this->replay($this->payload($panadol, 2, [['batch_id' => $october->getKey(), 'quantity' => 2]], soldAt: '2026-10-30T10:00:00+05:30'));

        $this->assertFalse($sale->needs_review);
        $this->assertSame('OCT', $sale->items->sole()->batches()->sole()->batch_no);
    }

    public function test_an_oversell_goes_to_the_unallocated_batch_and_raises_an_alert(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'A', '03/2027', 3);

        $sale = $this->replay($this->payload($panadol, 5));

        $this->assertSame(['batch_shortfall'], $this->reviewTypes($sale));
        $this->assertEquals(2, $sale->review_reasons[0]['quantity']);
        $this->assertSame(['unallocated' => -2.0], $this->batchQuantities($panadol));
        $this->assertSame(-2.0, $panadol->stockAt($this->general));

        $alert = DatabaseNotification::query()->sole();
        $this->assertTrue($alert->notifiable()->firstOrFail()->is($this->owner), 'The owner (batches.manage) is told; the cashier is not.');
        $this->assertStringContainsString('2 Tablet below its recorded batches', (string) ($alert->data['body'] ?? ''));

        // Selling more of it does not repeat the alert while it is still below zero.
        $this->replay($this->payload($panadol, 1));
        $this->assertSame(1, DatabaseNotification::query()->count());
        $this->assertSame(['unallocated' => -3.0], $this->batchQuantities($panadol));

        // Never offered by FEFO, never in an expiry list.
        $this->assertFalse(StockBatch::query()->expiringBy(now()->addYears(20))->where('is_unallocated', true)->exists());
        $this->assertLedgerMatches();
    }

    public function test_the_next_receipt_settles_the_unallocated_batch(): void
    {
        $panadol = $this->medicine();
        $this->replay($this->payload($panadol, 4));

        $delivery = $this->receive($panadol, 'NEW', '03/2027', 100);

        // The 4 oversold tablets are attributed to the batch that arrived; the level is unchanged by it.
        $this->assertSame(['NEW' => 96.0], $this->batchQuantities($panadol));
        $this->assertSame(0.0, (float) $this->unallocated($panadol)?->quantity);
        $this->assertSame(96.0, $panadol->stockAt($this->general));

        $pair = StockMovement::query()->whereIn('type', [StockMovementType::BatchCorrectionOut, StockMovementType::BatchCorrectionIn])->orderBy('id')->get();
        $this->assertSame([[StockMovementType::BatchCorrectionOut, -4.0, $delivery->getKey()], [StockMovementType::BatchCorrectionIn, 4.0, $this->unallocated($panadol)?->getKey()]], $pair
            ->map(fn (StockMovement $m): array => [$m->type, (float) $m->quantity, $m->stock_batch_id])->all());
        // The level was -4 before the delivery and 96 after it; the pair nets to zero.
        $this->assertSame([92.0, 96.0], $pair->map(fn (StockMovement $m): float => (float) $m->balance_after)->all());
        $this->assertLedgerMatches();
    }

    public function test_a_partial_receipt_settles_what_it_can(): void
    {
        $panadol = $this->medicine();
        $this->replay($this->payload($panadol, 10));
        $this->receive($panadol, 'SMALL', '03/2027', 4);

        $this->assertSame(['SMALL' => 0.0, 'unallocated' => -6.0], ['SMALL' => (float) $this->batch($panadol, 'SMALL')->quantity, 'unallocated' => (float) $this->unallocated($panadol)?->quantity]);
        $this->assertLedgerMatches();
    }

    public function test_voiding_an_oversold_sale_after_it_was_settled_returns_the_units_to_the_settling_batch(): void
    {
        $panadol = $this->medicine();
        $sale = $this->replay($this->payload($panadol, 4));
        $this->receive($panadol, 'NEW', '03/2027', 100);

        app(CheckoutService::class)->void($sale, $this->owner, 'Customer cancelled');

        $this->assertSame(['NEW' => 100.0], $this->batchQuantities($panadol));
        $this->assertSame(0.0, (float) $this->unallocated($panadol)?->quantity);

        // Voided before any delivery: straight back to the unallocated batch.
        $other = $this->replay($this->payload($panadol, 105));
        $this->assertSame(['NEW' => 0.0, 'unallocated' => -5.0], ['NEW' => (float) $this->batch($panadol, 'NEW')->quantity, 'unallocated' => (float) $this->unallocated($panadol)?->quantity]);
        app(CheckoutService::class)->void($other, $this->owner, 'Duplicate');
        $this->assertSame(['NEW' => 100.0], $this->batchQuantities($panadol));
        $this->assertLedgerMatches();
    }

    public function test_the_owner_can_settle_by_hand_from_a_batch_on_record(): void
    {
        $panadol = $this->medicine();
        $shelf = $this->receive($panadol, 'SHELF', '03/2027', 10);
        // The till did not know about SHELF yet and sold beyond what it had.
        $this->replay($this->payload($panadol, 12, [['batch_id' => 999_999, 'quantity' => 12]]));
        $this->assertSame(['SHELF' => 0.0, 'unallocated' => -2.0], ['SHELF' => (float) $shelf->fresh()?->quantity, 'unallocated' => (float) $this->unallocated($panadol)?->quantity]);

        $this->receive($panadol, 'SHELF', '03/2027', 5); // more of the same batch: settles 2 of it
        $this->assertSame(['SHELF' => 3.0], $this->batchQuantities($panadol));

        $this->replay($this->payload($panadol, 4));
        try {
            app(StockService::class)->settleUnallocated($shelf->fresh() ?? $shelf, null, $this->cashier);
            $this->fail('A cashier settled unallocated stock.');
        } catch (PosException $exception) {
            $this->assertStringContainsString('permission', $exception->getMessage());
        }

        // A count finds a batch that was never received (counts never settle by themselves):
        // the owner attributes the oversold tablet to it.
        app(StockService::class)->adjustBatchTo($panadol, $this->general, new BatchInput('FOUND', '06/2027'), 3, AdjustmentReason::CountCorrection, $this->owner);
        app(StockService::class)->settleUnallocated($this->batch($panadol, 'FOUND'), null, $this->owner);
        $this->assertSame(['SHELF' => 0.0, 'FOUND' => 2.0, 'unallocated' => 0.0], [
            'SHELF' => (float) $shelf->fresh()?->quantity, 'FOUND' => (float) $this->batch($panadol, 'FOUND')->quantity, 'unallocated' => (float) $this->unallocated($panadol)?->quantity,
        ]);

        try {
            app(StockService::class)->settleUnallocated($this->batch($panadol, 'FOUND'), null, $this->owner);
            $this->fail('Settled with nothing to settle.');
        } catch (PosException $exception) {
            $this->assertStringContainsString('Nothing to settle', $exception->getMessage());
        }

        $this->assertLedgerMatches();
    }

    public function test_the_sync_endpoint_accepts_proposals_units_and_locations(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'GEN1', '03/2027', 50);
        $doc = $this->receive($panadol, 'DOC1', '06/2027', 50, $this->doctors);
        $token = $this->till->issueSyncToken('Clinic till')->plainTextToken;
        // A clinic cashier with no allowed locations works at the default location only
        // (docs/PHARMACY.md §4.4): this one may use both counters.
        app(LocationService::class)->setAllowedLocations($this->cashier, [(int) $this->general->getKey(), (int) $this->doctors->getKey()], $this->owner);

        $box = $this->payload($panadol, 1, [['batch_id' => $doc->getKey(), 'quantity' => 10]], line: ['unit_id' => $this->boxOf($panadol), 'location_id' => $this->doctors->getKey(), 'unit_price' => 18_000]);
        $unknownUnit = $this->payload($panadol, 2, line: ['unit_id' => 999_999]);

        $this->withToken($token)->postJson(route('pos.sync.sales'), ['sales' => [$box, $unknownUnit]])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'synced')
            ->assertJsonPath('results.0.needs_review', false)
            ->assertJsonPath('results.1.status', 'synced')
            ->assertJsonPath('results.1.needs_review', true);

        $this->assertSame(['DOC1' => 40.0], $this->batchQuantities($panadol, $this->doctors));
        $this->assertSame(['GEN1' => 48.0], $this->batchQuantities($panadol));
        $this->assertSame(['unit'], $this->reviewTypes(Sale::query()->where('uuid', $unknownUnit['uuid'])->firstOrFail()));

        // A malformed proposal is a malformed payload.
        $this->withToken($token)->postJson(route('pos.sync.sales'), ['sales' => [$this->payload($panadol, 1, [['batch_id' => 'x']])]])
            ->assertJsonPath('results.0.status', 'rejected');
    }

    public function test_an_online_sale_stops_at_what_is_really_on_hand_while_an_oversell_is_unsettled(): void
    {
        $panadol = $this->medicine();
        $this->replay($this->payload($panadol, 3)); // sold offline, nothing on record at GEN
        $this->receive($panadol, 'DOC1', '03/2027', 5, $this->doctors);

        // A transfer is not a receipt: it does not settle, so GEN holds 5 in DOC1 but only 2 on hand.
        app(StockTransferService::class)->create($this->doctors, $this->general, [new TransferLine($panadol->getKey(), 5)], $this->owner);
        $this->assertSame(['unallocated' => -3.0, 'DOC1' => 5.0], $this->batchQuantities($panadol));

        try {
            $this->sell([new CartLine($panadol->getKey(), 4)]);
            $this->fail('An online sale went below what is on hand.');
        } catch (PosException $exception) {
            $this->assertStringContainsString('2 on hand, 4 requested. 3 Tablet sold beyond the recorded batches are not settled yet', $exception->getMessage());
        }

        $this->sell([new CartLine($panadol->getKey(), 2)]);
        $this->assertSame(0.0, $panadol->stockAt($this->general));
        $this->assertLedgerMatches();

        // The stock movements report names each movement's batch.
        $rows = app(ReportService::class)->stockMovements(ReportPeriod::today($this->shop), false, 1, 50)->items();
        $this->assertSame([...array_fill(0, 4, 'DOC1 · exp 03/2027'), 'Unallocated'], array_column($rows, 'batch'));
    }
}
