<?php

namespace App\Services;

use App\Enums\AdjustmentReason;
use App\Enums\BatchStatus;
use App\Enums\ReturnReason;
use App\Enums\StockMovementType;
use App\Exceptions\PosException;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\SaleItemBatch;
use App\Models\StockBatch;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Services\Data\BatchAllocation;
use App\Services\Data\BatchInput;
use App\Services\Data\BatchPick;
use App\Support\CurrentShop;
use App\Support\Quantity;
use App\Support\UnitCost;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY place stock changes. Every change:
 *   1. locks the stock_levels row of the item at that location (no two tills
 *      can oversell),
 *   2. writes an immutable stock_movements row with the running balance,
 *   3. updates the cached stock level,
 * all in one database transaction.
 *
 * Costing is weighted average cost (WAC) per variant per location
 * (docs/PHARMACY.md §5), held on the stock level as DECIMAL(20,4) minor units
 * per base unit (Q9). A receipt with a unit cost (Opening, Stock In, later
 * goods received) re-averages the cost at its location:
 *   new = (on_hand x average + qty x unit_cost) / (on_hand + qty)
 * or just unit_cost when nothing (or less than nothing) is on hand there. A
 * transfer re-averages the destination with the source location's average.
 * Reversing a goods received note takes its cost back out exactly
 * (reverseReceipt()). Returns, voids, adjustments, sales and supplier
 * returns never change an average.
 * product_variants.cost_price is kept as the blend of the locations'
 * averages (whole minor units), so a one-location shop sees exactly the
 * figures it always did.
 *
 * Batches (docs/PHARMACY.md §6): a batch-tracked item (products.track_batches,
 * every medicine) never goes through record(). Stock leaves through issue()
 * (first expiry first out, one movement per batch), comes in through
 * receive() (into a named batch), and goes back through restore() (into the
 * batch a sale took it from). Each batch's quantity at its location is the
 * sum of its movements, updated with them. Only the unallocated batch may go
 * below zero: it takes what was sold beyond the recorded batches (offline, or
 * with negative stock allowed) until the next receipt settles it.
 *
 * Lock order (no deadlocks): product_variants rows (receipts, transfers and
 * revaluations only, to keep the blended cost) in id order, then stock_levels
 * rows in (location_id, variant_id) order, then that level's batch rows.
 * Batch rows are only ever locked while their level is held, so the level
 * row is the mutex for the item at that location. Sales lock stock levels
 * (and their batches) only.
 *
 * Quantities are always in the variant's base unit (tablet, piece, litre);
 * callers convert packs first (VariantUnit::toBase()). A product with
 * whole_units_only takes whole units only, in whatever unit the quantity
 * was entered: 1 strip or 3 tablets, never half a tablet or half a box.
 * Stock In and stock counts are entered in base units.
 */
