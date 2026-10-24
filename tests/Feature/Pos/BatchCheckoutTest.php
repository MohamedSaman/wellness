<?php

namespace Tests\Feature\Pos;

use App\Enums\DrugSchedule;
use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Enums\StockMovementType;
use App\Enums\StorageCondition;
use App\Exceptions\PosException;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Services\CheckoutService;
use App\Services\Data\BatchPick;
use App\Services\Data\CartLine;
use App\Services\LocationService;
use App\Services\ProductCatalogService;
use App\Services\ReceiptService;
use App\Services\ReturnService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Selling, voiding and returning batch-tracked items (docs/PHARMACY.md §6.2,
 * §6.5): sale lines record their batches with the number and expiry as
 * sold, a pharmacist can pick a batch, and stock always goes back to the
 * exact batches it came from.
 */
class BatchCheckoutTest extends BatchTestCase
{
    public function test_a_box_and_loose_tablets_of_one_item_are_allocated_one_after_the_other(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'A', '11/2026', 12);
        $this->receive($panadol, 'B', '03/2027', 30);

        $sale = $this->sell([
            new CartLine($panadol->getKey(), 1, unitId: $this->boxOf($panadol)), // 10 tablets
            new CartLine($panadol->getKey(), 5),
        ]);

        [$box, $loose] = [$sale->items[0], $sale->items[1]];
        $this->assertSame(['A' => 10.0], $box->batches()->pluck('quantity', 'batch_no')->map(fn ($q) => (float) $q)->all());
        $this->assertSame(['A' => 2.0, 'B' => 3.0], $loose->batches()->pluck('quantity', 'batch_no')->map(fn ($q) => (float) $q)->all());
        $this->assertSame(['B' => 27.0], $this->batchQuantities($panadol));
        $this->assertLedgerMatches();
    }

    public function test_a_line_records_its_batches_as_sold_for_the_receipt(): void
    {
        $panadol = $this->medicine();
        $a = $this->receive($panadol, 'A', '11/2026', 4);
        $this->receive($panadol, 'B', '03/2027', 30);

        $sale = $this->sell([new CartLine($panadol->getKey(), 6)]);

        // A later correction of the batch never changes what the receipt says.
        DB::table('stock_batches')->where('id', $a->getKey())->update(['batch_no' => 'A-FIXED', 'expiry_date' => '2026-12-31']);

        $receipt = app(ReceiptService::class)->sale($sale->fresh() ?? $sale);
        $this->assertSame([
            ['batchNo' => 'A', 'expiry' => '11/2026', 'quantity' => '4'],
            ['batchNo' => 'B', 'expiry' => '03/2027', 'quantity' => '2'],
        ], $receipt['items'][0]['batches']);

        $piece = $sale->items->sole()->batches()->firstOrFail();
        $this->assertSame('1000.0000', $piece->batch_unit_cost);
        $this->assertSame($this->general->getKey(), $piece->location_id);
    }

    public function test_items_without_batches_sell_as_before(): void
    {
        $consultation = app(ProductCatalogService::class)->create(
            ['name' => 'Consultation', 'unit' => 'Visit', 'track_stock' => false],
            [['price' => 150_000]],
            user: $this->owner,
        )->variants()->firstOrFail();
        $cotton = app(ProductCatalogService::class)->create(
            ['name' => 'Cotton wool', 'unit' => 'Pack'],
            [['price' => 2_000, 'opening_stock' => 10]],
            user: $this->owner,
        )->variants()->firstOrFail();

        $this->assertFalse($consultation->product->track_batches);
        $this->assertFalse($cotton->product->track_batches);

        $sale = $this->sell([new CartLine($consultation->getKey(), 1), new CartLine($cotton->getKey(), 2)]);

        $this->assertSame(0, $sale->items->sum(fn (SaleItem $item): int => $item->batches()->count()));
        $this->assertNull(StockMovement::query()->where('type', StockMovementType::Sale)->sole()->stock_batch_id);
        $this->assertSame(0, StockBatch::query()->count());
        $this->assertReceiptHasNoBatches($sale);
    }

    public function test_a_pharmacist_may_pick_a_batch_and_only_a_usable_one(): void
    {
        $panadol = $this->medicine();
        $soon = $this->receive($panadol, 'SOON', '11/2026', 10);
        $later = $this->receive($panadol, 'LATER', '03/2027', 10);
        $expired = $this->foundExpired($panadol, 'GONE', '09/2026', 10);

        // A cashier may not choose: first expiry first out.
        try {
            $this->sell([new CartLine($panadol->getKey(), 2, batches: [new BatchPick($later->getKey(), 2)])]);
            $this->fail('A cashier chose a batch.');
        } catch (PosException $exception) {
            $this->assertStringContainsString('not allowed to choose a batch', $exception->getMessage());
        }

        // The owner (pos.batch_override) picks the later batch, e.g. for a long course.
        $sale = $this->sell([new CartLine($panadol->getKey(), 2, batches: [new BatchPick($later->getKey(), 2)])], cashier: $this->owner);
        $this->assertSame('LATER', $sale->items->sole()->batches()->sole()->batch_no);

        // A pick for part of the line; the rest is first expiry first out.
        $sale = $this->sell([new CartLine($panadol->getKey(), 5, batches: [new BatchPick($later->getKey(), 3)])], cashier: $this->owner);
        $this->assertSame(['LATER' => 3.0, 'SOON' => 2.0], $sale->items->sole()->batches()->get()->sortBy('id')->pluck('quantity', 'batch_no')->map(fn ($q) => (float) $q)->all());

        $refused = [
            'expired' => [1, [new BatchPick($expired->getKey(), 1)], 'had expired'],
            'short' => [9, [new BatchPick($later->getKey(), 9)], 'Only 5 Tablet left in batch LATER'],
            'unknown' => [1, [new BatchPick(999_999, 1)], 'not a batch of this item'],
            'more than the line' => [1, [new BatchPick($soon->getKey(), 1), new BatchPick($later->getKey(), 1)], 'More was picked'],
        ];

        foreach ($refused as $case => [$quantity, $picks, $message]) {
            try {
                $this->sell([new CartLine($panadol->getKey(), $quantity, batches: $picks)], cashier: $this->owner);
                $this->fail("A pick was accepted: {$case}.");
            } catch (PosException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage(), $case);
            }
        }

        $this->assertSame(3, Sale::query()->count() + 1, 'Refused picks record nothing.');
        $this->assertLedgerMatches();
    }

    public function test_the_till_api_carries_a_batch_pick(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'SOON', '11/2026', 10);
        $later = $this->receive($panadol, 'LATER', '03/2027', 10);

        $this->actingAs($this->owner)->postJson(route('pos.checkout'), [
            'device' => $this->till->uuid,
            'uuid' => (string) Str::uuid(),
            'lines' => [['variant_id' => $panadol->getKey(), 'quantity' => 2, 'batches' => [['batch_id' => $later->getKey(), 'quantity' => 2]]]],
            'payments' => [['method' => 'cash', 'amount' => '100']],
        ])->assertOk();

        $this->assertSame(['SOON' => 10.0, 'LATER' => 8.0], $this->batchQuantities($panadol));
    }

    public function test_a_shop_that_allows_negative_stock_books_the_rest_as_unallocated(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'A', '03/2027', 3);
        $this->shop->update(['settings' => ['allow_negative_stock' => true]]);

        $sale = $this->sell([new CartLine($panadol->getKey(), 5)]);

        $this->assertSame(['unallocated' => -2.0], $this->batchQuantities($panadol));
        $this->assertTrue($sale->needs_review);
        $this->assertSame(['batch_shortfall'], array_column($sale->review_reasons ?? [], 'type'));
        $this->assertSame(['A' => 3.0, '' => 2.0], $sale->items->sole()->batches()->get()->pluck('quantity', 'batch_no')->map(fn ($q) => (float) $q)->all());
        $this->assertTrue($sale->items->sole()->batches()->get()->last()?->is_unallocated);
        $this->assertSame([['batchNo' => 'A', 'expiry' => '03/2027', 'quantity' => '3'], ['batchNo' => null, 'expiry' => null, 'quantity' => '2']], app(ReceiptService::class)->sale($sale)['items'][0]['batches']);
        $this->assertLedgerMatches();
    }

    public function test_a_void_puts_every_piece_back_into_its_own_batch(): void
    {
        $panadol = $this->medicine();
        $a = $this->receive($panadol, 'A', '11/2026', 4, cost: 900);
        $b = $this->receive($panadol, 'B', '03/2027', 30, cost: 1_200);
        $this->receive($panadol, 'B', '03/2027', 10, $this->doctors);
        // A clinic cashier with no allowed locations works at the default location only
        // (docs/PHARMACY.md §4.4): this one may use both counters.
        app(LocationService::class)->setAllowedLocations($this->cashier, [(int) $this->general->getKey(), (int) $this->doctors->getKey()], $this->owner);

        $sale = $this->sell([
            new CartLine($panadol->getKey(), 6),
            new CartLine($panadol->getKey(), 3, locationId: $this->doctors->getKey()),
        ]);

        // Batch A expires before the void: it still takes its units back (not sellable).
        $this->travelTo(CarbonImmutable::parse('2026-12-05 04:30:00', 'UTC'));
        app(CheckoutService::class)->void($sale, $this->owner, 'Wrong patient');

        $this->assertSame(['A' => 4.0, 'B' => 30.0], $this->batchQuantities($panadol));
        $this->assertSame(['B' => 10.0], $this->batchQuantities($panadol, $this->doctors));

        $voids = StockMovement::query()->where('type', StockMovementType::SaleVoid)->orderBy('id')->get();
        $this->assertSame([[$a->getKey(), 4.0], [$b->getKey(), 2.0], [$this->batch($panadol, 'B', $this->doctors)->getKey(), 3.0]], $voids
            ->map(fn (StockMovement $m): array => [$m->stock_batch_id, (float) $m->quantity])->sortBy(fn (array $row) => $row[0])->values()->all());
        $this->assertSame($sale->items[0]->unit_cost, $voids->firstWhere('stock_batch_id', $a->getKey())?->unit_cost, 'At the line\'s cost.');
        $this->assertLedgerMatches();
    }

    public function test_returns_go_back_to_the_latest_expiring_batch_unless_told_otherwise(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'A', '11/2026', 4);
        $this->receive($panadol, 'B', '03/2027', 30);
        $sale = $this->sell([new CartLine($panadol->getKey(), 10)]); // A 4 + B 6
        $line = $sale->items->sole();
        $returns = app(ReturnService::class);

        $returns->process($sale, [$line->getKey() => 5], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);
        $this->assertSame(['B' => 29.0], $this->batchQuantities($panadol), 'Latest expiry first: 5 back into B.');

        $pieces = $line->batches()->get()->keyBy('batch_no');
        $returns->process($sale, [$line->getKey() => 2], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner,
            batchQuantities: [$line->getKey() => [$pieces['A']->getKey() => 2]]);
        $this->assertSame(['A' => 2.0, 'B' => 29.0], $this->batchQuantities($panadol));

        $this->assertSame([2.0, 5.0], [(float) $pieces['A']->fresh()?->returned_quantity, (float) $pieces['B']->fresh()?->returned_quantity]);

        try {
            $returns->process($sale, [$line->getKey() => 2], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner,
                batchQuantities: [$line->getKey() => [$pieces['B']->getKey() => 2]]);
            $this->fail('More came back from a batch than it sold.');
        } catch (PosException $exception) {
            $this->assertStringContainsString('Only 1 of', $exception->getMessage());
        }

        try {
            $returns->process($sale, [$line->getKey() => 2], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner,
                batchQuantities: [$line->getKey() => [$pieces['A']->getKey() => 1]]);
            $this->fail('Batch quantities that do not add up were accepted.');
        } catch (PosException $exception) {
            $this->assertStringContainsString('must add up to 2', $exception->getMessage());
        }

        // Not restocked (damaged): the batch's returned quantity still counts, no stock moves.
        $return = $returns->process($sale, [$line->getKey() => 3], ReturnReason::FaultyDamaged, PaymentMethod::Cash, $this->owner, restock: false);
        $this->assertSame(['A' => 2.0, 'B' => 29.0], $this->batchQuantities($panadol));
        $this->assertSame([4.0, 6.0], [(float) $pieces['A']->fresh()?->returned_quantity, (float) $pieces['B']->fresh()?->returned_quantity]);
        $this->assertSame([['batch_no' => 'B', 'quantity' => 1.0], ['batch_no' => 'A', 'quantity' => 2.0]], array_map(
            fn (array $row): array => ['batch_no' => $row['batch_no'], 'quantity' => (float) $row['quantity']],
            $return->items->sole()->batches ?? [],
        ));
        $this->assertLedgerMatches();
    }

    public function test_fridge_items_and_controlled_drugs_are_not_restocked_by_default(): void
    {
        $insulin = $this->medicine('Insulin', [], storage: StorageCondition::Fridge);
        $morphine = $this->medicine('Morphine', [], schedule: DrugSchedule::Controlled);
        $panadol = $this->medicine();

        foreach ([$insulin, $morphine, $panadol] as $i => $variant) {
            $this->receive($variant, "B{$i}", '03/2027', 5);
        }

        $sale = $this->sell([new CartLine($insulin->getKey(), 1), new CartLine($morphine->getKey(), 1, prescriber: 'Dr Perera'), new CartLine($panadol->getKey(), 1)]);

        $this->assertSame([false, false, true], $sale->items->map(fn (SaleItem $item): bool => ReturnService::restocksByDefault($item))->all());
    }

    private function assertReceiptHasNoBatches(Sale $sale): void
    {
        foreach (app(ReceiptService::class)->sale($sale)['items'] as $item) {
            $this->assertArrayNotHasKey('batches', $item);
        }
    }
}
