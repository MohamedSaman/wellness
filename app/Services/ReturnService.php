<?php

namespace App\Services;

use App\Enums\DrugSchedule;
use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Enums\StorageCondition;
use App\Exceptions\PosException;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemBatch;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\Shift;
use App\Models\User;
use App\Services\Data\ReturnLine;
use App\Support\CurrentShop;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Customer returns and refunds. Returned items go back into stock unless the
 * cashier says not to (faulty or damaged goods): then the return and refund
 * are still recorded, but no stock movement is made. Refunds are calculated
 * from what was actually paid for each line (after discounts), so a customer
 * can never be over-refunded.
 *
 * Units: a line sold as a box of 30 can be returned as boxes (the sold unit,
 * the default) or as tablets ($inBaseUnits), e.g. 10 tablets back from a box.
 * On a whole-units product either is a whole number: never 0.5 box.
 * Refunds are proportional to base units, and stock goes back in base units.
 *
 * Batches (docs/PHARMACY.md §6.5): a batch-tracked line goes back into the
 * batches it was sold from. When it took several, the caller may say how
 * much comes back from each; by default the latest-expiring batch first.
 * Each batch's returned quantity is counted whether or not it is restocked.
 *
 * Per line (docs/PHARMACY.md §9, branch 9): each line of a return has its
 * own unit (sold unit or base units) and its own "Put back into stock?"
 * choice (processLines()). An OPD bill's medicine line refunds in proportion
 * to its stored share of the Medicines amount, which is its line total. The
 * refund is paid back by cash, card or bank transfer, with its reference
 * (required for a bank transfer when the shop requires one at checkout).
 */
