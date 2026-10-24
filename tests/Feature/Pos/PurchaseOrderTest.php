<?php

namespace Tests\Feature\Pos;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\PosException;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Services\Data\PurchaseLine;
use App\Services\Data\ReceiptLine;
use App\Services\GoodsReceiptService;
use App\Services\PurchaseOrderService;
use Tests\Feature\Pos\Concerns\WorksWithPurchasing;

/**
 * Purchase orders (purchasing, docs/PHARMACY.md §4.7): in any unit, never
 * moving stock; draft -> sent -> partially received -> received, or
 * cancelled; received in as many deliveries as it takes.
 */
class PurchaseOrderTest extends BatchTestCase
{
    use WorksWithPurchasing;

    private function orders(): PurchaseOrderService
    {
        return app(PurchaseOrderService::class);
    }

    /** @param  callable(): mixed  $attempt */
    private function refused(callable $attempt, string $message): void
    {
        try {
            $attempt();
            $this->fail("Expected: {$message}");
        } catch (PosException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    public function test_an_order_is_in_any_unit_and_never_moves_stock(): void
    {
        $panadol = $this->medicine();
        $amoxil = $this->medicine('Amoxil 250 mg Capsules');
        $supplier = $this->supplier();

        $order = $this->orders()->create($supplier, $this->general, [
            new PurchaseLine($panadol->getKey(), 10, $this->boxOf($panadol), 9_000),
            new PurchaseLine($amoxil->getKey(), 50, unitCost: 1_000),
        ], $this->owner, expectedOn: '2026-10-25', notes: 'Urgent');

        $this->assertSame(['PO-000001', PurchaseOrderStatus::Draft, 140_000, '2026-10-25'], [$order->po_number, $order->status, $order->total, $order->expected_on?->toDateString()]);
        $this->assertSame([['Box', 10.0, 100.0, 90_000], ['Tablet', 50.0, 50.0, 50_000]], $order->items->map(fn ($item): array => [$item->unit_name, (float) $item->quantity, (float) $item->base_quantity, $item->line_total])->all());
        $this->assertSame(0, StockMovement::query()->count());

        $order = $this->orders()->update($order, $supplier, $this->general, [new PurchaseLine($panadol->getKey(), 12, $this->boxOf($panadol), 9_000)], $this->owner);
        $this->assertSame([108_000, 1], [$order->total, $order->items->count()]);

        $order = $this->orders()->send($order, $this->owner);
        $this->assertSame(PurchaseOrderStatus::Sent, $order->status);
        $this->assertNotNull($order->sent_at);

        $this->refused(fn () => $this->orders()->update($order, $supplier, $this->general, [new PurchaseLine($panadol->getKey(), 1)], $this->owner), 'has been sent');
        $this->refused(fn () => $this->orders()->send($order, $this->owner), 'already been sent');
        $this->refused(fn () => $this->orders()->create($supplier, $this->general, [new PurchaseLine($panadol->getKey(), 0.5, $this->boxOf($panadol))], $this->owner), 'whole units only');
        $this->refused(fn () => $this->orders()->create($supplier, $this->general, [], $this->owner), 'at least one item');
        $this->refused(fn () => $this->orders()->create($supplier, $this->general, [new PurchaseLine($panadol->getKey(), 1)], $this->cashier), 'permission');

        $this->assertSame('PO-000002', $this->orders()->create($supplier, $this->doctors, [new PurchaseLine($panadol->getKey(), 1)], $this->owner)->po_number);
    }

    public function test_an_order_is_received_in_two_deliveries(): void
    {
        $panadol = $this->medicine();
        $amoxil = $this->medicine('Amoxil 250 mg Capsules');
        $supplier = $this->supplier();
        $order = $this->sentOrder($supplier, [
            new PurchaseLine($panadol->getKey(), 10, $this->boxOf($panadol), 9_000),
            new PurchaseLine($amoxil->getKey(), 50, unitCost: 1_000),
        ]);
        [$panadolLine, $amoxilLine] = $order->items->all();

        // First delivery: 6 of the 10 boxes, plus a box free (free goods don't count against the order).
        $this->postReceipt($supplier, [
            new ReceiptLine($panadol->getKey(), 6, $this->boxOf($panadol), freeQuantity: 1, unitCost: 9_000, batchNo: 'P1', expiry: '12/2027', purchaseOrderItemId: $panadolLine->getKey()),
        ], order: $order);

        $order->refresh();
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $order->status);
        $this->assertSame([60.0, 0.0], [(float) $panadolLine->fresh()?->received_base_quantity, (float) $amoxilLine->fresh()?->received_base_quantity]);
        $this->assertSame(['P1' => 70.0], $this->batchQuantities($panadol));

        // What is still to come, ready for the next GRN: 4 boxes and 50 capsules, at the order's costs.
        $outstanding = app(GoodsReceiptService::class)->outstandingLines($order);
        $this->assertSame([
            ['purchase_order_item_id' => $panadolLine->getKey(), 'variant_id' => $panadol->getKey(), 'unit_id' => $this->boxOf($panadol), 'quantity' => 4.0, 'unit_cost' => 9_000],
            ['purchase_order_item_id' => $amoxilLine->getKey(), 'variant_id' => $amoxil->getKey(), 'unit_id' => $amoxilLine->variant_unit_id, 'quantity' => 50.0, 'unit_cost' => 1_000],
        ], $outstanding);

        // Second delivery: the rest.
        $second = $this->postReceipt($supplier, array_map(fn (array $line): ReceiptLine => new ReceiptLine(
            $line['variant_id'], $line['quantity'], $line['unit_id'], unitCost: $line['unit_cost'], batchNo: 'B'.$line['variant_id'], expiry: '06/2028', purchaseOrderItemId: $line['purchase_order_item_id'],
        ), $outstanding), order: $order);

        $this->assertSame(PurchaseOrderStatus::Received, $order->fresh()?->status);
        $this->assertSame([], app(GoodsReceiptService::class)->outstandingLines($order));
        $this->refused(fn () => $this->draftReceipt($supplier, [new ReceiptLine($panadol->getKey(), 1)], order: $order->fresh()), 'Received: goods can only be received against a sent order');

        // Reversing the second delivery opens the order again.
        app(GoodsReceiptService::class)->reverse($second, 'Wrong batch numbers', $this->owner);
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $order->fresh()?->status);
        $this->assertSame([60.0, 0.0], [(float) $panadolLine->fresh()?->received_base_quantity, (float) $amoxilLine->fresh()?->received_base_quantity]);
        $this->assertLedgerMatches();
    }

