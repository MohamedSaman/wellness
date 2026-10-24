<?php

namespace App\Services;

use App\Enums\GoodsReceiptStatus;
use App\Enums\StockMovementType;
use App\Exceptions\PosException;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Data\BatchInput;
use App\Services\Data\GoodsReceiptData;
use App\Services\Data\ReceiptLine;
use App\Support\BatchExpiry;
use App\Support\CalendarDay;
use App\Support\CurrentShop;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\UnitCost;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Goods received notes (purchasing module, docs/PHARMACY.md §4.8), with or
 * without a purchase order. Entering one needs purchasing.receive.
 *
 * A GRN is saved as a draft (edited freely, no stock moves) and then
 * posted. Posting, in one transaction:
 *   - numbers it (GRN-000001 per shop);
 *   - brings each line in through StockService as a stock_in at the GRN's
 *     location, in base units (a box of 100 is 100 tablets): a batch-tracked
 *     item into its batch (created or topped up; BatchService checks the
 *     number and expiry, and short expiry needs the owner's go-ahead), which
 *     first settles any unallocated (oversold) stock there; and re-averages
 *     the location's cost at the line's effective cost per base unit:
 *
 *       gross           = quantity x unit cost
 *       line total      = gross - line discount - share of the invoice discount
 *                         + line tax + share of the invoice tax
 *       base quantity   = (quantity + free quantity) x unit factor
 *       cost per base   = line total / base quantity   (4 places, Q9)
 *
 *     so free goods lower the cost: 10 boxes of 100 at 1,000.00 plus 1 box
 *     free, less 5%, is 9,500.00 over 1,100 tablets = 8.6364 a tablet.
 *     Tax (VAT) on the invoice is part of the cost (the shop does not
 *     reclaim it): entered per line, or once for the invoice and spread over
 *     the lines by their value after discounts, never both;
 *   - counts the paid quantity against the purchase order's lines.
 *
 * A posted GRN never changes. A mistake is put right with a reversing GRN
 * (reverse(), purchasing.manage): the same quantities leave the same
 * batches at the same costs, the location's average is un-averaged
 * exactly, the order's received quantities go back down, and its total is
 * negative, so supplier balances and purchases by month net out.
 *
 * Posting also makes the GRN an open item owed to the supplier
 * (SupplierPayablesService), due on the invoice date (else the date
 * received) + the supplier's payment terms; reversing it closes that item
 * and turns what was paid on it back into credit.
 */
class GoodsReceiptService
{
    public const MAX_LINES = 200;

    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly StockService $stock,
        private readonly BatchService $batches,
        private readonly PurchaseLineResolver $lines,
        private readonly PurchaseOrderService $orders,
        private readonly ReceivingItemService $items,
        private readonly SupplierPayablesService $payables,
    ) {}

    /** Save a new draft, or replace a draft's header and lines. Nothing moves stock. */
    public function saveDraft(?GoodsReceipt $draft, GoodsReceiptData $data, User $user): GoodsReceipt
    {
        $this->authorize($user, 'purchasing.receive', 'enter goods received');

        $supplier = Supplier::query()->findOrFail($data->supplierId);
        $location = Location::query()->findOrFail($data->locationId);
        $order = $data->purchaseOrderId === null ? null : PurchaseOrder::query()->findOrFail($data->purchaseOrderId);

        if (! $supplier->is_active) {
            throw new PosException("{$supplier->name} is not an active supplier.");
        }

        if (! $location->is_active) {
            throw new PosException("{$location->name} is not active.");
        }

        if ($order !== null) {
            $this->assertReceivable($order, $supplier);
        }

        $receivedOn = $this->day($data->receivedOn, 'received') ?? $this->batches->today()->toDateString();
        $invoiceDate = $this->day($data->supplierInvoiceDate, 'invoice');
        $lines = $this->prepare($data->lines, $order);
        $amounts = $this->amounts($lines, $data->invoiceDiscount, $data->invoiceTax);

        return DB::transaction(function () use ($draft, $data, $user, $supplier, $location, $order, $receivedOn, $invoiceDate, $lines, $amounts): GoodsReceipt {
            $attributes = [
                'supplier_id' => $supplier->getKey(),
                'location_id' => $location->getKey(),
                'purchase_order_id' => $order?->getKey(),
                'supplier_invoice_no' => $this->text($data->supplierInvoiceNo, 50),
                'supplier_invoice_date' => $invoiceDate,
                'received_on' => $receivedOn,
                'notes' => $this->text($data->notes),
                ...$amounts['header'],
            ];

            if ($draft === null) {
                $receipt = GoodsReceipt::query()->create([...$attributes, 'status' => GoodsReceiptStatus::Draft, 'user_id' => $user->getKey()]);
            } else {
                $receipt = $this->lockDraft($draft);
                $receipt->update($attributes);
                GoodsReceiptItem::query()->where('goods_receipt_id', $receipt->getKey())->delete();
            }

            foreach ($lines as $i => $line) {
                $receipt->items()->create([...$line, ...$amounts['lines'][$i], 'sort_order' => $i]);
            }

            // Items created on this GRN's lines while it was being entered.
            $this->items->link($receipt, array_column($lines, 'product_variant_id'), $user);

            return $receipt->load('items');
        });
    }

    /** A draft that won't be posted. A posted GRN is reversed instead. */
    public function deleteDraft(GoodsReceipt $draft, User $user): void
    {
        $this->authorize($user, 'purchasing.receive', 'enter goods received');

        DB::transaction(function () use ($draft): void {
            $receipt = $this->lockDraft($draft);
            GoodsReceiptItem::query()->where('goods_receipt_id', $receipt->getKey())->delete();
            $receipt->delete();
        });
    }

    /**
     * Post a draft: bring its stock in, number it, count it against its
     * order. All lines or none.
     *
     * Lock order: the shop row (GRN numbers, one posting at a time per shop),
     * the GRN, its order, then each item's row and stock level in item order,
     * as everywhere stock is received. The shop is locked before anything is
     * read, so on MySQL the transaction's snapshot (REPEATABLE READ) is taken
     * after the previous posting committed: its number, and the order's
     * received quantities, are seen.
     */
    public function post(GoodsReceipt $draft, User $user): GoodsReceipt
    {
        $this->authorize($user, 'purchasing.receive', 'post goods received');

        return DB::transaction(function () use ($draft, $user): GoodsReceipt {
            $this->lockShop();
            $receipt = $this->lockDraft($draft);
            $receipt->load(['items', 'supplier', 'location']);
            /** @var Collection<int, GoodsReceiptItem> $items */
            $items = $receipt->items;

            if ($items->isEmpty()) {
                throw new PosException('Add at least one item before posting.');
            }

            $order = $receipt->purchase_order_id === null ? null : $this->orders->lock(PurchaseOrder::query()->findOrFail($receipt->purchase_order_id));

            if ($order !== null) {
                $this->assertReceivable($order, $receipt->supplier);
            }

            if (! $receipt->location->is_active) {
                throw new PosException("{$receipt->location->name} is not active.");
            }

            $this->assertInvoiceNotEntered($receipt);

            // The lines are checked again: the item may have changed since the draft was saved.
            $this->prepare(array_values($items->map(fn (GoodsReceiptItem $item): ReceiptLine => $this->lineOf($item))->all()), $order);

            $number = $this->nextNumber();
            $variants = ProductVariant::query()->withTrashed()->with('product')->whereKey($items->pluck('product_variant_id')->unique()->all())->get()->keyBy('id');
            $note = $this->movementNote($number, $receipt);
            $ordered = [];

            foreach ($items->sortBy(fn (GoodsReceiptItem $item): string => sprintf('%012d:%06d', $item->product_variant_id, $item->sort_order)) as $item) {
                /** @var ProductVariant $variant */
                $variant = $variants->get($item->product_variant_id);
                $cost = UnitCost::perUnit($item->line_total, (float) $item->base_quantity);
                $movement = $this->receiveLine($variant, $receipt, $item, $cost, $user, $note);

                $item->update([
                    'base_unit_cost' => $cost,
                    'stock_batch_id' => $movement->stock_batch_id,
                    'stock_movement_id' => $movement->getKey(),
                ]);

                if ($item->purchase_order_item_id !== null) {
                    $ordered[(int) $item->purchase_order_item_id] = ($ordered[(int) $item->purchase_order_item_id] ?? 0.0) + $this->paidBase($item);
                }
            }

            if ($order !== null) {
                $this->orders->applyReceipt($order, $ordered);
            }

            $receipt->update([
                'grn_number' => $number,
                'status' => GoodsReceiptStatus::Posted,
                'posted_by' => $user->getKey(),
                'posted_at' => now(),
            ]);

            // Owed from now on, due on the invoice date (else today's delivery) + the supplier's terms.
            $this->payables->receiptPosted($receipt);

            return $receipt->load('items');
        }, attempts: 3);
    }

    /**
     * Put a posted GRN right with a reversing GRN: every line leaves the
     * batch it went into, at the cost it came in at, and the location's
     * average is un-averaged exactly. Refused if any of it has been sold,
     * moved or settled since (that is a supplier return or a count). The
     * reversal is posted at once and has its own GRN number.
     */
    public function reverse(GoodsReceipt $posted, string $reason, User $user): GoodsReceipt
    {
        $this->authorize($user, 'purchasing.manage', 'reverse goods received');

        if (blank($reason)) {
            throw new PosException('Say why the goods received note is being reversed.');
        }

        return DB::transaction(function () use ($posted, $reason, $user): GoodsReceipt {
            // The same lock order as posting: shop, GRN, order, items and their stock; the
            // supplier after the shop, as payments and returns take it (its invoice is settled here).
            $this->lockShop();
            $this->payables->lockSupplier((int) $posted->supplier_id);
            $original = GoodsReceipt::query()->whereKey($posted->getKey())->lockForUpdate()->firstOrFail();

            if (! $original->isPosted()) {
                throw new PosException('Only a posted goods received note can be reversed: delete the draft instead.');
            }

            if ($original->isReversal()) {
                throw new PosException("{$original->grn_number} is itself a reversal.");
            }

            if (GoodsReceipt::query()->where('reversal_of_id', $original->getKey())->exists()) {
                throw new PosException("{$original->grn_number} has already been reversed.");
            }

            $order = $original->purchase_order_id === null ? null : $this->orders->lock(PurchaseOrder::query()->findOrFail($original->purchase_order_id));

            $number = $this->nextNumber();

            $reversal = GoodsReceipt::query()->create([
                'grn_number' => $number,
                'supplier_id' => $original->supplier_id,
                'purchase_order_id' => $original->purchase_order_id,
                'location_id' => $original->location_id,
                'supplier_invoice_no' => $original->supplier_invoice_no,
                'supplier_invoice_date' => $original->supplier_invoice_date?->toDateString(),
                'received_on' => $this->batches->today()->toDateString(),
                'status' => GoodsReceiptStatus::Posted,
                'subtotal' => -$original->subtotal,
                'line_discount_total' => -$original->line_discount_total,
                'invoice_discount' => -$original->invoice_discount,
                'line_tax_total' => -$original->line_tax_total,
                'invoice_tax' => -$original->invoice_tax,
                'total' => -$original->total,
                'reversal_of_id' => $original->getKey(),
                'notes' => trim($reason),
                'user_id' => $user->getKey(),
                'posted_by' => $user->getKey(),
                'posted_at' => now(),
            ]);

            $note = "Reverses {$original->grn_number}: ".trim($reason);
            $ordered = [];

            /** @var Collection<int, GoodsReceiptItem> $items */
            $items = $original->items()->with('movement')->get();

            foreach ($items->sortBy(fn (GoodsReceiptItem $item): string => sprintf('%012d:%06d', $item->product_variant_id, $item->sort_order)) as $item) {
                /** @var StockMovement $receipt */
                $receipt = $item->movement;
                $movement = $this->stock->reverseReceipt($receipt, $user, $reversal, $note);

                $reversal->items()->create([
                    'purchase_order_item_id' => $item->purchase_order_item_id,
                    'reversal_of_item_id' => $item->getKey(),
                    'product_variant_id' => $item->product_variant_id,
                    'variant_unit_id' => $item->variant_unit_id,
                    'unit_name' => $item->unit_name,
                    'unit_factor' => (float) $item->unit_factor,
                    'quantity' => -(float) $item->quantity,
                    'free_quantity' => -(float) $item->free_quantity,
                    'unit_cost' => $item->unit_cost,
                    'gross' => -$item->gross,
                    'discount' => -$item->discount,
                    'invoice_discount_share' => -$item->invoice_discount_share,
                    'tax' => -$item->tax,
                    'invoice_tax_share' => -$item->invoice_tax_share,
                    'line_total' => -$item->line_total,
                    'base_quantity' => -(float) $item->base_quantity,
                    'base_unit_cost' => $item->base_unit_cost,
                    'batch_no' => $item->batch_no,
                    'expiry_date' => $item->expiry_date?->toDateString(),
                    'stock_batch_id' => $movement->stock_batch_id,
                    'stock_movement_id' => $movement->getKey(),
                    'sort_order' => $item->sort_order,
                ]);

                if ($item->purchase_order_item_id !== null) {
                    $ordered[(int) $item->purchase_order_item_id] = ($ordered[(int) $item->purchase_order_item_id] ?? 0.0) - $this->paidBase($item);
                }
            }

            if ($order !== null) {
                $this->orders->applyReceipt($order, $ordered);
            }

            // Nothing is owed on it now: what was paid against it goes back to the supplier's credit.
            $this->payables->receiptReversed($original, $reversal, $user);

            return $reversal->load('items');
        }, attempts: 3);
    }

    /**
     * What is still to come on an order, as lines for a new GRN: in the unit
     * ordered when what is left is a whole number of it, else in base units,
     * at the order's expected cost.
     *
     * @return list<array{purchase_order_item_id: int, variant_id: int, unit_id: int|null, quantity: float, unit_cost: int}>
     */
    public function outstandingLines(PurchaseOrder $order): array
    {
        $lines = [];

        foreach ($order->items()->with('variant.product')->get() as $item) {
            $left = $item->outstandingBase();

            if ($left <= 0) {
                continue;
            }

            $inUnit = $item->outstanding();
            $whole = Quantity::isWhole($inUnit) || ! $item->variant->product->whole_units_only;
            $factor = max((float) $item->unit_factor, 0.001);

            $lines[] = [
                'purchase_order_item_id' => (int) $item->getKey(),
                'variant_id' => (int) $item->product_variant_id,
                'unit_id' => $whole ? (int) $item->variant_unit_id : null,
                'quantity' => $whole ? $inUnit : $left,
                'unit_cost' => $whole ? $item->unit_cost : (int) round($item->unit_cost / $factor),
            ];
        }

        return $lines;
    }

    /**
     * The amounts of a set of lines: gross, discounts, the invoice discount
     * spread over the lines by their value after line discount (the rounding
     * remainder to the largest, so the shares add up exactly), tax, line
     * totals, base quantities and cost per base unit.
     *
     * Tax is part of the cost. It is entered on the lines or once for the
     * invoice, not both (the same VAT entered twice would double the cost);
     * the invoice tax is spread like the discount, by each line's value
     * after all discounts.
     *
     * @param  list<array<string, mixed>>  $lines  prepared lines
     * @return array{header: array{subtotal: int, line_discount_total: int, invoice_discount: int, line_tax_total: int, invoice_tax: int, total: int}, lines: list<array{gross: int, discount: int, invoice_discount_share: int, tax: int, invoice_tax_share: int, line_total: int, base_unit_cost: string}>}
     */
    public function amounts(array $lines, int $invoiceDiscount, int $invoiceTax = 0): array
    {
        if ($invoiceDiscount < 0) {
            throw new PosException('A discount can\'t be below zero.');
        }

        if ($invoiceTax < 0) {
            throw new PosException('Tax can\'t be below zero.');
        }

        $lineTax = array_sum(array_map(fn (array $line): int => (int) ($line['tax'] ?? 0), $lines));

        if ($invoiceTax > 0 && $lineTax > 0) {
            throw new PosException('Enter the tax on the lines or for the whole invoice, not both.');
        }

        $nets = [];

        foreach ($lines as $i => $line) {
            $nets[$i] = (int) $line['gross'] - (int) $line['discount'];
        }

        if ($invoiceDiscount > array_sum($nets)) {
            throw new PosException('The invoice discount is more than the lines are worth.');
        }

        $shares = Money::allocate($invoiceDiscount, $nets);
        $taxable = array_map(fn (int $net, int $share): int => $net - $share, $nets, $shares);

        if ($invoiceTax > 0 && array_sum($taxable) <= 0) {
            throw new PosException('The invoice tax needs lines with a cost to go on.');
        }

        $taxShares = Money::allocate($invoiceTax, $taxable);
        $out = [];

        foreach ($lines as $i => $line) {
            $tax = (int) ($line['tax'] ?? 0);
            $total = $taxable[$i] + $tax + $taxShares[$i];
            $out[] = [
                'gross' => (int) $line['gross'],
                'discount' => (int) $line['discount'],
                'invoice_discount_share' => $shares[$i],
                'tax' => $tax,
                'invoice_tax_share' => $taxShares[$i],
                'line_total' => $total,
                'base_unit_cost' => UnitCost::perUnit($total, (float) $line['base_quantity']),
            ];
        }

        return [
            'header' => [
                'subtotal' => array_sum(array_column($out, 'gross')),
                'line_discount_total' => array_sum(array_column($out, 'discount')),
                'invoice_discount' => $invoiceDiscount,
                'line_tax_total' => $lineTax,
                'invoice_tax' => $invoiceTax,
                'total' => array_sum(array_column($out, 'line_total')),
            ],
            'lines' => $out,
        ];
    }

    /**
     * Check and normalise the lines: item, unit bought in, whole units of it,
     * quantities and amounts, the order line, and for a batch-tracked item the
     * batch number and expiry (blank on a draft is allowed; posting needs
     * them). An item without batches has none.
     *
     * @param  list<ReceiptLine>  $lines
     * @return list<array<string, mixed>>
     */
    public function prepare(array $lines, ?PurchaseOrder $order): array
    {
        if ($lines === []) {
            throw new PosException('Add at least one item.');
        }

        if (count($lines) > self::MAX_LINES) {
            throw new PosException('A goods received note can have at most '.self::MAX_LINES.' lines.');
        }

        $variants = $this->lines->variants(array_map(fn (ReceiptLine $line): int => $line->variantId, $lines));
        $orderItems = $order === null ? collect() : PurchaseOrderItem::query()->where('purchase_order_id', $order->getKey())->get()->keyBy('id');
        $prepared = [];

        foreach ($lines as $line) {
            $variant = $this->lines->variant($variants, $line->variantId);
            $unit = $this->lines->unit($variant, $line->unitId);
            $quantity = $this->lines->quantity($variant, $unit, $line->quantity);
            $free = $this->lines->quantity($variant, $unit, $line->freeQuantity, 'Free quantities');

            if ($quantity + $free <= 0) {
                throw new PosException("Enter how many of {$variant->sku} arrived.");
            }

            if ($line->unitCost < 0 || $line->discount < 0 || $line->tax < 0) {
                throw new PosException('Costs, discounts and tax can\'t be below zero.');
            }

            $gross = Money::multiply($line->unitCost, $quantity);

            if ($line->discount > $gross) {
                throw new PosException("The discount on {$variant->sku} is more than the line is worth.");
            }

            if ($line->purchaseOrderItemId !== null) {
                $orderItem = $orderItems->get($line->purchaseOrderItemId);

                if ($orderItem === null || (int) $orderItem->product_variant_id !== (int) $variant->getKey()) {
                    throw new PosException("That order line is not {$variant->sku} on this order.");
                }
            }

            [$batchNo, $expiry] = $variant->product->tracksBatches() ? $this->batchFields($line) : [null, null];

            $prepared[] = [
                'purchase_order_item_id' => $line->purchaseOrderItemId,
                'product_variant_id' => $variant->getKey(),
                'variant_unit_id' => $unit->getKey(),
                'unit_name' => $unit->name,
                'unit_factor' => (float) $unit->factor,
                'quantity' => $quantity,
                'free_quantity' => $free,
                'unit_cost' => $line->unitCost,
                'gross' => $gross,
                'discount' => $line->discount,
                'tax' => $line->tax,
                'base_quantity' => $unit->toBase(Quantity::normalize($quantity + $free)),
                'batch_no' => $batchNo,
                'expiry_date' => $expiry,
                'accept_short_expiry' => $variant->product->tracksBatches() && $line->acceptShortExpiry,
            ];
        }

        return $prepared;
    }

    /** A stored line as entered, to check it again when posting. */
    private function lineOf(GoodsReceiptItem $item): ReceiptLine
    {
        return new ReceiptLine(
            (int) $item->product_variant_id,
            (float) $item->quantity,
            (int) $item->variant_unit_id,
            (float) $item->free_quantity,
            $item->unit_cost,
            $item->discount,
            $item->batch_no,
            $item->expiry_date?->toDateString(),
            $item->accept_short_expiry,
            $item->purchase_order_item_id === null ? null : (int) $item->purchase_order_item_id,
            $item->tax,
        );
    }

    /** One line into stock at its effective cost per base unit (StockService re-averages the location). */
    private function receiveLine(ProductVariant $variant, GoodsReceipt $receipt, GoodsReceiptItem $item, string $cost, User $user, string $note): StockMovement
    {
        /** @var Location $location */
        $location = $receipt->location;
        $quantity = (float) $item->base_quantity;

        if (! $variant->product->tracksBatches()) {
            return $this->stock->record($variant, $location, StockMovementType::StockIn, $quantity, $user, $receipt, $cost, note: $note);
        }

        if (blank($item->batch_no) || $item->expiry_date === null) {
            throw new PosException("{$variant->sku} tracks batches: enter the batch number and expiry printed on the pack.");
        }

        return $this->stock->receive(
            $variant,
            $location,
            new BatchInput($item->batch_no, $item->expiry_date->toDateString(), $item->accept_short_expiry),
            StockMovementType::StockIn,
            $quantity,
            $user,
            $receipt,
            $cost,
            note: $note,
        );
    }

    /** The paid quantity in base units: what counts against the order (free goods come on top). */
    private function paidBase(GoodsReceiptItem $item): float
    {
        return Quantity::normalize(abs((float) $item->quantity) * (float) $item->unit_factor);
    }

    /**
     * The batch number (normalised) and expiry (a date, or a printed month:
     * its last day) of a batch-tracked line. Either may still be blank on a
     * draft. The full rules (not expired, short expiry) apply when posting.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function batchFields(ReceiptLine $line): array
    {
        $batchNo = BatchService::normalizeBatchNo((string) $line->batchNo);

        if (Str::length($batchNo) > 50) {
            throw new PosException('A batch number can have at most 50 characters.');
        }

        $expiry = blank($line->expiry) ? null : BatchExpiry::parse((string) $line->expiry)->toDateString();

        return [$batchNo === '' ? null : $batchNo, $expiry];
    }

    private function assertReceivable(PurchaseOrder $order, Supplier $supplier): void
    {
        if ((int) $order->supplier_id !== (int) $supplier->getKey()) {
            throw new PosException("{$order->po_number} is an order to another supplier.");
        }

        if (! $order->status->isOpen()) {
            throw new PosException("{$order->po_number} is {$order->status->label()}: goods can only be received against a sent order.");
        }
    }

    /** The same supplier invoice can't be entered twice (unless the first was reversed). */
    private function assertInvoiceNotEntered(GoodsReceipt $receipt): void
    {
        if ($receipt->supplier_invoice_no === null) {
            return;
        }

        $existing = GoodsReceipt::query()
            ->where('supplier_id', $receipt->supplier_id)
            ->where('supplier_invoice_no', $receipt->supplier_invoice_no)
            ->where('status', GoodsReceiptStatus::Posted)
            ->whereNull('reversal_of_id')
            ->whereDoesntHave('reversal')
            ->whereKeyNot($receipt->getKey())
            ->value('grn_number');

        if ($existing !== null) {
            throw new PosException("Invoice {$receipt->supplier_invoice_no} from {$receipt->supplier->name} is already entered on {$existing}.");
        }
    }

    private function movementNote(string $number, GoodsReceipt $receipt): string
    {
        return $number.($receipt->supplier_invoice_no === null ? '' : ", invoice {$receipt->supplier_invoice_no}").", {$receipt->supplier->name}";
    }

    /** The shop row: one GRN numbered at a time. Taken first in a transaction (see post()). */
    private function lockShop(): void
    {
        Shop::query()->whereKey($this->currentShop->getOrFail()->getKey())->lockForUpdate()->firstOrFail();
    }

    /** The next GRN number, per shop. Only with the shop locked. */
    private function nextNumber(): string
    {
        return 'GRN-'.str_pad((string) (GoodsReceipt::query()->whereNotNull('grn_number')->count() + 1), 6, '0', STR_PAD_LEFT);
    }

    private function lockDraft(GoodsReceipt $draft): GoodsReceipt
    {
        $receipt = GoodsReceipt::query()->whereKey($draft->getKey())->lockForUpdate()->firstOrFail();

        if ($receipt->status !== GoodsReceiptStatus::Draft) {
            throw new PosException("{$receipt->label()} has been posted and can't be changed: reverse it instead.");
        }

        return $receipt;
    }

    private function day(?string $value, string $what): ?string
    {
        if (blank($value)) {
            return null;
        }

        $day = CalendarDay::parse((string) $value);

        if ($day->greaterThan($this->batches->today())) {
            throw new PosException("The {$what} date can't be in the future.");
        }

        return $day->toDateString();
    }

    private function text(?string $value, ?int $max = null): ?string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        if ($max !== null && Str::length($text) > $max) {
            throw new PosException("\"{$text}\" is too long (at most {$max} characters).");
        }

        return $text;
    }

    private function authorize(User $user, string $permission, string $doing): void
    {
        if (! $user->can($permission)) {
            throw new PosException("You do not have permission to {$doing}.");
        }

        if (! $this->currentShop->getOrFail()->hasModule('purchasing')) {
            throw new PosException('Goods received needs the purchasing module.');
        }
    }
}