class StockService
{
    private const SETTLE_NOTE = 'Settles stock sold beyond the recorded batches';

    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly BatchService $batches,
    ) {}

    /**
     * Stock of an item that does not track batches.
     *
     * @param  float  $quantity  In base units. Always positive; the movement type decides in/out.
     * @param  int|string|null  $unitCost  per base unit, minor units (a decimal string up to 4 places
     *                                     is kept exactly for the average). A receipt's purchase cost,
     *                                     or the cost a return or void comes back at. Null: the
     *                                     location's average.
     * @param  AdjustmentReason|ReturnReason|null  $reason  stored as a code; free text goes in $note
     * @param  bool  $allowPartUnits  skip the whole-units rule: only for undoing or recording what
     *                                already happened (a void, an offline sale the till already made)
     */
    public function record(
        ProductVariant $variant,
        Location $location,
        StockMovementType $type,
        float $quantity,
        ?User $user = null,
        ?Model $reference = null,
        int|string|null $unitCost = null,
        AdjustmentReason|ReturnReason|null $reason = null,
        ?string $note = null,
        ?int $deviceId = null,
        bool $allowNegative = false,
        ?\DateTimeInterface $occurredAt = null,
        bool $allowPartUnits = false,
    ): StockMovement {
        $quantity = $this->checkedQuantity($variant, $quantity, $allowPartUnits);

        if ($type->requiresReason() && $reason === null) {
            throw new PosException('A reason is required for stock adjustments.');
        }

        if (in_array($type, [StockMovementType::TransferIn, StockMovementType::TransferOut], true)) {
            throw new \LogicException('Transfers come in pairs: use StockService::transfer().');
        }

        if (in_array($type, [StockMovementType::BatchCorrectionIn, StockMovementType::BatchCorrectionOut], true)) {
            throw new \LogicException('Batch corrections come in pairs: use StockService::settleUnallocated().');
        }

        $this->assertCurrentShop($variant, $location);

        // Nothing may bypass batches: their quantities would no longer add up to the level.
        if ($variant->product->tracksBatches()) {
            throw new \LogicException("{$variant->sku} tracks batches: its stock moves through issue(), receive() or restore().");
        }

        return DB::transaction(function () use (
            $variant, $location, $type, $quantity, $user, $reference, $unitCost,
            $reason, $note, $deviceId, $allowNegative, $occurredAt
        ): StockMovement {
            $cost = $unitCost === null ? null : UnitCost::normalize($unitCost);
            $receivesAtCost = $cost !== null && $type->updatesAverageCost();

            // Receipts lock the variant first (only to keep its blended cost; sales never
            // lock it, so this order can never deadlock with them), then the level.
            $lockedVariant = $receivesAtCost ? $this->lockVariant($variant) : null;
            $level = $this->lockLevel($variant, $location);

            if ($receivesAtCost) {
                $level->average_cost = UnitCost::weightedAverage((float) $level->quantity, $level->average_cost, $quantity, $cost);
                $level->last_cost = $cost;
            }

            $movement = $this->move($level, $variant, $type, $quantity, $user, $reference, $cost, $reason, $note, $deviceId, $allowNegative, $occurredAt, receivedCost: $receivesAtCost ? $cost : null);

            if ($lockedVariant !== null) {
                $this->refreshVariantCost($lockedVariant, $variant, $level, UnitCost::toMinor($cost));
            }

            return $movement;
        });
    }

    /**
     * Take stock of a batch-tracked item out of its batches at a location
     * (docs/PHARMACY.md §6.2), one movement per batch:
     *
     *   1. lock the stock level (the mutex for the item at this location),
     *      then its batches;
     *   2. honour the proposal first, if any: a batch the pharmacist picked,
     *      or an offline till's plan. With $strictProposal (an online pick)
     *      a batch that can't be used is refused; otherwise it is dropped and
     *      listed in the allocation's differences;
     *   3. fill the rest first expiry first out: soonest expiry first, then
     *      the batch received first. A sale never takes a batch expiring
     *      before its cutoff (the sale day plus the medicine's or the shop's
     *      minimum days to expiry), nor a quarantined or recalled one;
     *   4. what is still missing is refused (saying how much is held back as
     *      expired or blocked), or with $allowNegative (offline replay, or a
     *      shop that allows negative stock) goes to the unallocated batch.
     *
     * The sale day is $occurredAt's day in the shop's timezone (now online,
     * the till's sold_at offline).
     *
     * @param  float  $quantity  base units
     * @param  list<BatchPick>|null  $proposal
     */
    public function issue(
        ProductVariant $variant,
        Location $location,
        StockMovementType $type,
        float $quantity,
        ?User $user = null,
        ?Model $reference = null,
        ?array $proposal = null,
        bool $strictProposal = false,
        bool $allowNegative = false,
        ?\DateTimeInterface $occurredAt = null,
        ?int $deviceId = null,
        AdjustmentReason|ReturnReason|null $reason = null,
        ?string $note = null,
        bool $allowPartUnits = false,
    ): BatchAllocation {
        $quantity = $this->checkedQuantity($variant, $quantity, $allowPartUnits);

        if ($type->direction() !== -1 || in_array($type, [StockMovementType::TransferOut, StockMovementType::BatchCorrectionOut], true)) {
            throw new \LogicException("issue() takes stock out; {$type->value} goes through its own method.");
        }

        if ($type->requiresReason() && $reason === null) {
            throw new PosException('A reason is required for stock adjustments.');
        }

        $this->assertCurrentShop($variant, $location);
        $this->assertTracksBatches($variant);

        return DB::transaction(fn (): BatchAllocation => $this->issueLocked(
            $this->lockLevel($variant, $location), $variant, $type, $quantity, $user, $reference,
            proposal: $proposal, strictProposal: $strictProposal, allowNegative: $allowNegative,
            occurredAt: $occurredAt, deviceId: $deviceId, reason: $reason, note: $note,
        ));
    }

    /**
     * Bring stock of a batch-tracked item into one batch at a location: a
     * receipt (Opening, Stock In, later goods received) or a count that
     * found more. A new batch is given as a BatchInput and checked by
     * BatchService::validateNew() (for a receipt: expiry after today, the
     * owner's go-ahead when it is close). A receipt with a unit cost
     * re-averages the location's cost, as record() does. A receipt then
     * settles the location's unallocated batch, if it is below zero, from the
     * batch that arrived (§6.4). Returns and voids use restore().
     *
     * @param  float  $quantity  base units
     */
    public function receive(
        ProductVariant $variant,
        Location $location,
        StockBatch|BatchInput $batch,
        StockMovementType $type,
        float $quantity,
        ?User $user = null,
        ?Model $reference = null,
        int|string|null $unitCost = null,
        AdjustmentReason|ReturnReason|null $reason = null,
        ?string $note = null,
        ?int $deviceId = null,
        ?\DateTimeInterface $occurredAt = null,
        bool $allowPartUnits = false,
    ): StockMovement {
        $quantity = $this->checkedQuantity($variant, $quantity, $allowPartUnits);

        if ($type->direction() !== 1 || in_array($type, [StockMovementType::TransferIn, StockMovementType::BatchCorrectionIn, StockMovementType::Return, StockMovementType::SaleVoid], true)) {
            throw new \LogicException("receive() brings new stock in; {$type->value} goes through its own method.");
        }

        if ($type->requiresReason() && $reason === null) {
            throw new PosException('A reason is required for stock adjustments.');
        }

        $this->assertCurrentShop($variant, $location);
        $this->assertTracksBatches($variant);

        $new = $batch instanceof BatchInput
            ? $this->batches->validateNew($variant, $batch, $user, receipt: $type->updatesAverageCost())
            : null;

        if ($batch instanceof StockBatch) {
            $this->assertBatchOf($batch, $variant, $location);
        }

        return DB::transaction(function () use ($variant, $location, $batch, $new, $type, $quantity, $user, $reference, $unitCost, $reason, $note, $deviceId, $occurredAt): StockMovement {
            $cost = $unitCost === null ? null : UnitCost::normalize($unitCost);
            $receivesAtCost = $cost !== null && $type->updatesAverageCost();

            $lockedVariant = $receivesAtCost ? $this->lockVariant($variant) : null;
            $level = $this->lockLevel($variant, $location);

            [$row, $created] = $new !== null
                ? $this->findOrCreateBatch($variant, $location, $new, $cost ?? UnitCost::normalize($level->average_cost), $occurredAt)
                : [$this->lockBatch($batch), false];

            if ($receivesAtCost) {
                $level->average_cost = UnitCost::weightedAverage((float) $level->quantity, $level->average_cost, $quantity, $cost);
                $level->last_cost = $cost;
            }

            $movement = $this->move($level, $variant, $type, $quantity, $user, $reference, $cost, $reason, $note, $deviceId, false, $occurredAt, batch: $row, receivedCost: $receivesAtCost ? $cost : null);

            if ($created) {
                $row->source()->associate($reference ?? $movement)->save();
            }

            if ($type->settlesUnallocated()) {
                $this->settleLocked($level, $variant, $row, null, $user, $occurredAt);
            }

            if ($lockedVariant !== null) {
                $this->refreshVariantCost($lockedVariant, $variant, $level, UnitCost::toMinor($cost));
            }

            return $movement;
        });
    }

    /**
     * Put stock a sale took back into the batch it came from (a return or a
     * void, docs/PHARMACY.md §6.5), at the line's location and cost. A batch
     * that has expired or been recalled since still takes the units back:
     * the ledger must balance, and FEFO will not sell them.
     *
     * A piece sold from the unallocated batch goes back there while that
     * batch is still below zero; whatever is left goes to the batch that
     * settled it (the units were attributed to that batch), or, if nothing
     * did, stays on the unallocated batch.
     *
     * @return list<StockMovement>
     */
    public function restore(
        SaleItemBatch $piece,
        StockMovementType $type,
        float $quantity,
        ?User $user,
        Model $reference,
        int|string|null $unitCost = null,
        AdjustmentReason|ReturnReason|null $reason = null,
        ?string $note = null,
    ): array {
        if (! in_array($type, [StockMovementType::Return, StockMovementType::SaleVoid], true)) {
            throw new \LogicException('restore() is for returns and voids.');
        }

        $quantity = Quantity::normalize($quantity);

        if ($quantity <= 0) {
            throw new PosException('Quantity must be greater than zero.');
        }

        $batch = StockBatch::query()->findOrFail($piece->stock_batch_id);
        /** @var ProductVariant $variant */
        $variant = $batch->variant;
        /** @var Location $location */
        $location = Location::query()->findOrFail($piece->location_id);

        $this->assertCurrentShop($variant, $location);

        return DB::transaction(function () use ($batch, $variant, $location, $type, $quantity, $user, $reference, $unitCost, $reason, $note): array {
            $cost = $unitCost === null ? null : UnitCost::normalize($unitCost);
            $level = $this->lockLevel($variant, $location);
            $row = $this->lockBatch($batch);
            $back = fn (StockBatch $into, float $quantity): StockMovement => $this->move($level, $variant, $type, $quantity, $user, $reference, $cost, $reason, $note, batch: $into);

            if (! $row->is_unallocated) {
                return [$back($row, $quantity)];
            }

            $owed = Quantity::normalize(max(0.0, -(float) $row->quantity));
            $toUnallocated = min($owed, $quantity);
            $rest = Quantity::normalize($quantity - $toUnallocated);
            $movements = $toUnallocated > 0 ? [$back($row, $toUnallocated)] : [];

            if ($rest > 0) {
                $settledBy = $this->settlingBatch($row);
                $movements[] = $back($settledBy === null ? $row : $this->lockBatch($settledBy), $rest);
            }

            return $movements;
        });
    }

    /**
     * Stock coming back for a batch-tracked item on a sale line that has no
     * batches (sold before the item tracked batches): which batch it is in
     * is unknown, so it goes to the unallocated batch, which shows on the
     * dashboard until it is counted into a real batch.
     */
    public function restoreWithoutBatch(
        ProductVariant $variant,
        Location $location,
        StockMovementType $type,
        float $quantity,
        ?User $user,
        Model $reference,
        int|string|null $unitCost = null,
        AdjustmentReason|ReturnReason|null $reason = null,
        ?string $note = null,
    ): StockMovement {
        if (! in_array($type, [StockMovementType::Return, StockMovementType::SaleVoid], true)) {
            throw new \LogicException('restoreWithoutBatch() is for returns and voids.');
        }

        $quantity = Quantity::normalize($quantity);

        if ($quantity <= 0) {
            throw new PosException('Quantity must be greater than zero.');
        }

        $this->assertCurrentShop($variant, $location);
        $this->assertTracksBatches($variant);

        return DB::transaction(function () use ($variant, $location, $type, $quantity, $user, $reference, $unitCost, $reason, $note): StockMovement {
            $level = $this->lockLevel($variant, $location);
            $cost = $unitCost === null ? null : UnitCost::normalize($unitCost);

            return $this->move($level, $variant, $type, $quantity, $user, $reference, $cost, $reason, $note, batch: $this->lockUnallocated($variant, $level));
        });
    }

    /**
     * Move stock from one location to another: per line, a transfer_out at the
     * source and a transfer_in at the destination, all in one transaction. The
     * cost travels at the source location's average (decided, PHARMACY.md §5):
     * the source average is unchanged and the destination re-averages with it.
     * Never more than the source has on hand.
     *
     * A batch-tracked line moves batches: the one named (any batch with stock,
     * expired or quarantined ones too, e.g. into quarantine), or by default
     * first expiry first out over the batches that have not expired. Each
     * batch arrives as the same batch (number and expiry) at the destination.
     *
     * @param  list<array{variant: ProductVariant, quantity: float, batch?: StockBatch|null}>  $lines  quantities in base units
     * @return list<array{out: StockMovement|null, in: StockMovement|null, unit_cost: string, batches: list<array{from: StockBatch, to: StockBatch, quantity: float, out: StockMovement, in: StockMovement}>}> in the order of $lines
     */
    public function transfer(Location $from, Location $to, array $lines, ?User $user, Model $reference, ?string $note = null, ?\DateTimeInterface $occurredAt = null): array
    {
        if ((int) $from->getKey() === (int) $to->getKey()) {
            throw new PosException('Choose two different locations.');
        }

        if ($lines === []) {
            throw new PosException('Add at least one item to transfer.');
        }

        foreach ($lines as $i => $line) {
            $quantity = Quantity::normalize($line['quantity']);

            if ($quantity <= 0) {
                throw new PosException('Quantities must be greater than zero.');
            }

            $this->assertWholeUnits($line['variant'], $quantity);
            $this->assertCurrentShop($line['variant'], $from);
            $this->assertCurrentShop($line['variant'], $to);
            $lines[$i]['quantity'] = $quantity;

            if (($line['batch'] ?? null) !== null) {
                $this->assertTracksBatches($line['variant']);
                $this->assertBatchOf($line['batch'], $line['variant'], $from);
            }
        }

        return DB::transaction(function () use ($from, $to, $lines, $user, $reference, $note, $occurredAt): array {
            $variants = collect($lines)->pluck('variant')->keyBy(fn (ProductVariant $variant): int => (int) $variant->getKey())->sortKeys();
            $locked = $variants->map(fn (ProductVariant $variant): ProductVariant => $this->lockVariant($variant));

            /** @var array<string, StockLevel> $levels */
            $levels = [];
            $ends = (int) $from->getKey() < (int) $to->getKey() ? [$from, $to] : [$to, $from];

            foreach ($ends as $location) {
                foreach ($variants as $variantId => $variant) {
                    $levels["{$location->getKey()}:{$variantId}"] = $this->lockLevel($variant, $location);
                }
            }

            $results = [];

            foreach ($lines as $line) {
                $variant = $line['variant'];
                $quantity = $line['quantity'];
                $source = $levels["{$from->getKey()}:{$variant->getKey()}"];
                $destination = $levels["{$to->getKey()}:{$variant->getKey()}"];
                $cost = UnitCost::normalize($source->average_cost);

                if (! $variant->product->tracksBatches()) {
                    $out = $this->move($source, $variant, StockMovementType::TransferOut, $quantity, $user, $reference, $cost, note: $note, occurredAt: $occurredAt);

                    $destination->average_cost = UnitCost::weightedAverage((float) $destination->quantity, $destination->average_cost, $quantity, $cost);
                    $in = $this->move($destination, $variant, StockMovementType::TransferIn, $quantity, $user, $reference, $cost, note: $note, occurredAt: $occurredAt, receivedCost: $cost);

                    $results[] = ['out' => $out, 'in' => $in, 'unit_cost' => $cost, 'batches' => []];

                    continue;
                }

                /** @var StockBatch|null $named */
                $named = $line['batch'] ?? null;
                $allocation = $this->issueLocked(
                    $source, $variant, StockMovementType::TransferOut, $quantity, $user, $reference,
                    proposal: $named === null ? null : [new BatchPick((int) $named->getKey(), $quantity)],
                    strictProposal: $named !== null, picksMayBeUnsellable: true,
                    occurredAt: $occurredAt, note: $note, unitCost: $cost,
                );

                // One average for the whole line, exactly as for an item without batches.
                $destination->average_cost = UnitCost::weightedAverage((float) $destination->quantity, $destination->average_cost, $quantity, $cost);
                $pieces = [];

                foreach ($allocation->pieces as ['batch' => $batch, 'quantity' => $pieceQuantity, 'movement' => $out]) {
                    [$arrival, $created] = $this->counterpart($batch, $to, $occurredAt);
                    $in = $this->move($destination, $variant, StockMovementType::TransferIn, $pieceQuantity, $user, $reference, $cost, note: $note, occurredAt: $occurredAt, batch: $arrival, receivedCost: $cost);

                    if ($created) {
                        $arrival->source()->associate($reference)->save();
                    }

                    $pieces[] = ['from' => $batch, 'to' => $arrival, 'quantity' => $pieceQuantity, 'out' => $out, 'in' => $in];
                }

                $results[] = ['out' => null, 'in' => null, 'unit_cost' => $cost, 'batches' => $pieces];
            }

            foreach ($locked as $variantId => $lockedVariant) {
                $this->refreshVariantCost($lockedVariant, $variants->get($variantId), $levels["{$to->getKey()}:{$variantId}"]);
            }

            return $results;
        }, attempts: 3);
    }

    /**
     * Take a receipt back out (a reversing goods received note): the same
     * quantity leaves the same batch at the same location, as a
     * receipt_reversal at the receipt's own cost, and the location's average
     * is un-averaged exactly (UnitCost::withoutReceipt(); the ledger keeps the
     * cost as received_cost, so the cost rebuild replays it). Refused when
     * the batch (or, without batches, the location) no longer holds all of
     * it: what has been sold or moved since can't be reversed, it is a
     * supplier return or a count. The last cost is left as it is.
     */
    public function reverseReceipt(StockMovement $receipt, ?User $user, Model $reference, ?string $note = null): StockMovement
    {
        if ($receipt->type !== StockMovementType::StockIn || (float) $receipt->quantity <= 0 || $receipt->received_cost === null) {
            throw new \LogicException('Only a stock in received at a cost can be reversed.');
        }

        /** @var ProductVariant $variant */
        $variant = ProductVariant::query()->withTrashed()->with('product')->findOrFail($receipt->product_variant_id);
        /** @var Location $location */
        $location = Location::query()->findOrFail($receipt->location_id);
        $this->assertCurrentShop($variant, $location);

        $quantity = Quantity::normalize((float) $receipt->quantity);
        $cost = UnitCost::normalize($receipt->received_cost);

        return DB::transaction(function () use ($receipt, $variant, $location, $quantity, $cost, $user, $reference, $note): StockMovement {
            $locked = $this->lockVariant($variant);
            $level = $this->lockLevel($variant, $location);
            $batch = $receipt->stock_batch_id === null ? null : $this->lockBatch(StockBatch::query()->findOrFail($receipt->stock_batch_id));
            $holds = Quantity::normalize((float) ($batch ?? $level)->quantity);

            if ($holds < $quantity) {
                throw new PosException(sprintf(
                    '%s: %s %s were received%s, but only %s %s left at %s. Stock that was sold or moved since can\'t be reversed: return it to the supplier or count it instead.',
                    $variant->sku,
                    Quantity::format($quantity),
                    $variant->product->unit,
                    $batch === null ? '' : " in batch {$batch->label()}",
                    Quantity::format(max(0.0, $holds)),
                    $batch === null ? 'are' : 'is',
                    $location->name,
                ));
            }

            $level->average_cost = UnitCost::withoutReceipt((float) $level->quantity, $level->average_cost, $quantity, $cost);
            $movement = $this->move($level, $variant, StockMovementType::ReceiptReversal, $quantity, $user, $reference, $cost, note: $note, batch: $batch, receivedCost: $cost);

            $this->refreshVariantCost($locked, $variant, $level);

            return $movement;
        });
    }

    /**
     * Send stock back to a supplier: out of the named batch (any batch with
     * stock: expired, quarantined and recalled ones too) at its purchase
     * cost, or for an item without batches out of the location at its
     * average. The location's average is not changed on the way out
     * (docs/PHARMACY.md §5). Returns the movement and the cost per base unit
     * the credit is worked out at.
     *
     * @param  float  $quantity  base units
     * @return array{movement: StockMovement, unit_cost: string}
     */
    public function returnToSupplier(ProductVariant $variant, Location $location, ?StockBatch $batch, float $quantity, ?User $user, Model $reference, ?string $note = null): array
    {
        $quantity = $this->checkedQuantity($variant, $quantity, false);
        $this->assertCurrentShop($variant, $location);

        if ($variant->product->tracksBatches()) {
            if ($batch === null) {
                throw new PosException("{$variant->sku} tracks batches: choose the batch going back.");
            }

            $this->assertBatchOf($batch, $variant, $location);
        } elseif ($batch !== null) {
            throw new PosException("{$variant->sku} does not track batches.");
        }

        return DB::transaction(function () use ($variant, $location, $batch, $quantity, $user, $reference, $note): array {
            $level = $this->lockLevel($variant, $location);

            if ($batch === null) {
                $cost = UnitCost::normalize($level->average_cost);

                return ['movement' => $this->move($level, $variant, StockMovementType::SupplierReturn, $quantity, $user, $reference, $cost, note: $note), 'unit_cost' => $cost];
            }

            $cost = UnitCost::normalize($this->lockBatch($batch)->unit_cost);
            $allocation = $this->issueLocked(
                $level, $variant, StockMovementType::SupplierReturn, $quantity, $user, $reference,
                proposal: [new BatchPick((int) $batch->getKey(), $quantity)],
                strictProposal: true, picksMayBeUnsellable: true, note: $note, unitCost: $cost,
            );

            return ['movement' => $allocation->pieces[0]['movement'], 'unit_cost' => $cost];
        });
    }

    /**
     * Attribute stock sold beyond the recorded batches (the unallocated
     * batch, below zero) to a real batch: a batch_correction_out from that
     * batch and a batch_correction_in to the unallocated one, net zero on
     * the level. Receipts do this by themselves; this is for when the units
     * turn out to have come from a batch already on record.
     *
     * @return array{0: StockMovement, 1: StockMovement} [out of the batch, into the unallocated batch]
     */
    public function settleUnallocated(StockBatch $from, ?float $quantity, User $user): array
    {
        if (! $user->can('batches.manage')) {
            throw new PosException('You do not have permission to settle unallocated stock.');
        }

        if ($from->is_unallocated) {
            throw new PosException('Choose the batch the units were sold from.');
        }

        /** @var ProductVariant $variant */
        $variant = $from->variant;
        /** @var Location $location */
        $location = $from->location;
        $this->assertCurrentShop($variant, $location);

        if ($quantity !== null && Quantity::normalize($quantity) <= 0) {
            throw new PosException('Quantity must be greater than zero.');
        }

        return DB::transaction(function () use ($from, $variant, $quantity, $user): array {
            $level = $this->lockLevel($variant, $from->location()->firstOrFail());
            $settled = $this->settleLocked($level, $variant, $this->lockBatch($from), $quantity, $user);

            if ($settled === []) {
                throw new PosException("Nothing to settle: {$variant->sku} has no unallocated stock below zero here, or batch {$from->batch_no} is empty.");
            }

            return $settled;
        });
    }

    /**
     * Weighted average cost in minor units, exact: quantities are counted in
     * thousandths (DECIMAL(14,3)) so the sum is whole numbers, and the division
     * rounds half up. Nothing (or less than nothing) on hand: the unit cost.
     * The stored averages use UnitCost::weightedAverage() (4 decimals); this
     * whole-minor version is what they round to.
     */
    public static function weightedAverageCost(float $onHand, int $currentCost, float $quantity, int $unitCost): int
    {
        $onHandMilli = (int) round(Quantity::normalize($onHand) * 1000);
        $quantityMilli = (int) round(Quantity::normalize($quantity) * 1000);

        if ($onHandMilli <= 0 || $quantityMilli <= 0) {
            return $unitCost;
        }

        $value = $onHandMilli * $currentCost + $quantityMilli * $unitCost;
        $units = $onHandMilli + $quantityMilli;

        return intdiv(2 * $value + $units, 2 * $units);
    }

    /**
     * The average cost per base unit of the item at a location, DECIMAL(20,4).
     * A location that never held the item starts at the item's blended cost.
     */
    public function averageCost(ProductVariant $variant, Location $location): string
    {
        $average = StockLevel::query()
            ->where('location_id', $location->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->value('average_cost');

        return UnitCost::normalize($average ?? (int) $variant->cost_price);
    }

    /**
     * Set a location's average cost by hand (Revalue cost). Callers check
     * cost.manage and write the audit entry. Stock and past sales are untouched.
     *
     * @return array{0: string, 1: int, 2: int} the location's old average, the item's old blended cost,
     *                                          and the last movement id at that location (the cost
     *                                          rebuild replays the revaluation right after it)
     */
    public function setAverageCost(ProductVariant $variant, Location $location, int|string $cost): array
    {
        $cost = UnitCost::normalize($cost);

        if (bccomp($cost, '0', UnitCost::SCALE) < 0) {
            throw new PosException('A cost cannot be negative.');
        }

        $this->assertCurrentShop($variant, $location);

        return DB::transaction(function () use ($variant, $location, $cost): array {
            $locked = $this->lockVariant($variant);
            $level = $this->lockLevel($variant, $location);
            $before = UnitCost::normalize($level->average_cost);
            $blendedBefore = (int) $locked->cost_price;

            if (UnitCost::equals($before, $cost)) {
                throw new PosException('That is already the average cost.');
            }

            $level->average_cost = $cost;
            $level->save();

            $this->refreshVariantCost($locked, $variant, $level, quietly: true);

            $lastMovementId = (int) StockMovement::query()
                ->where('location_id', $location->getKey())
                ->where('product_variant_id', $variant->getKey())
                ->max('id');

            return [$before, $blendedBefore, $lastMovementId];
        });
    }

    /**
     * A location's own reorder level for an item; null goes back to the item's.
     * Not a stock change, but the level row is StockService's alone.
     */
    public function setReorderLevel(ProductVariant $variant, Location $location, ?float $reorderLevel): StockLevel
    {
        if ($reorderLevel !== null && $reorderLevel < 0) {
            throw new PosException('A reorder level cannot be negative.');
        }

        $this->assertCurrentShop($variant, $location);

        return DB::transaction(function () use ($variant, $location, $reorderLevel): StockLevel {
            $level = $this->lockLevel($variant, $location);
            $level->update(['reorder_level' => $reorderLevel === null ? null : Quantity::normalize($reorderLevel)]);

            return $level;
        });
    }

    /**
     * A location's par level for an item: what reorder suggestions top it up
     * to (docs/PHARMACY.md §9, branch 10); null = none (suggestions then go
     * up to twice the reorder level). Never below the reorder level in force
     * there (the location's own, else the item's).
     */
    public function setParLevel(ProductVariant $variant, Location $location, ?float $parLevel): StockLevel
    {
        if ($parLevel !== null && $parLevel < 0) {
            throw new PosException('A par level cannot be negative.');
        }

        $this->assertCurrentShop($variant, $location);

        return DB::transaction(function () use ($variant, $location, $parLevel): StockLevel {
            $level = $this->lockLevel($variant, $location);
            $reorderAt = (float) ($level->reorder_level ?? $variant->reorder_level ?? 0);

            if ($parLevel !== null && Quantity::normalize($parLevel) < Quantity::normalize($reorderAt)) {
                throw new PosException('The par level is what stock is topped up to: it can\'t be below the reorder level ('.Quantity::format($reorderAt).').');
            }

            $level->update(['par_level' => $parLevel === null ? null : Quantity::normalize($parLevel)]);

            return $level;
        });
    }

    /**
     * Write off stock of one batch (expiry write-off, docs/PHARMACY.md §9,
     * branch 10): an adjustment_out of the named batch, expired or blocked
     * ones included, valued at the batch's own purchase cost (as a supplier
     * return is), referencing the write-off. Never more than the batch holds;
     * the location's average is not changed on the way out. Callers check
     * stock.write_off.
     *
     * @param  float  $quantity  base units
     */
    public function writeOff(StockBatch $batch, float $quantity, AdjustmentReason $reason, ?User $user, Model $reference, ?string $note = null): StockMovement
    {
        /** @var ProductVariant $variant */
        $variant = $batch->variant()->with('product')->firstOrFail();
        /** @var Location $location */
        $location = $batch->location()->firstOrFail();

        $quantity = $this->checkedQuantity($variant, $quantity, false);
        $this->assertCurrentShop($variant, $location);
        $this->assertTracksBatches($variant);
        $this->assertBatchOf($batch, $variant, $location);

        if ($reason->requiresNote() && blank($note)) {
            throw new PosException('Add a note explaining the write-off.');
        }

        return DB::transaction(function () use ($batch, $variant, $location, $quantity, $reason, $user, $reference, $note): StockMovement {
            $level = $this->lockLevel($variant, $location);
            $cost = UnitCost::normalize($this->lockBatch($batch)->unit_cost);

            return $this->issueLocked(
                $level, $variant, StockMovementType::AdjustmentOut, $quantity, $user, $reference,
                proposal: [new BatchPick((int) $batch->getKey(), $quantity)],
                strictProposal: true, picksMayBeUnsellable: true, reason: $reason, note: $note, unitCost: $cost,
            )->pieces[0]['movement'];
        });
    }

    /**
     * Opening stock for a newly created variant. A batch-tracked item needs
     * the batch it is counted in.
     */
    public function opening(ProductVariant $variant, Location $location, float $quantity, ?User $user = null, int|string|null $unitCost = null, ?BatchInput $batch = null): StockMovement
    {
        if ($variant->product->tracksBatches()) {
            return $this->receive($variant, $location, $batch ?? $this->batchRequired($variant), StockMovementType::Opening, $quantity, $user, unitCost: $unitCost);
        }

        return $this->record($variant, $location, StockMovementType::Opening, $quantity, $user, unitCost: $unitCost);
    }

    /**
     * Stock received (Phase 1 "Stock In" screen; later called by goods
     * received). A batch-tracked item needs the batch number and expiry of
     * what arrived (or an existing batch of it at that location).
     */
    public function stockIn(ProductVariant $variant, Location $location, float $quantity, ?User $user = null, int|string|null $unitCost = null, ?string $note = null, ?Model $reference = null, BatchInput|StockBatch|null $batch = null): StockMovement
    {
        if ($user !== null && ! $user->can('stock.receive')) {
            throw new PosException('You do not have permission to record stock in.');
        }

        if ($variant->product->tracksBatches()) {
            return $this->receive($variant, $location, $batch ?? $this->batchRequired($variant), StockMovementType::StockIn, $quantity, $user, $reference, $unitCost, note: $note);
        }

        return $this->record($variant, $location, StockMovementType::StockIn, $quantity, $user, $reference, $unitCost, note: $note);
    }

    /**
     * Set stock to a counted figure (stock take). Creates the right +/- adjustment.
     * Returns null when the count already matches. A batch-tracked item is
     * counted batch by batch (adjustBatchTo()).
     */
    public function adjustTo(ProductVariant $variant, Location $location, float $countedQuantity, AdjustmentReason $reason, ?User $user = null, ?string $note = null, ?Model $reference = null): ?StockMovement
    {
        if ($user !== null && ! $user->can('stock.adjust')) {
            throw new PosException('You do not have permission to adjust stock.');
        }

        if ($variant->product->tracksBatches()) {
            throw new PosException("{$variant->sku} is counted batch by batch: choose the batch you counted.");
        }

        if ($reason->requiresNote() && blank($note)) {
            throw new PosException('Add a note explaining the adjustment.');
        }

        // The count must be whole; the difference may not be, when it corrects
        // a part unit an offline till already sold.
        $this->assertWholeUnits($variant, $countedQuantity);

        return DB::transaction(function () use ($variant, $location, $countedQuantity, $reason, $user, $note, $reference): ?StockMovement {
            $current = Quantity::normalize((float) $this->lockLevel($variant, $location)->quantity);
            $difference = Quantity::normalize($countedQuantity - $current);

            if ($difference == 0.0) {
                return null;
            }

            return $this->record(
                $variant,
                $location,
                $difference > 0 ? StockMovementType::AdjustmentIn : StockMovementType::AdjustmentOut,
                abs($difference),
                $user,
                $reference,
                reason: $reason,
                note: $note,
                allowNegative: true,
                allowPartUnits: true,
            );
        });
    }

    /**
     * Set one batch at a location to a counted figure (stock take, expiry
     * write-off: count it to 0 with reason "expired"). A batch found on the
     * shelf but not on record is given as a BatchInput; any real expiry is
     * accepted, so expired stock can be recorded and written off. The
     * unallocated batch can only be counted to zero (the oversold units
     * turned out to have been there after all).
     */
    public function adjustBatchTo(ProductVariant $variant, Location $location, StockBatch|BatchInput $batch, float $countedQuantity, AdjustmentReason $reason, ?User $user = null, ?string $note = null, ?Model $reference = null): ?StockMovement
    {
        if ($user !== null && ! $user->can('stock.adjust')) {
            throw new PosException('You do not have permission to adjust stock.');
        }

        if ($reason->requiresNote() && blank($note)) {
            throw new PosException('Add a note explaining the adjustment.');
        }

        if (Quantity::normalize($countedQuantity) < 0) {
            throw new PosException('A count cannot be below zero.');
        }

        $this->assertWholeUnits($variant, $countedQuantity);
        $this->assertCurrentShop($variant, $location);
        $this->assertTracksBatches($variant);

        if ($batch instanceof StockBatch) {
            $this->assertBatchOf($batch, $variant, $location);
        }

        $new = $batch instanceof BatchInput ? $this->batches->validateNew($variant, $batch, $user, receipt: false) : null;

        return DB::transaction(function () use ($variant, $location, $batch, $new, $countedQuantity, $reason, $user, $note, $reference): ?StockMovement {
            $level = $this->lockLevel($variant, $location);
            $counted = Quantity::normalize($countedQuantity);

            if ($new !== null) {
                $existing = $this->findBatch($variant, $location, $new);

                if ($existing === null && $counted == 0.0) {
                    return null;
                }

                [$row, $created] = $existing !== null ? [$existing, false] : $this->findOrCreateBatch($variant, $location, $new, UnitCost::normalize($level->average_cost));
            } else {
                [$row, $created] = [$this->lockBatch($batch), false];
            }

            if ($row->is_unallocated && $counted != 0.0) {
                throw new PosException('Unallocated stock can only be counted to zero. Count the real batches instead.');
            }

            $difference = Quantity::normalize($counted - (float) $row->quantity);

            if ($difference == 0.0) {
                return null;
            }

            $movement = $this->move(
                $level,
                $variant,
                $difference > 0 ? StockMovementType::AdjustmentIn : StockMovementType::AdjustmentOut,
                abs($difference),
                $user,
                $reference,
                null,
                $reason,
                $note,
                allowNegative: true,
                batch: $row,
            );

            if ($created) {
                $row->source()->associate($movement)->save();
            }

            return $movement;
        });
    }

    /**
     * Change an item without batches at a location by a difference found in
     * a stock count (StockCountService: counted - what the system held when
     * the line was counted), whatever moved since. Never takes the location
     * below zero (nor further below, when it already is). Null when there is
     * nothing to change.
     */
    public function adjustBy(ProductVariant $variant, Location $location, float $difference, AdjustmentReason $reason, ?User $user = null, ?string $note = null, ?Model $reference = null): ?StockMovement
    {
        if ($user !== null && ! $user->can('stock.adjust')) {
            throw new PosException('You do not have permission to adjust stock.');
        }

        if ($variant->product->tracksBatches()) {
            throw new PosException("{$variant->sku} is counted batch by batch: choose the batch you counted.");
        }

        if ($reason->requiresNote() && blank($note)) {
            throw new PosException('Add a note explaining the adjustment.');
        }

        return DB::transaction(function () use ($variant, $location, $difference, $reason, $user, $note, $reference): ?StockMovement {
            $current = Quantity::normalize((float) $this->lockLevel($variant, $location)->quantity);
            $target = max(Quantity::normalize($current + $difference), min($current, 0.0));
            $applied = Quantity::normalize($target - $current);

            if ($applied == 0.0) {
                return null;
            }

            return $this->record(
                $variant,
                $location,
                $applied > 0 ? StockMovementType::AdjustmentIn : StockMovementType::AdjustmentOut,
                abs($applied),
                $user,
                $reference,
                reason: $reason,
                note: $note,
                allowNegative: true,
                allowPartUnits: true,
            );
        });
    }

    /**
     * Change one batch at a location by a difference found in a stock count
     * (counted - what the system held for the batch when it was counted),
     * whatever moved since: a sale, transfer or receipt between counting and
     * posting is not a variance. A batch found on the shelf is a BatchInput
     * (any real expiry). A batch never goes below zero (a difference larger
     * than what it holds now takes it to zero); the unallocated batch only
     * moves towards zero, never past it (a full count found where the units
     * sold beyond the batches came from). Null when there is nothing to change.
     */
    public function adjustBatchBy(ProductVariant $variant, Location $location, StockBatch|BatchInput $batch, float $difference, AdjustmentReason $reason, ?User $user = null, ?string $note = null, ?Model $reference = null): ?StockMovement
    {
        if ($user !== null && ! $user->can('stock.adjust')) {
            throw new PosException('You do not have permission to adjust stock.');
        }

        if ($reason->requiresNote() && blank($note)) {
            throw new PosException('Add a note explaining the adjustment.');
        }

        $this->assertCurrentShop($variant, $location);
        $this->assertTracksBatches($variant);

        if ($batch instanceof StockBatch) {
            $this->assertBatchOf($batch, $variant, $location, allowUnallocated: true);
        }

        $new = $batch instanceof BatchInput ? $this->batches->validateNew($variant, $batch, $user, receipt: false) : null;
        $difference = Quantity::normalize($difference);

        return DB::transaction(function () use ($variant, $location, $batch, $new, $difference, $reason, $user, $note, $reference): ?StockMovement {
            $level = $this->lockLevel($variant, $location);

            if ($new !== null) {
                $existing = $this->findBatch($variant, $location, $new);

                if ($existing === null && $difference <= 0) {
                    return null;
                }

                [$row, $created] = $existing !== null ? [$existing, false] : $this->findOrCreateBatch($variant, $location, $new, UnitCost::normalize($level->average_cost));
            } else {
                [$row, $created] = [$this->lockBatch($batch), false];
            }

            $current = Quantity::normalize((float) $row->quantity);
            $target = Quantity::normalize($current + $difference);
            $target = $row->is_unallocated
                ? min(max($target, min($current, 0.0)), max($current, 0.0))
                : max($target, 0.0);
            $applied = Quantity::normalize($target - $current);

            if ($applied == 0.0) {
                return null;
            }

            $movement = $this->move(
                $level,
                $variant,
                $applied > 0 ? StockMovementType::AdjustmentIn : StockMovementType::AdjustmentOut,
                abs($applied),
                $user,
                $reference,
                null,
                $reason,
                $note,
                allowNegative: true,
                batch: $row,
            );

            if ($created) {
                $row->source()->associate($movement)->save();
            }

            return $movement;
        });
    }

    /**
     * Whole units only: on a whole_units_only product a quantity must be a
     * whole number of the unit it is entered in (1 strip, 3 tablets), never
     * 0.5 box, even though half a box of 30 is 15 whole tablets.
     *
     * @param  float  $quantity  in the unit named $unitName, or in base units when it is null
     *
     * @throws PosException
     */
    public function assertWholeUnits(ProductVariant $variant, float $quantity, ?string $unitName = null): void
    {
        if (! $variant->product->whole_units_only || Quantity::isWhole($quantity)) {
            return;
        }

        $unitName ??= $variant->product->unit;

        throw new PosException("{$variant->sku} is sold, received and counted in whole units only: ".Quantity::format($quantity)." {$unitName} is not allowed.");
    }

    /**
     * The allocation itself, with the level already locked (issue(), transfer()).
     *
     * @param  list<BatchPick>|null  $proposal
     * @param  bool  $picksMayBeUnsellable  a named batch may be expired or quarantined (moving it into quarantine)
     */
    private function issueLocked(
        StockLevel $level,
        ProductVariant $variant,
        StockMovementType $type,
        float $quantity,
        ?User $user,
        ?Model $reference,
        ?array $proposal = null,
        bool $strictProposal = false,
        bool $picksMayBeUnsellable = false,
        bool $allowNegative = false,
        ?\DateTimeInterface $occurredAt = null,
        ?int $deviceId = null,
        AdjustmentReason|ReturnReason|null $reason = null,
        ?string $note = null,
        ?string $unitCost = null,
    ): BatchAllocation {
        $product = $variant->product;
        $product->loadMissing('drugDetail');

        // A sale keeps clear of the expiry by the item's minimum days; anything else just
        // never takes an expired batch by itself. The same rule as StockBatch::sellableOn().
        $day = $this->batches->dayOf($occurredAt ?? now());
        $minDays = $type === StockMovementType::Sale ? $this->batches->minDaysToExpiry($product) : 0;
        $cutoffDate = StockBatch::sellableCutoff($day, $minDays);
        $cutoff = CarbonImmutable::parse($cutoffDate);

        // Every batch with stock (a pick may name an unsellable one, and a refusal
        // says what is held back), locked; sellability is checked per batch below.
        /** @var Collection<int, StockBatch> $batches */
        $batches = StockBatch::query()
            ->where('location_id', $level->location_id)
            ->where('product_variant_id', $variant->getKey())
            ->inStock()
            ->fefo()
            ->lockForUpdate()
            ->get();

        $sellable = fn (StockBatch $batch): bool => $batch->isSellableFrom($cutoffDate);

        /** @var array<int, float> $left */
        $left = $batches->mapWithKeys(fn (StockBatch $batch): array => [(int) $batch->getKey() => Quantity::normalize((float) $batch->quantity)])->all();
        /** @var array<int, float> $take batch id => quantity, in the order taken */
        $take = [];
        $remaining = $quantity;
        $differences = [];
        $expiredProposed = false;
        $refuse = function (string $message) use ($strictProposal, &$differences): void {
            if ($strictProposal) {
                throw new PosException($message);
            }

            $differences[] = $message;
        };

        foreach ($proposal ?? [] as $pick) {
            $wanted = Quantity::normalize($pick->quantity);

            if ($wanted <= 0) {
                continue;
            }

            if ($remaining <= 0) {
                $refuse('More was picked from batches than the line holds.');

                break;
            }

            $wanted = min($wanted, $remaining);
            $batch = $batches->firstWhere('id', $pick->batchId);

            if ($batch === null) {
                $refuse($this->batches->notInStockMessage($pick->batchId, (int) $level->product_variant_id, (int) $level->location_id));

                continue;
            }

            if (! $picksMayBeUnsellable && ! $sellable($batch)) {
                $expiredProposed = $expiredProposed || $batch->isExpiredOn($day);
                $refuse($this->batches->notSellableMessage($batch, $day, $this->batches->minDaysToExpiry($product)));

                continue;
            }

            $got = min($wanted, $left[$batch->getKey()]);

            if ($got < $wanted) {
                $refuse('Only '.Quantity::format($got)." {$product->unit} left in batch {$batch->label()}.");
            }

            if ($got > 0) {
                $take[$batch->getKey()] = Quantity::normalize(($take[$batch->getKey()] ?? 0) + $got);
                $left[$batch->getKey()] = Quantity::normalize($left[$batch->getKey()] - $got);
                $remaining = Quantity::normalize($remaining - $got);
            }
        }

        $proposed = array_sum($take);

        // First expiry, first out for whatever the proposal did not cover.
        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $got = min($left[$batch->getKey()], $remaining);

            if (! $sellable($batch) || $got <= 0) {
                continue;
            }

            $take[$batch->getKey()] = Quantity::normalize(($take[$batch->getKey()] ?? 0) + $got);
            $left[$batch->getKey()] = Quantity::normalize($left[$batch->getKey()] - $got);
            $remaining = Quantity::normalize($remaining - $got);
        }

        if ($proposal !== null && ! $strictProposal && array_sum($take) > $proposed && $differences === []) {
            $differences[] = 'The proposal covered '.Quantity::format($proposed).' of '.Quantity::format($quantity)." {$product->unit}; the rest was taken first expiry first out.";
        }

        if ($remaining > 0 && ! $allowNegative) {
            $available = Quantity::normalize($batches->filter($sellable)->sum(fn (StockBatch $batch): float => (float) $batch->quantity));

            throw PosException::insufficientBatchStock($variant->sku, $available, $quantity, $this->batches->heldBack($batches, $day, $cutoff));
        }

        // The batches can cover it, but units already sold beyond them are not settled yet:
        // what is really on hand here is the level, so an online sale may not go below it.
        if (! $allowNegative && Quantity::normalize((float) $level->quantity - $quantity) < 0) {
            $owed = abs((float) StockBatch::query()
                ->where('location_id', $level->location_id)
                ->where('product_variant_id', $variant->getKey())
                ->where('is_unallocated', true)
                ->value('quantity'));

            throw new PosException(sprintf(
                'Not enough stock for %s: %s on hand, %s requested. %s %s sold beyond the recorded batches are not settled yet: settle them on the Batches screen.',
                $variant->sku,
                Quantity::format(max(0.0, (float) $level->quantity)),
                Quantity::format($quantity),
                Quantity::format($owed),
                $product->unit,
            ));
        }

        $pieces = [];

        foreach ($take as $batchId => $pieceQuantity) {
            /** @var StockBatch $batch */
            $batch = $batches->firstWhere('id', $batchId);
            $movement = $this->move($level, $variant, $type, $pieceQuantity, $user, $reference, $unitCost, $reason, $note, $deviceId, $allowNegative, $occurredAt, batch: $batch);
            $pieces[] = ['batch' => $batch, 'quantity' => $pieceQuantity, 'movement' => $movement];
        }

        if ($remaining > 0) {
            $unallocated = $this->lockUnallocated($variant, $level);
            $movement = $this->move($level, $variant, $type, $remaining, $user, $reference, $unitCost, $reason, $note, $deviceId, true, $occurredAt, batch: $unallocated);
            $pieces[] = ['batch' => $unallocated, 'quantity' => $remaining, 'movement' => $movement];
        }

        return new BatchAllocation($pieces, $remaining, $differences, $expiredProposed);
    }

    /**
     * The settling pair (§6.4), with the level and $from locked: as much of
     * the unallocated batch's deficit as $from holds (or $quantity, if less).
     *
     * @return array{0: StockMovement, 1: StockMovement}|array{}
     */
    private function settleLocked(StockLevel $level, ProductVariant $variant, StockBatch $from, ?float $quantity, ?User $user, ?\DateTimeInterface $occurredAt = null): array
    {
        $unallocated = StockBatch::query()
            ->where('location_id', $level->location_id)
            ->where('product_variant_id', $variant->getKey())
            ->where('is_unallocated', true)
            ->lockForUpdate()
            ->first();

        if ($unallocated === null || (float) $unallocated->quantity >= 0) {
            return [];
        }

        $settle = Quantity::normalize(min(-(float) $unallocated->quantity, (float) $from->quantity, $quantity ?? PHP_FLOAT_MAX));

        if ($settle <= 0) {
            return [];
        }

        // Net zero on the level, which may already be below zero (that is what is being settled).
        $out = $this->move($level, $variant, StockMovementType::BatchCorrectionOut, $settle, $user, $unallocated, null, note: self::SETTLE_NOTE, allowNegative: true, occurredAt: $occurredAt, batch: $from);
        $in = $this->move($level, $variant, StockMovementType::BatchCorrectionIn, $settle, $user, $from, null, note: self::SETTLE_NOTE, allowNegative: true, occurredAt: $occurredAt, batch: $unallocated);

        return [$out, $in];
    }

    /** The batch that most recently settled an unallocated batch, if any. */
    private function settlingBatch(StockBatch $unallocated): ?StockBatch
    {
        $batchId = StockMovement::query()
            ->where('type', StockMovementType::BatchCorrectionOut)
            ->where('reference_type', $unallocated->getMorphClass())
            ->where('reference_id', $unallocated->getKey())
            ->latest('id')
            ->value('stock_batch_id');

        return $batchId === null ? null : StockBatch::query()->whereKey($batchId)->first();
    }

    /**
     * Write one movement against a level that is already locked, and update
     * the level (quantity, and any average the caller changed on it), and the
     * batch's quantity when it names one (locked too). Only the unallocated
     * batch may go below zero.
     */
    private function move(
        StockLevel $level,
        ProductVariant $variant,
        StockMovementType $type,
        float $quantity,
        ?User $user,
        ?Model $reference,
        ?string $unitCost,
        AdjustmentReason|ReturnReason|null $reason = null,
        ?string $note = null,
        ?int $deviceId = null,
        bool $allowNegative = false,
        ?\DateTimeInterface $occurredAt = null,
        ?StockBatch $batch = null,
        ?string $receivedCost = null,
    ): StockMovement {
        $change = $quantity * $type->direction();
        $newBalance = Quantity::normalize((float) $level->quantity + $change);

        if ($newBalance < 0 && $change < 0 && ! $allowNegative) {
            throw PosException::insufficientStock($variant->sku, (float) $level->quantity, $quantity);
        }

        $batchBalance = $batch === null ? null : Quantity::normalize((float) $batch->quantity + $change);

        if ($batch !== null && $batchBalance < 0 && $change < 0 && ! $batch->is_unallocated) {
            throw PosException::insufficientStock("{$variant->sku} batch {$batch->batch_no}", (float) $batch->quantity, $quantity);
        }

        $movement = new StockMovement([
            'location_id' => $level->location_id,
            'product_variant_id' => $variant->getKey(),
            'stock_batch_id' => $batch?->getKey(),
            'type' => $type,
            'quantity' => $change,
            'balance_after' => $newBalance,
            'batch_balance_after' => $batchBalance,
            // The ledger keeps whole minor units per base unit, as it always has.
            'unit_cost' => UnitCost::toMinor($unitCost ?? $level->average_cost),
            'received_cost' => $receivedCost,
            'reason_code' => $reason?->value,
            'note' => blank($note) ? null : trim($note),
            'user_id' => $user?->getKey(),
            'device_id' => $deviceId,
            'occurred_at' => $occurredAt ?? now(),
        ]);

        if ($reference !== null) {
            $movement->reference()->associate($reference);
        }

        $movement->save();

        $level->quantity = (string) $newBalance;
        $level->save();

        if ($batch !== null) {
            $wasAtOrAboveZero = (float) $batch->quantity >= 0;
            $batch->quantity = (string) $batchBalance;
            $batch->save();

            if ($batch->is_unallocated && $wasAtOrAboveZero && $batchBalance < 0) {
                $this->batches->alertUnallocated($batch);
            }
        }

        return $movement;
    }

    /**
     * Keep product_variants.cost_price as the blend of the locations' averages
     * (and last_cost_price as the latest receipt's cost), whole minor units.
     * With no stock anywhere it follows the location that just changed. Saved
     * through Eloquent so the audit log keeps the cost history, unless $quietly
     * (the caller writes its own entry). Must run with the variant locked.
     */
    private function refreshVariantCost(ProductVariant $locked, ProductVariant $callers, StockLevel $changed, ?int $lastCost = null, bool $quietly = false): void
    {
        $blend = UnitCost::blend(StockLevel::query()
            ->where('product_variant_id', $locked->getKey())
            ->get(['quantity', 'average_cost'])
            ->map(fn (StockLevel $level): array => [(string) $level->quantity, $level->average_cost]));

        $locked->forceFill(['cost_price' => UnitCost::toMinor($blend ?? $changed->average_cost)]);

        if ($lastCost !== null) {
            $locked->forceFill(['last_cost_price' => $lastCost]);
        }

        $quietly ? $locked->saveQuietly() : $locked->save();

        // The caller's instance shows the new cost too.
        $callers->forceFill(['cost_price' => $locked->cost_price, 'last_cost_price' => $locked->last_cost_price])->syncOriginal();
    }

    private function lockVariant(ProductVariant $variant): ProductVariant
    {
        return ProductVariant::query()->withTrashed()->whereKey($variant->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Lock (creating if needed) the stock level row for update. A new row
     * starts at the item's blended average and last cost, so an item with a
     * cost but no stock yet keeps costing what it did.
     */
    private function lockLevel(ProductVariant $variant, Location $location): StockLevel
    {
        $attributes = [
            'location_id' => $location->getKey(),
            'product_variant_id' => $variant->getKey(),
        ];

        $level = StockLevel::query()->where($attributes)->lockForUpdate()->first();

        if ($level === null) {
            // Unique index on (location_id, product_variant_id) protects against a race here.
            StockLevel::query()->firstOrCreate($attributes, ['quantity' => 0, ...$this->startingCosts($variant)]);
            $level = StockLevel::query()->where($attributes)->lockForUpdate()->firstOrFail();
        }

        return $level;
    }

    /** Re-read a batch row for update; only ever while its level is locked. */
    private function lockBatch(StockBatch $batch): StockBatch
    {
        return StockBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * The item's unallocated batch at the level's location, created on first
     * need. Only called with the level locked, which serialises the creation.
     */
    private function lockUnallocated(ProductVariant $variant, StockLevel $level): StockBatch
    {
        $attributes = [
            'location_id' => $level->location_id,
            'product_variant_id' => $variant->getKey(),
            'is_unallocated' => true,
        ];

        $batch = StockBatch::query()->where($attributes)->lockForUpdate()->first();

        if ($batch !== null) {
            return $batch;
        }

        return $this->lockBatch(StockBatch::query()->create([
            ...$attributes,
            'batch_no' => '',
            'expiry_date' => null,
            'unit_cost' => 0,
            'status' => BatchStatus::Active,
            'quantity' => 0,
            'received_at' => now(),
        ]));
    }

    /** @param  array{batch_no: string, expiry_date: string}  $new */
    private function findBatch(ProductVariant $variant, Location $location, array $new): ?StockBatch
    {
        return StockBatch::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('location_id', $location->getKey())
            ->where('is_unallocated', false)
            ->where('batch_no', $new['batch_no'])
            ->whereDate('expiry_date', $new['expiry_date'])
            ->lockForUpdate()
            ->first();
    }

    /**
     * The batch with this number and expiry at the location, created if it is
     * new (with $cost as its purchase cost). Only called with the level locked.
     *
     * @param  array{batch_no: string, expiry_date: string}  $new
     * @return array{0: StockBatch, 1: bool} the locked batch, and whether it was created
     */
    private function findOrCreateBatch(ProductVariant $variant, Location $location, array $new, string $cost, ?\DateTimeInterface $receivedAt = null): array
    {
        $existing = $this->findBatch($variant, $location, $new);

        if ($existing !== null) {
            return [$existing, false];
        }

        $batch = StockBatch::query()->create([
            'product_variant_id' => $variant->getKey(),
            'location_id' => $location->getKey(),
            'batch_no' => $new['batch_no'],
            'expiry_date' => $new['expiry_date'],
            'unit_cost' => $cost,
            'status' => BatchStatus::Active,
            'is_unallocated' => false,
            'quantity' => 0,
            'received_at' => $receivedAt ?? now(),
        ]);

        return [$this->lockBatch($batch), true];
    }

    /**
     * The same batch (number, expiry) at another location, created on its
     * first transfer there with the source's purchase cost, receipt time and
     * status. Only called with the destination level locked.
     *
     * @return array{0: StockBatch, 1: bool}
     */
    private function counterpart(StockBatch $source, Location $location, ?\DateTimeInterface $occurredAt): array
    {
        $existing = StockBatch::query()
            ->where('product_variant_id', $source->product_variant_id)
            ->where('location_id', $location->getKey())
            ->where('is_unallocated', false)
            ->where('batch_no', $source->batch_no)
            ->when(
                $source->expiry_date === null,
                fn ($query) => $query->whereNull('expiry_date'),
                fn ($query) => $query->whereDate('expiry_date', (string) $source->expiry_date?->toDateString()),
            )
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            return [$existing, false];
        }

        $batch = StockBatch::query()->create([
            'product_variant_id' => $source->product_variant_id,
            'location_id' => $location->getKey(),
            'batch_no' => $source->batch_no,
            'expiry_date' => $source->expiry_date?->toDateString(),
            'unit_cost' => $source->unit_cost,
            'status' => $source->status,
            'is_unallocated' => false,
            'quantity' => 0,
            'received_at' => $source->received_at ?? $occurredAt ?? now(),
        ]);

        return [$this->lockBatch($batch), true];
    }

    /** @return array{average_cost: string, last_cost: string|null} */
    private function startingCosts(ProductVariant $variant): array
    {
        $costs = ProductVariant::query()->withTrashed()->whereKey($variant->getKey())->first(['cost_price', 'last_cost_price']);
        $last = $costs->last_cost_price ?? null;

        return [
            'average_cost' => UnitCost::normalize((int) ($costs->cost_price ?? 0)),
            'last_cost' => $last === null ? null : UnitCost::normalize((int) $last),
        ];
    }

    private function checkedQuantity(ProductVariant $variant, float $quantity, bool $allowPartUnits): float
    {
        $quantity = Quantity::normalize($quantity);

        if ($quantity <= 0) {
            throw new PosException('Quantity must be greater than zero.');
        }

        if (! $allowPartUnits) {
            $this->assertWholeUnits($variant, $quantity);
        }

        return $quantity;
    }

    private function batchRequired(ProductVariant $variant): never
    {
        throw new PosException("{$variant->sku} tracks batches: enter the batch number and expiry date printed on the pack.");
    }

    private function assertTracksBatches(ProductVariant $variant): void
    {
        if (! $variant->product->tracksBatches()) {
            throw new \LogicException("{$variant->sku} does not track batches: use record().");
        }
    }

    private function assertBatchOf(StockBatch $batch, ProductVariant $variant, Location $location, bool $allowUnallocated = false): void
    {
        if ((int) $batch->product_variant_id !== (int) $variant->getKey() || (int) $batch->location_id !== (int) $location->getKey()) {
            throw new PosException("That batch is not a batch of {$variant->sku} at {$location->name}.");
        }

        if ($batch->is_unallocated && ! $allowUnallocated) {
            throw new PosException('Choose a real batch: unallocated stock has no batch number.');
        }
    }

    private function assertCurrentShop(ProductVariant $variant, Location $location): void
    {
        $shopId = (int) $this->currentShop->getOrFail()->getKey();

        if ((int) $variant->shop_id !== $shopId || (int) $location->shop_id !== $shopId) {
            throw new \LogicException('Variant and location must belong to the current shop.');
        }
    }

    /**
     * Recalculate cached stock levels from the ledger. Used by
     * `php artisan pos:stock-rebuild` as an integrity check. Each level is
     * locked while it is recalculated, so it is safe while tills are selling.
     *
     * @return int number of levels that were wrong and got fixed
     */
    public function rebuildLevels(): int
    {
        $pairs = StockMovement::query()
            ->select(['location_id', 'product_variant_id'])
            ->distinct()
            ->get()
            ->map(fn (StockMovement $m) => [(int) $m->location_id, (int) $m->product_variant_id])
            ->merge(StockLevel::query()->get(['location_id', 'product_variant_id'])
                ->map(fn (StockLevel $l) => [(int) $l->location_id, (int) $l->product_variant_id]))
            ->unique(fn (array $pair) => implode(':', $pair))
            ->values();

        $fixed = 0;

        foreach ($pairs as [$locationId, $variantId]) {
            $fixed += DB::transaction(function () use ($locationId, $variantId): int {
                $attributes = ['location_id' => $locationId, 'product_variant_id' => $variantId];

                StockLevel::query()->firstOrCreate($attributes, ['quantity' => 0]);
                $level = StockLevel::query()->where($attributes)->lockForUpdate()->firstOrFail();

                $total = Quantity::normalize((float) StockMovement::query()->where($attributes)->sum('quantity'));

                if (Quantity::normalize((float) $level->quantity) === $total) {
                    return 0;
                }

                $level->update(['quantity' => $total]);

                return 1;
            });
        }

        return $fixed;
    }

    /**
     * Recalculate every batch's quantity from the ledger (the sum of the
     * movements that name it). Each batch is checked with its level locked.
     *
     * @return int number of batches that were wrong and got fixed
     */
    public function rebuildBatches(): int
    {
        $fixed = 0;

        foreach (StockBatch::query()->get(['id', 'location_id', 'product_variant_id']) as $batch) {
            $fixed += DB::transaction(function () use ($batch): int {
                StockLevel::query()->where('location_id', $batch->location_id)->where('product_variant_id', $batch->product_variant_id)->lockForUpdate()->first();
                $row = $this->lockBatch($batch);
                $total = Quantity::normalize((float) StockMovement::query()->where('stock_batch_id', $row->getKey())->sum('quantity'));

                if (Quantity::normalize((float) $row->quantity) === $total) {
                    return 0;
                }

                $row->update(['quantity' => $total]);

                return 1;
            });
        }

        return $fixed;
    }

    /**
     * Go-live only (pos:go-live-reset, docs/deploy/GO-LIVE.md): removes these
     * movements from the ledger, the one time movements are ever deleted
     * (rule 6), then puts every stock level, batch quantity and running
     * balance they touched back to what the ledger without them says. A
     * sale, return or void never changed an average cost, so costs stand.
     * The caller deletes whatever else points at the movements first.
     * Returns how many movements were removed.
     *
     * @param  list<int>  $movementIds
     */
    public function forgetMovements(array $movementIds): int
    {
        if ($movementIds === []) {
            return 0;
        }

        return DB::transaction(function () use ($movementIds): int {
            $pairs = [];
            $batchIds = [];

            foreach (array_chunk($movementIds, 500) as $chunk) {
                foreach (StockMovement::query()->whereKey($chunk)->get(['location_id', 'product_variant_id', 'stock_batch_id']) as $movement) {
                    $pairs[$movement->location_id.':'.$movement->product_variant_id] = [(int) $movement->location_id, (int) $movement->product_variant_id];

                    if ($movement->stock_batch_id !== null) {
                        $batchIds[(int) $movement->stock_batch_id] = true;
                    }
                }
            }

            $deleted = 0;

            foreach (array_chunk($movementIds, 500) as $chunk) {
                $deleted += StockMovement::query()->whereKey($chunk)->delete();
            }

            foreach ($pairs as [$locationId, $variantId]) {
                $attributes = ['location_id' => $locationId, 'product_variant_id' => $variantId];
                $level = StockLevel::query()->where($attributes)->lockForUpdate()->first();
                $balance = '0';
                $batchBalances = [];

                foreach (StockMovement::query()->where($attributes)->orderBy('id')->get(['id', 'quantity', 'stock_batch_id', 'balance_after', 'batch_balance_after']) as $movement) {
                    $balance = (string) Quantity::normalize((float) $balance + (float) $movement->quantity);
                    $changes = ['balance_after' => $balance];

                    if ($movement->stock_batch_id !== null) {
                        $batch = (int) $movement->stock_batch_id;
                        $batchBalances[$batch] = Quantity::normalize(($batchBalances[$batch] ?? 0.0) + (float) $movement->quantity);
                        $changes['batch_balance_after'] = $batchBalances[$batch];
                    }

                    StockMovement::query()->whereKey($movement->getKey())->toBase()->update($changes);
                }

                $level?->update(['quantity' => Quantity::normalize((float) $balance)]);
            }

            foreach (array_keys($batchIds) as $batchId) {
                StockBatch::query()->whereKey($batchId)->update([
                    'quantity' => Quantity::normalize((float) StockMovement::query()->where('stock_batch_id', $batchId)->sum('quantity')),
                ]);
            }

            return $deleted;
        });
    }

    /**
     * Stock levels of batch-tracked items whose batches do not add up to the
     * level: stock that moved without a batch (before the item tracked
     * batches). Reported by pos:stock-rebuild; a stock count fixes them.
     *
     * @return list<array{sku: string, location: string, level: float, batches: float}>
     */
    public function batchMismatches(): array
    {
        $mismatches = [];

        $levels = StockLevel::query()
            ->with(['variant.product', 'location'])
            ->whereHas('variant.product', fn ($product) => $product->where('track_stock', true)->where('track_batches', true))
            ->get();

        foreach ($levels as $level) {
            $inBatches = Quantity::normalize((float) StockBatch::query()
                ->where('location_id', $level->location_id)
                ->where('product_variant_id', $level->product_variant_id)
                ->sum('quantity'));

            if ($inBatches !== Quantity::normalize((float) $level->quantity)) {
                $mismatches[] = [
                    'sku' => (string) $level->variant->sku,
                    'location' => (string) $level->location->name,
                    'level' => Quantity::normalize((float) $level->quantity),
                    'batches' => $inBatches,
                ];
            }
        }

        return $mismatches;
    }

    /**
     * Rebuild each location's average and last cost from the ledger
     * (docs/PHARMACY.md §5), replaying its movements in the order they were
     * written: a receipt at a cost and a transfer in re-average with the exact
     * cost they carried (received_cost), a receipt reversal takes its cost
     * back out (UnitCost::withoutReceipt()), a revaluation (audit log
     * `cost_revalued`) sets the average, everything else leaves it alone.
     * Exactly what StockService did, so a level that differs was changed
     * outside it, or predates 4-place costs (branch 4) and differs by rounding.
     *
     * Reports only, unless $write: then each wrong level is set to the
     * rebuilt figures with the item locked, the item's blended cost follows,
     * and each change is audited as `cost_rebuilt`.
     *
     * @return list<array{sku: string, location: string, stored_average: string, rebuilt_average: string, stored_last: string|null, rebuilt_last: string|null}>
     */
    public function rebuildCosts(bool $write = false, ?int $userId = null): array
    {
        $shop = $this->currentShop->getOrFail();
        $defaultLocationId = (int) $shop->defaultLocation()->getKey();
        $locationIds = Location::query()->pluck('id', 'code');
        $revaluations = $this->revaluations($defaultLocationId, $locationIds->all());
        $differences = [];

        foreach (StockLevel::query()->with(['variant', 'location'])->orderBy('id')->get() as $level) {
            $key = "{$level->location_id}:{$level->product_variant_id}";
            $rebuilt = $this->replayCost($level, $revaluations[$key] ?? []);

            if ($rebuilt === null) {
                continue; // nothing in the ledger ever set a cost here
            }

            [$average, $last] = $rebuilt;
            $storedLast = $level->last_cost === null ? null : UnitCost::normalize($level->last_cost);

            if (UnitCost::equals($average, $level->average_cost) && ($last === null || $storedLast === $last)) {
                continue;
            }

            $differences[] = [
                'sku' => (string) $level->variant->sku,
                'location' => (string) $level->location->name,
                'stored_average' => UnitCost::normalize($level->average_cost),
                'rebuilt_average' => $average,
                'stored_last' => $storedLast,
                'rebuilt_last' => $last,
            ];

            if ($write) {
                DB::transaction(function () use ($level, $average, $last, $storedLast, $userId): void {
                    /** @var ProductVariant $variant */
                    $variant = $level->variant;
                    $locked = $this->lockVariant($variant);
                    /** @var Location $location */
                    $location = $level->location;
                    $row = $this->lockLevel($variant, $location);
                    $old = ['location' => $location->code, 'average_cost' => UnitCost::normalize($row->average_cost), 'last_cost' => $storedLast];

                    $row->average_cost = $average;
                    $row->last_cost = $last ?? $row->last_cost;
                    $row->save();

                    $this->refreshVariantCost($locked, $variant, $row, quietly: true);

                    app(AuditObserver::class)->record('cost_rebuilt', $variant, $old, [
                        'location' => $location->code,
                        'average_cost' => $average,
                        'last_cost' => $last,
                        'cost_price' => (int) $locked->cost_price,
                    ], $userId);
                });
            }
        }

        return $differences;
    }

    /**
     * @param  list<array{after: int|null, at: string, average: string}>  $revaluations  in the order they happened
     * @return array{0: string, 1: string|null}|null [average, last cost], or null when nothing set a cost
     */
    private function replayCost(StockLevel $level, array $revaluations): ?array
    {
        $average = null;
        $last = null;
        $onHand = 0.0;
        $next = 0;

        $movements = StockMovement::query()
            ->where('location_id', $level->location_id)
            ->where('product_variant_id', $level->product_variant_id)
            ->orderBy('id')
            ->get(['id', 'type', 'quantity', 'unit_cost', 'received_cost', 'created_at']);

        foreach ($movements as $movement) {
            // Revaluations made before this movement was written.
            while (isset($revaluations[$next]) && ($revaluations[$next]['after'] !== null
                ? $revaluations[$next]['after'] < $movement->getKey()
                : $revaluations[$next]['at'] < (string) $movement->created_at?->toDateTimeString())) {
                $average = $revaluations[$next++]['average'];
            }

            // A level starts at the item's cost of the day; its first movement records it.
            $average ??= $movement->unit_cost === null ? null : UnitCost::normalize($movement->unit_cost);

            // A receipt reversal carries the cost it takes back out: the exact inverse.
            if ($movement->received_cost !== null && (float) $movement->quantity < 0) {
                $average = UnitCost::withoutReceipt($onHand, $average ?? '0', abs((float) $movement->quantity), $movement->received_cost);
            } elseif ($movement->received_cost !== null) {
                $average = UnitCost::weightedAverage($onHand, $average ?? '0', abs((float) $movement->quantity), $movement->received_cost);

                if ($movement->type->updatesAverageCost()) {
                    $last = UnitCost::normalize($movement->received_cost);
                }
            }

            $onHand = Quantity::normalize($onHand + (float) $movement->quantity);
        }

        while (isset($revaluations[$next])) {
            $average = $revaluations[$next++]['average'];
        }

        return $average === null ? null : [UnitCost::normalize($average), $last];
    }

    /**
     * Every cost revaluation in the audit log, by "location:variant", oldest
     * first. Older entries without a location are the default location's;
     * older entries without after_movement_id are placed by time.
     *
     * @param  array<string, int>  $locationIds  code => id
     * @return array<string, list<array{after: int|null, at: string, average: string}>>
     */
    private function revaluations(int $defaultLocationId, array $locationIds): array
    {
        $byLevel = [];

        $entries = AuditLog::query()
            ->where('shop_id', $this->currentShop->id())
            ->where('event', 'cost_revalued')
            ->where('auditable_type', (new ProductVariant)->getMorphClass())
            ->orderBy('id')
            ->get();

        foreach ($entries as $entry) {
            $new = $entry->new_values ?? [];
            $locationId = (int) ($new['location_id'] ?? $locationIds[$new['location'] ?? ''] ?? $defaultLocationId);
            $average = $new['average_cost'] ?? $new['cost_price'] ?? null;

            if ($average === null) {
                continue;
            }

            $byLevel["{$locationId}:{$entry->auditable_id}"][] = [
                'after' => isset($new['after_movement_id']) ? (int) $new['after_movement_id'] : null,
                'at' => (string) $entry->created_at?->toDateTimeString(),
                'average' => UnitCost::normalize($average),
            ];
        }

        return $byLevel;
    }
}