    public function test_what_is_left_of_a_pack_is_received_in_base_units(): void
    {
        $panadol = $this->medicine();
        $supplier = $this->supplier();
        $order = $this->sentOrder($supplier, [new PurchaseLine($panadol->getKey(), 1, $this->boxOf($panadol), 9_000)]);
        $line = $order->items->sole();

        $this->postReceipt($supplier, [new ReceiptLine($panadol->getKey(), 5, unitCost: 900, batchNo: 'P1', expiry: '12/2027', purchaseOrderItemId: $line->getKey())], order: $order);

        $this->assertSame([['purchase_order_item_id' => $line->getKey(), 'variant_id' => $panadol->getKey(), 'unit_id' => null, 'quantity' => 5.0, 'unit_cost' => 900]], app(GoodsReceiptService::class)->outstandingLines($order));
    }

    public function test_goods_are_received_only_against_a_sent_order_of_that_supplier(): void
    {
        $panadol = $this->medicine();
        $amoxil = $this->medicine('Amoxil 250 mg Capsules');
        $supplier = $this->supplier();
        $line = fn (?int $orderItem = null): ReceiptLine => new ReceiptLine($panadol->getKey(), 10, unitCost: 1_000, batchNo: 'A', expiry: '12/2027', purchaseOrderItemId: $orderItem);

        $draftOrder = $this->orders()->create($supplier, $this->general, [new PurchaseLine($panadol->getKey(), 10)], $this->owner);
        $this->refused(fn () => $this->draftReceipt($supplier, [$line()], order: $draftOrder), 'Draft: goods can only be received against a sent order');

        $order = $this->orders()->send($draftOrder, $this->owner);
        $this->refused(fn () => $this->draftReceipt($this->supplier('State Pharmaceuticals'), [$line()], order: $order), 'an order to another supplier');
        $this->refused(fn () => $this->draftReceipt($supplier, [new ReceiptLine($amoxil->getKey(), 1, purchaseOrderItemId: $order->items->sole()->getKey())], order: $order), 'That order line is not');

        // Cancelled after the draft was saved: the draft can't be posted against it.
        $draft = $this->draftReceipt($supplier, [$line($order->items->sole()->getKey())], order: $order);
        $this->orders()->cancel($order, $this->owner);
        $this->refused(fn () => app(GoodsReceiptService::class)->post($draft, $this->owner), 'Cancelled: goods can only be received');
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_cancelling_keeps_what_arrived(): void
    {
        $panadol = $this->medicine();
        $supplier = $this->supplier();
        $order = $this->sentOrder($supplier, [new PurchaseLine($panadol->getKey(), 20)]);
        $this->postReceipt($supplier, [new ReceiptLine($panadol->getKey(), 5, unitCost: 1_000, batchNo: 'A', expiry: '12/2027', purchaseOrderItemId: $order->items->sole()->getKey())], order: $order);

        $cancelled = $this->orders()->cancel($order, $this->owner);
        $this->assertSame(PurchaseOrderStatus::Cancelled, $cancelled->status);
        $this->assertSame(5.0, (float) $order->items->sole()->fresh()?->received_base_quantity);
        $this->assertSame(5.0, $panadol->stockAt($this->general));
        $this->refused(fn () => $this->orders()->cancel($cancelled, $this->owner), 'Cancelled and can\'t be cancelled');

        $full = $this->sentOrder($supplier, [new PurchaseLine($panadol->getKey(), 5)]);
        $this->postReceipt($supplier, [new ReceiptLine($panadol->getKey(), 5, unitCost: 1_000, batchNo: 'A', expiry: '12/2027', purchaseOrderItemId: $full->items->sole()->getKey())], order: $full);
        $this->refused(fn () => $this->orders()->cancel($full, $this->owner), 'Received and can\'t be cancelled');

        $this->assertSame(2, PurchaseOrder::query()->count());
    }
}