class ReturnService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly CurrentShop $currentShop,
    ) {}

    /**
     * Medicines kept in the fridge, and controlled drugs, are not put back on
     * the shelf by default (docs/PHARMACY.md §6.5, Q5): the cold chain and the
     * register can't be vouched for once they have left. The user can still
     * choose to restock.
     */
    public static function restocksByDefault(SaleItem $item): bool
    {
        $drug = $item->loadMissing('variant.product.drugDetail')->variant->product->drugDetail;

        return $drug === null
            || ($drug->storage_condition !== StorageCondition::Fridge && $drug->schedule !== DrugSchedule::Controlled);
    }

    /**
     * One return with the same unit and restock choice for every line.
     *
     * @param  array<int, float>  $quantities  sale_item_id => quantity to return, in the line's sold
     *                                         unit, or in base units when $inBaseUnits
     * @param  array<int, array<int, float>>|null  $batchQuantities  for batch-tracked lines that took several
     *                                                               batches: sale_item_id => [sale_item_batch_id =>
     *                                                               base units]; omitted lines go latest expiry first
     */
    public function process(
        Sale $sale,
        array $quantities,
        ReturnReason $reason,
        PaymentMethod $refundMethod,
        User $user,
        bool $restock = true,
        ?Shift $shift = null,
        ?string $note = null,
        bool $inBaseUnits = false,
        ?array $batchQuantities = null,
        ?string $refundReference = null,
    ): SaleReturn {
        $lines = [];

        foreach ($quantities as $saleItemId => $quantity) {
            $lines[] = new ReturnLine((int) $saleItemId, (float) $quantity, $inBaseUnits, $restock, $batchQuantities[$saleItemId] ?? null);
        }

        return $this->processLines($sale, $lines, $reason, $refundMethod, $user, $refundReference, $shift, $note);
    }

    /**
     * A return of some lines of a sale, each in its own unit and with its
     * own restock choice. Limits: what was sold less what already came back.
     *
     * @param  list<ReturnLine>  $lines
     */
    public function processLines(
        Sale $sale,
        array $lines,
        ReturnReason $reason,
        PaymentMethod $refundMethod,
        User $user,
        ?string $refundReference = null,
        ?Shift $shift = null,
        ?string $note = null,
    ): SaleReturn {
        if (! $user->can('returns.create')) {
            throw new PosException('You do not have permission to process returns.');
        }

        $note = blank($note) ? null : trim($note);
        $refundReference = $this->refundReference($refundMethod, $refundReference);
        $lines = $this->requested($lines);

        return DB::transaction(function () use ($sale, $lines, $reason, $note, $refundMethod, $refundReference, $user, $shift): SaleReturn {
            /** @var Sale $sale */
            $sale = Sale::query()->whereKey($sale->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($sale->status, [SaleStatus::Voided, SaleStatus::Returned], true)) {
                throw new PosException("This sale is {$sale->status->value} and cannot be returned.");
            }

            $sale->load(['items.variant.product', 'items.location', 'items.batches']);
            $shift = $this->resolveShift($sale, $shift);
            $priced = $this->price($sale, $lines);
            $refundTotal = array_sum(array_column($priced, 'refund'));

            $count = SaleReturn::query()->where('sale_id', $sale->getKey())->count();

            $return = SaleReturn::query()->create([
                'sale_id' => $sale->getKey(),
                'user_id' => $user->getKey(),
                'shift_id' => $shift?->getKey(),
                'return_no' => $sale->invoice_no.'-R'.($count + 1),
                'reason_code' => $reason,
                'note' => $note,
                'refund_total' => $refundTotal,
                'refund_method' => $refundMethod,
                'refund_reference' => $refundReference,
                'returned_at' => now(),
            ]);

            // Fixed lock order (by location, then variant) so concurrent stock changes never deadlock.
            usort($priced, fn ($a, $b) => [$a['item']->location_id, $a['item']->product_variant_id] <=> [$b['item']->location_id, $b['item']->product_variant_id]);

            foreach ($priced as ['item' => $item, 'quantity' => $quantity, 'baseQuantity' => $baseQuantity, 'refund' => $refund, 'pieces' => $pieces, 'restock' => $restock]) {
                $return->items()->create([
                    'sale_item_id' => $item->getKey(),
                    'quantity' => $quantity,
                    'base_quantity' => $baseQuantity,
                    'refund_amount' => $refund,
                    'restocked' => $restock,
                    'batches' => $pieces === [] ? null : array_map(fn (array $piece): array => [
                        'sale_item_batch_id' => (int) $piece[0]->getKey(),
                        'stock_batch_id' => (int) $piece[0]->stock_batch_id,
                        'batch_no' => $piece[0]->batch_no,
                        'expiry_date' => $piece[0]->expiry_date?->toDateString(),
                        'quantity' => $piece[1],
                    ], $pieces),
                ]);

                $item->update([
                    'returned_quantity' => Quantity::normalize((float) $item->returned_quantity + $quantity),
                ]);

                foreach ($pieces as [$piece, $pieceQuantity]) {
                    $piece->update(['returned_quantity' => Quantity::normalize((float) $piece->returned_quantity + $pieceQuantity)]);
                }

                if (! $restock || ! $item->variant->product->track_stock) {
                    continue;
                }

                // Into the batches it was sold from, at the line's location and cost.
                if ($item->variant->product->tracksBatches()) {
                    if ($pieces === []) {
                        $this->stock->restoreWithoutBatch($item->variant, $item->location, StockMovementType::Return, $baseQuantity, $user, $return, $item->unitCostPerBase(), $reason, $note);
                    }

                    foreach ($pieces as [$piece, $pieceQuantity]) {
                        $this->stock->restore($piece, StockMovementType::Return, $pieceQuantity, $user, $return, $item->unitCostPerBase(), $reason, $note);
                    }

                    continue;
                }

                // Back to the location the line's stock came from.
                $this->stock->record(
                    $item->variant,
                    $item->location,
                    StockMovementType::Return,
                    $baseQuantity,
                    $user,
                    reference: $return,
                    unitCost: $item->unitCostPerBase(),
                    reason: $reason,
                    note: $note,
                    allowPartUnits: true, // checked above
                );
            }

            $fullyReturned = $sale->items->every(
                fn (SaleItem $i) => Quantity::normalize((float) $i->quantity - (float) $i->returned_quantity) <= 0
            );

            $sale->update([
                'refunded_total' => $sale->refunded_total + $refundTotal,
                'status' => $fullyReturned ? SaleStatus::Returned : SaleStatus::PartiallyReturned,
            ]);

            return $return->load('items');
        });
    }

    /**
     * What a return would refund, line by line and in all, without
     * recording anything: the return form shows it as the user types. Throws
     * what processLines() would refuse the return with.
     *
     * @param  list<ReturnLine>  $lines
     * @return array{total: int, lines: array<int, int>} lines: sale_item_id => refund
     */
    public function preview(Sale $sale, array $lines): array
    {
        $lines = $this->requested($lines);
        $sale->loadMissing(['items.variant.product', 'items.batches']);
        $priced = $this->price($sale, $lines);

        return [
            'total' => (int) array_sum(array_column($priced, 'refund')),
            'lines' => array_column(array_map(fn (array $line): array => ['id' => (int) $line['item']->getKey(), 'refund' => $line['refund']], $priced), 'refund', 'id'),
        ];
    }

    /**
     * The lines with something to return (one line of the sale once).
     *
     * @param  list<ReturnLine>  $lines
     * @return list<ReturnLine>
     */
    private function requested(array $lines): array
    {
        $requested = [];

        foreach ($lines as $line) {
            $quantity = Quantity::normalize($line->quantity);

            if ($quantity <= 0) {
                continue;
            }

            if (isset($requested[$line->saleItemId])) {
                throw new PosException('Each item can be returned once per return.');
            }

            $requested[$line->saleItemId] = new ReturnLine($line->saleItemId, $quantity, $line->inBaseUnits, $line->restock, $line->batches);
        }

        if ($requested === []) {
            throw new PosException('Choose at least one item to return.');
        }

        return array_values($requested);
    }

    /**
     * Each line's quantity in its sold unit and in base units, its refund
     * and the batches it comes back from.
     *
     * @param  list<ReturnLine>  $lines
     * @return list<array{item: SaleItem, quantity: float, baseQuantity: float, refund: int, pieces: list<array{0: SaleItemBatch, 1: float}>, restock: bool}>
     */
    private function price(Sale $sale, array $lines): array
    {
        $items = $sale->items->keyBy('id');
        $priced = [];

        foreach ($lines as $line) {
            /** @var SaleItem|null $item */
            $item = $items->get($line->saleItemId)
                ?? throw new PosException('That item is not on this sale.');

            [$quantity, $baseQuantity, $isLast] = $this->split($item, $line->quantity, $line->inBaseUnits);

            // Whole units of the unit entered: 1 box or 10 tablets, never 0.5 box (restocked
            // or not). The one exception is the last of a line an offline till sold as a part tablet.
            if (! ($isLast && ! Quantity::isWhole($baseQuantity))) {
                $this->stock->assertWholeUnits($item->variant, $line->quantity, $line->inBaseUnits ? null : $item->unit_name);
            }

            $priced[] = [
                'item' => $item,
                'quantity' => $quantity,
                'baseQuantity' => $baseQuantity,
                'refund' => $this->refundFor($item, $baseQuantity, $isLast),
                'pieces' => $item->batches->isEmpty() ? [] : $this->batchSplit($item, $baseQuantity, $line->batches),
                'restock' => $line->restock,
            ];
        }

        return $priced;
    }

    /**
     * The refund's reference, trimmed: required for a bank transfer when the
     * shop requires one at checkout; none for cash.
     */
    private function refundReference(PaymentMethod $method, ?string $reference): ?string
    {
        $reference = trim((string) $reference);

        if ($method === PaymentMethod::Cash) {
            return null;
        }

        if ($reference === '' && $method === PaymentMethod::BankTransfer && $this->currentShop->getOrFail()->setting('bank_transfer_reference_required')) {
            throw new PosException('Enter the bank transfer reference of the refund.');
        }

        return $reference === '' ? null : mb_substr($reference, 0, 100);
    }

    /**
     * Which batches a returned base quantity comes back from: as the caller
     * said (each at most what is left of it, adding up to the quantity), or
     * the latest-expiring batch first, unallocated stock last.
     *
     * @param  array<int, float>|null  $requested  sale_item_batch_id => base units
     * @return list<array{0: SaleItemBatch, 1: float}>
     */
    private function batchSplit(SaleItem $item, float $baseQuantity, ?array $requested): array
    {
        $pieces = $item->batches->keyBy('id');

        if ($requested !== null) {
            $split = [];

            foreach ($requested as $pieceId => $quantity) {
                $quantity = Quantity::normalize($quantity);

                if ($quantity <= 0) {
                    continue;
                }

                /** @var SaleItemBatch|null $piece */
                $piece = $pieces->get($pieceId) ?? throw new PosException("That batch was not sold on {$item->sku}.");

                if ($quantity > $piece->remaining()) {
                    throw new PosException('Only '.Quantity::format($piece->remaining())." of {$item->sku} from batch {$piece->label()} can still be returned.");
                }

                $split[] = [$piece, $quantity];
            }

            if (Quantity::normalize(array_sum(array_column($split, 1))) !== Quantity::normalize($baseQuantity)) {
                throw new PosException('The batches returned for '.$item->sku.' must add up to '.Quantity::format($baseQuantity).' '.$item->variant->product->unit.'.');
            }

            return $split;
        }

        $order = $pieces->values()->sort(fn (SaleItemBatch $a, SaleItemBatch $b): int => [$a->is_unallocated, $b->expiry_date?->toDateString() ?? '', $b->getKey()]
            <=> [$b->is_unallocated, $a->expiry_date?->toDateString() ?? '', $a->getKey()]);

        $split = [];
        $left = Quantity::normalize($baseQuantity);

        foreach ($order as $piece) {
            $take = min($left, $piece->remaining());

            if ($take > 0) {
                $split[] = [$piece, Quantity::normalize($take)];
                $left = Quantity::normalize($left - $take);
            }
        }

        if ($left > 0) {
            throw new PosException("Only what was sold of {$item->sku} can be returned.");
        }

        return $split;
    }

    /**
     * Cash refunds must belong to an open shift so the drawer count is right.
     * Defaults to the open shift on the till that made the sale.
     */
    private function resolveShift(Sale $sale, ?Shift $shift): ?Shift
    {
        if ($shift === null) {
            return Shift::query()
                ->where('device_id', $sale->device_id)
                ->whereNull('closed_at')
                ->latest('opened_at')
                ->lockForUpdate()
                ->first();
        }

        $locked = Shift::query()->whereKey($shift->getKey())->lockForUpdate()->first();

        if ($locked === null || ! $locked->isOpen()) {
            throw new PosException('That shift is closed. Refund on an open shift.');
        }

        return $locked;
    }

    /**
     * A requested return as [sold-unit quantity, base quantity, is it all that is left].
     * Returning everything that is left takes exactly the remainder in both
     * units, so 3 x 10 tablets of a box of 30 closes the line at exactly 1 box.
     *
     * @return array{0: float, 1: float, 2: bool}
     */
    private function split(SaleItem $item, float $requested, bool $inBaseUnits): array
    {
        $remaining = Quantity::normalize((float) $item->quantity - (float) $item->returned_quantity);
        $returnedBase = (float) SaleReturnItem::query()->where('sale_item_id', $item->getKey())->sum('base_quantity');
        $remainingBase = Quantity::normalize((float) $item->base_quantity - $returnedBase);
        $factor = (float) $item->unit_factor > 0 ? (float) $item->unit_factor : 1.0;

        if ($inBaseUnits) {
            if ($requested > $remainingBase) {
                $unit = $item->variant->product->unit;

                throw new PosException('Only '.Quantity::format($remainingBase)." {$unit} of {$item->sku} can still be returned.");
            }

            return $requested >= $remainingBase
                ? [$remaining, $remainingBase, true]
                : [min(Quantity::normalize($requested / $factor), $remaining), $requested, false];
        }

        if ($requested > $remaining) {
            throw new PosException("Only {$remaining} of {$item->sku} can still be returned.");
        }

        return $requested >= $remaining
            ? [$remaining, $remainingBase, true]
            : [$requested, min(Quantity::normalize($requested * $factor), $remainingBase), false];
    }

    /**
     * Proportional refund of what was paid for the line, by base units.
     * Returning the last remaining units refunds exactly what is left, so
     * rounding never drifts.
     */
    private function refundFor(SaleItem $item, float $baseQuantity, bool $isLast): int
    {
        if ($isLast) {
            $alreadyRefunded = (int) SaleReturnItem::query()
                ->where('sale_item_id', $item->getKey())
                ->sum('refund_amount');

            return $item->line_total - $alreadyRefunded;
        }

        return (int) round($item->line_total * $baseQuantity / (float) $item->base_quantity);
    }
}
