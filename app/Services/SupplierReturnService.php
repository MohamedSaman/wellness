<?php

namespace App\Services;

use App\Enums\GoodsReceiptStatus;
use App\Enums\SupplierReturnReason;
use App\Exceptions\PosException;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\StockBatch;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Models\VariantUnit;
use App\Services\Data\SupplierReturnLine;
use App\Support\CalendarDay;
use App\Support\CurrentShop;
use App\Support\UnitCost;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stock sent back to a supplier (purchasing module, docs/PHARMACY.md §4.9):
 * expired, near expiry, damaged or recalled goods, picked batch by batch at
 * one location. Saving posts it, in one transaction: a supplier_return
 * movement out of each named batch (any batch with stock, expired or
 * recalled too) at that batch's purchase cost, and the credit expected
 * (base quantity x batch cost), which lowers the supplier's balance. Items
 * without batches leave at the location's average cost. The location's
 * average is not changed (docs/PHARMACY.md §5). Needs purchasing.manage.
 *
 * Posted returns are never changed, except that the supplier's credit note
 * number can be added when it arrives. The credit reduces what is owed on
 * the GRN the goods came from (SupplierPayablesService); what that GRN no
 * longer owes stays as the supplier's credit.
 */
class SupplierReturnService
{
    public const MAX_LINES = 200;

    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly StockService $stock,
        private readonly BatchService $batches,
        private readonly PurchaseLineResolver $lines,
        private readonly SupplierPayablesService $payables,
    ) {}

    /**
     * $from is the GRN the goods came from; its outstanding is reduced by the
     * credit. Left out, it is the one GRN of this supplier that brought in
     * every batch returned, if there is exactly one; otherwise the credit
     * stays on the supplier's account to allocate later.
     *
     * @param  list<SupplierReturnLine>  $lines
     */
    public function create(Supplier $supplier, Location $location, SupplierReturnReason $reason, array $lines, User $user, ?string $notes = null, ?string $creditNoteNo = null, ?string $returnedOn = null, ?GoodsReceipt $from = null): SupplierReturn
    {
        $this->authorize($user);
        $shop = $this->currentShop->getOrFail();

        if ((int) $supplier->shop_id !== (int) $shop->getKey() || (int) $location->shop_id !== (int) $shop->getKey()) {
            throw new \LogicException('Supplier and location must belong to the current shop.');
        }

        if ($lines === []) {
            throw new PosException('Add at least one item to return.');
        }

        if (count($lines) > self::MAX_LINES) {
            throw new PosException('A return can have at most '.self::MAX_LINES.' lines.');
        }

        $day = blank($returnedOn) ? $this->batches->today() : CalendarDay::parse((string) $returnedOn);

        if ($day->greaterThan($this->batches->today())) {
            throw new PosException('A return can\'t be dated in the future.');
        }

        $prepared = $this->prepare($lines, $location);
        $receiptId = $this->fromReceipt($supplier, $prepared, $from);

        return DB::transaction(function () use ($shop, $supplier, $location, $reason, $prepared, $user, $notes, $creditNoteNo, $day, $receiptId): SupplierReturn {
            // One number at a time per shop; then the supplier, whose invoice the credit settles.
            Shop::query()->whereKey($shop->getKey())->lockForUpdate()->firstOrFail();
            $this->payables->lockSupplier((int) $supplier->getKey());
            $number = 'SR-'.str_pad((string) (SupplierReturn::query()->count() + 1), 6, '0', STR_PAD_LEFT);

            $return = SupplierReturn::query()->create([
                'return_number' => $number,
                'supplier_id' => $supplier->getKey(),
                'location_id' => $location->getKey(),
                'goods_receipt_id' => $receiptId,
                'reason' => $reason,
                'credit_note_no' => $this->creditNote($creditNoteNo),
                'returned_on' => $day->toDateString(),
                'notes' => blank($notes) ? null : trim((string) $notes),
                'user_id' => $user->getKey(),
            ]);

            $note = "{$number} to {$supplier->name}: {$reason->label()}";
            $total = 0;

            // Item order, as everywhere stock is locked.
            usort($prepared, fn (array $a, array $b): int => [$a['variant']->getKey(), $a['batch']?->getKey()] <=> [$b['variant']->getKey(), $b['batch']?->getKey()]);

            foreach ($prepared as $line) {
                ['movement' => $movement, 'unit_cost' => $cost] = $this->stock->returnToSupplier($line['variant'], $location, $line['batch'], $line['base_quantity'], $user, $return, $note);
                $lineTotal = UnitCost::times($cost, $line['base_quantity']);
                $total += $lineTotal;

                $return->items()->create([
                    'product_variant_id' => $line['variant']->getKey(),
                    'stock_batch_id' => $line['batch']?->getKey(),
                    'batch_no' => $line['batch']?->batch_no,
                    'expiry_date' => $line['batch']?->expiry_date?->toDateString(),
                    'variant_unit_id' => $line['unit']->getKey(),
                    'unit_name' => $line['unit']->name,
                    'unit_factor' => (float) $line['unit']->factor,
                    'quantity' => $line['quantity'],
                    'base_quantity' => $line['base_quantity'],
                    'unit_cost' => $cost,
                    'line_total' => $lineTotal,
                    'stock_movement_id' => $movement->getKey(),
                ]);
            }

            $return->forceFill(['total' => $total])->saveQuietly();

            // The credit goes against the GRN the goods came from, as far as it is still owed.
            $this->payables->returnPosted($return, $user);

            return $return->load('items');
        }, attempts: 3);
    }

    /** The supplier's credit note number, when it arrives. */
    public function setCreditNote(SupplierReturn $return, ?string $creditNoteNo, User $user): SupplierReturn
    {
        $this->authorize($user);

        $return->update(['credit_note_no' => $this->creditNote($creditNoteNo)]);

        return $return;
    }

    /**
     * @param  list<SupplierReturnLine>  $lines
     * @return list<array{variant: ProductVariant, unit: VariantUnit, batch: StockBatch|null, quantity: float, base_quantity: float}>
     */
    private function prepare(array $lines, Location $location): array
    {
        $variants = $this->lines->variants(array_map(fn (SupplierReturnLine $line): int => $line->variantId, $lines));
        $prepared = [];

        foreach ($lines as $line) {
            $variant = $this->lines->variant($variants, $line->variantId);
            $unit = $this->lines->unit($variant, $line->unitId, bought: false);
            $quantity = $this->lines->quantity($variant, $unit, $line->quantity);

            if ($quantity <= 0) {
                throw new PosException("Enter how many of {$variant->sku} go back.");
            }

            $batch = null;

            if ($variant->product->tracksBatches()) {
                $batch = $line->batchId === null ? null : StockBatch::query()
                    ->where('product_variant_id', $variant->getKey())
                    ->where('location_id', $location->getKey())
                    ->where('is_unallocated', false)
                    ->find($line->batchId);

                if ($batch === null) {
                    throw new PosException("Choose the batch of {$variant->sku} at {$location->name} that goes back.");
                }
            } elseif ($line->batchId !== null) {
                throw new PosException("{$variant->sku} does not track batches.");
            }

            $prepared[] = ['variant' => $variant, 'unit' => $unit, 'batch' => $batch, 'quantity' => $quantity, 'base_quantity' => $unit->toBase($quantity)];
        }

        return $prepared;
    }

    /**
     * The GRN a return's credit goes against: the one given (posted, of this
     * supplier, not a reversal), else the only GRN of the supplier that
     * received every batch going back; null when there is none or several.
     *
     * @param  list<array{variant: ProductVariant, unit: VariantUnit, batch: StockBatch|null, quantity: float, base_quantity: float}>  $prepared
     */
    private function fromReceipt(Supplier $supplier, array $prepared, ?GoodsReceipt $from): ?int
    {
        if ($from !== null) {
            if ((int) $from->supplier_id !== (int) $supplier->getKey() || ! $from->isPosted() || $from->isReversal()) {
                throw new PosException('Pick a posted goods received note from '.$supplier->name.'.');
            }

            return (int) $from->getKey();
        }

        $batchIds = array_map(fn (array $line): ?int => $line['batch']?->getKey(), $prepared);

        if (in_array(null, $batchIds, true)) {
            return null;
        }

        $receipts = GoodsReceiptItem::query()
            ->whereIn('stock_batch_id', array_unique($batchIds))
            ->whereHas('goodsReceipt', fn ($query) => $query
                ->where('supplier_id', $supplier->getKey())
                ->where('status', GoodsReceiptStatus::Posted)
                ->whereNull('reversal_of_id')
                ->whereDoesntHave('reversal'))
            ->distinct()
            ->pluck('goods_receipt_id');

        return $receipts->count() === 1 ? (int) $receipts->first() : null;
    }

    private function creditNote(?string $creditNoteNo): ?string
    {
        $text = trim((string) $creditNoteNo);

        if (Str::length($text) > 50) {
            throw new PosException('A credit note number can have at most 50 characters.');
        }

        return $text === '' ? null : $text;
    }

    private function authorize(User $user): void
    {
        if (! $user->can('purchasing.manage')) {
            throw new PosException('You do not have permission to return stock to suppliers.');
        }

        if (! $this->currentShop->getOrFail()->hasModule('purchasing')) {
            throw new PosException('Supplier returns need the purchasing module.');
        }
    }
}
