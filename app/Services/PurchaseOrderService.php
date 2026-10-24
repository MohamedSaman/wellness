<?php

namespace App\Services;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\PosException;
use App\Models\Location;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Shop;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Data\PurchaseLine;
use App\Support\CalendarDay;
use App\Support\CurrentShop;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Purchase orders (purchasing module, optional: goods can be received
 * without one). An order never moves stock. Its lines are in any unit the
 * item is bought in (a box, a strip, loose), with the cost expected per unit.
 *
 *   draft -> sent -> partially received -> received
 *     \--------\------------\-----------> cancelled
 *
 * A draft is edited freely; a sent order only changes by being received
 * against (GoodsReceiptService calls applyReceipt()) or cancelled. Orders
 * are never deleted, so PO numbers have no gaps. Needs purchasing.manage.
 */
class PurchaseOrderService
{
    public const MAX_LINES = 200;

    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly PurchaseLineResolver $lines,
        private readonly BatchService $batches,
        private readonly ReceivingItemService $items,
    ) {}

    /**
     * @param  list<PurchaseLine>  $lines
     */
    public function create(Supplier $supplier, Location $location, array $lines, User $user, ?string $expectedOn = null, ?string $notes = null): PurchaseOrder
    {
        $this->authorize($user);
        $this->assertSupplierAndLocation($supplier, $location);
        $prepared = $this->prepare($lines);
        $shop = $this->currentShop->getOrFail();

        return DB::transaction(function () use ($shop, $supplier, $location, $prepared, $user, $expectedOn, $notes): PurchaseOrder {
            // One number at a time per shop.
            Shop::query()->whereKey($shop->getKey())->lockForUpdate()->firstOrFail();

            $order = PurchaseOrder::query()->create([
                'po_number' => 'PO-'.str_pad((string) (PurchaseOrder::query()->count() + 1), 6, '0', STR_PAD_LEFT),
                'supplier_id' => $supplier->getKey(),
                'location_id' => $location->getKey(),
                'status' => PurchaseOrderStatus::Draft,
                'expected_on' => $this->expectedOn($expectedOn),
                'notes' => blank($notes) ? null : trim((string) $notes),
                'user_id' => $user->getKey(),
            ]);

            $this->saveLines($order, $prepared);
            // Items created on this order's lines while it was being entered.
            $this->items->link($order, array_column($prepared, 'product_variant_id'), $user);

            return $order->load('items');
        });
    }

    /**
     * Change a draft: its supplier, location, date, notes and lines (replaced).
     *
     * @param  list<PurchaseLine>  $lines
     */
    public function update(PurchaseOrder $order, Supplier $supplier, Location $location, array $lines, User $user, ?string $expectedOn = null, ?string $notes = null): PurchaseOrder
    {
        $this->authorize($user);
        $this->assertSupplierAndLocation($supplier, $location);
        $prepared = $this->prepare($lines);

        return DB::transaction(function () use ($order, $supplier, $location, $prepared, $user, $expectedOn, $notes): PurchaseOrder {
            $order = $this->lock($order);

            if ($order->status !== PurchaseOrderStatus::Draft) {
                throw new PosException("{$order->po_number} has been sent: cancel it and make a new order to change it.");
            }

            $order->update([
                'supplier_id' => $supplier->getKey(),
                'location_id' => $location->getKey(),
                'expected_on' => $this->expectedOn($expectedOn),
                'notes' => blank($notes) ? null : trim((string) $notes),
            ]);

            PurchaseOrderItem::query()->where('purchase_order_id', $order->getKey())->delete();
            $this->saveLines($order, $prepared);
            $this->items->link($order, array_column($prepared, 'product_variant_id'), $user);

            return $order->load('items');
        });
    }

    /** Mark a draft as sent to the supplier (print it from the order page). It can then be received against. */
    public function send(PurchaseOrder $order, User $user): PurchaseOrder
    {
        $this->authorize($user);

        return DB::transaction(function () use ($order): PurchaseOrder {
            $order = $this->lock($order);

            if ($order->status !== PurchaseOrderStatus::Draft) {
                throw new PosException("{$order->po_number} has already been sent.");
            }

            if (! $order->items()->exists()) {
                throw new PosException('Add at least one item before sending the order.');
            }

            $order->update(['status' => PurchaseOrderStatus::Sent, 'sent_at' => now()]);

            return $order;
        });
    }

    /** Nothing more is expected (draft, sent or partly received). What has arrived stays received. */
    public function cancel(PurchaseOrder $order, User $user): PurchaseOrder
    {
        $this->authorize($user);

        return DB::transaction(function () use ($order): PurchaseOrder {
            $order = $this->lock($order);

            if (in_array($order->status, [PurchaseOrderStatus::Received, PurchaseOrderStatus::Cancelled], true)) {
                throw new PosException("{$order->po_number} is {$order->status->label()} and can't be cancelled.");
            }

            $order->update(['status' => PurchaseOrderStatus::Cancelled, 'cancelled_at' => now()]);

            return $order;
        });
    }

    /**
     * Count goods received against (or, when reversed, taken back from) the
     * order's lines, and move its status on. Called by GoodsReceiptService
     * inside its posting transaction, with the order locked. A cancelled
     * order keeps its status.
     *
     * @param  array<int, float>  $baseQuantities  purchase order item id => paid base units (negative for a reversal)
     */
    public function applyReceipt(PurchaseOrder $order, array $baseQuantities): void
    {
        foreach ($order->items()->get() as $item) {
            $change = $baseQuantities[(int) $item->getKey()] ?? 0.0;

            if ($change != 0.0) {
                $item->update(['received_base_quantity' => max(0.0, Quantity::normalize((float) $item->received_base_quantity + $change))]);
            }
        }

        if ($order->status === PurchaseOrderStatus::Cancelled) {
            return;
        }

        $items = $order->items()->get();
        $received = $items->sum(fn (PurchaseOrderItem $item): float => (float) $item->received_base_quantity);

        $order->update(['status' => match (true) {
            $items->every(fn (PurchaseOrderItem $item): bool => $item->outstandingBase() <= 0) => PurchaseOrderStatus::Received,
            $received > 0 => PurchaseOrderStatus::PartiallyReceived,
            default => PurchaseOrderStatus::Sent,
        }]);
    }

    public function lock(PurchaseOrder $order): PurchaseOrder
    {
        return PurchaseOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
    }

    private function authorize(User $user): void
    {
        if (! $user->can('purchasing.manage')) {
            throw new PosException('You do not have permission to manage purchase orders.');
        }

        if (! $this->currentShop->getOrFail()->hasModule('purchasing')) {
            throw new PosException('Purchase orders need the purchasing module.');
        }
    }

    private function assertSupplierAndLocation(Supplier $supplier, Location $location): void
    {
        $shopId = (int) $this->currentShop->getOrFail()->getKey();

        if ((int) $supplier->shop_id !== $shopId || (int) $location->shop_id !== $shopId) {
            throw new \LogicException('Supplier and location must belong to the current shop.');
        }

        if (! $supplier->is_active) {
            throw new PosException("{$supplier->name} is not an active supplier.");
        }

        if (! $location->is_active) {
            throw new PosException("{$location->name} is not active.");
        }
    }

    private function expectedOn(?string $expectedOn): ?string
    {
        if (blank($expectedOn)) {
            return null;
        }

        $day = CalendarDay::parse((string) $expectedOn);

        if ($day->lessThan($this->batches->today()->subYear())) {
            throw new PosException('Check the expected date.');
        }

        return $day->toDateString();
    }

    /**
     * @param  list<PurchaseLine>  $lines
     * @return list<array<string, mixed>>
     */
    private function prepare(array $lines): array
    {
        if ($lines === []) {
            throw new PosException('Add at least one item to the order.');
        }

        if (count($lines) > self::MAX_LINES) {
            throw new PosException('An order can have at most '.self::MAX_LINES.' lines.');
        }

        $variants = $this->lines->variants(array_map(fn (PurchaseLine $line): int => $line->variantId, $lines));
        $prepared = [];

        foreach ($lines as $line) {
            $variant = $this->lines->variant($variants, $line->variantId);
            $unit = $this->lines->unit($variant, $line->unitId);
            $quantity = $this->lines->quantity($variant, $unit, $line->quantity);

            if ($quantity <= 0) {
                throw new PosException("Enter how many of {$variant->sku} to order.");
            }

            if ($line->unitCost < 0) {
                throw new PosException('A cost can\'t be below zero.');
            }

            $prepared[] = [
                'product_variant_id' => $variant->getKey(),
                'variant_unit_id' => $unit->getKey(),
                'unit_name' => $unit->name,
                'unit_factor' => (float) $unit->factor,
                'quantity' => $quantity,
                'base_quantity' => $unit->toBase($quantity),
                'unit_cost' => $line->unitCost,
                'line_total' => Money::multiply($line->unitCost, $quantity),
            ];
        }

        return $prepared;
    }

    /** @param  list<array<string, mixed>>  $prepared */
    private function saveLines(PurchaseOrder $order, array $prepared): void
    {
        foreach ($prepared as $i => $line) {
            $order->items()->create([...$line, 'sort_order' => $i]);
        }

        $order->update(['total' => array_sum(array_column($prepared, 'line_total'))]);
    }
}
