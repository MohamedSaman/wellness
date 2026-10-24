<?php

namespace App\Enums;

enum StockMovementType: string
{
    case Opening = 'opening';             // first stock count when the item is created
    case StockIn = 'stock_in';            // stock received: Stock In, or a posted goods received note
    case Sale = 'sale';
    case SaleVoid = 'sale_void';          // reverses a voided sale
    case Return = 'return';               // customer return put back on the shelf
    case AdjustmentIn = 'adjustment_in';  // count correction up
    case AdjustmentOut = 'adjustment_out'; // damage, loss, count correction down
    case TransferIn = 'transfer_in';      // multi_location module
    case TransferOut = 'transfer_out';
    // A pair that moves quantity between two batches of one item at one location, net
    // zero on the level: settling oversold (unallocated) stock onto a real batch.
    case BatchCorrectionIn = 'batch_correction_in';
    case BatchCorrectionOut = 'batch_correction_out';
    // Purchasing module: stock sent back to a supplier from named batches, and a
    // reversing goods received note taking a posted receipt back out of its batch.
    case SupplierReturn = 'supplier_return';
    case ReceiptReversal = 'receipt_reversal';

    /** Whether this movement adds (+1) or removes (-1) stock. */
    public function direction(): int
    {
        return match ($this) {
            self::Opening, self::StockIn, self::SaleVoid, self::Return,
            self::AdjustmentIn, self::TransferIn, self::BatchCorrectionIn => 1,
            self::Sale, self::AdjustmentOut, self::TransferOut, self::BatchCorrectionOut,
            self::SupplierReturn, self::ReceiptReversal => -1,
        };
    }

    /**
     * Receipts that bring stock in at a purchase cost and so re-average the
     * variant's cost. Returns and voids come back at the sale's own unit cost
     * and adjustments have no new cost, so they leave the average alone.
     */
    public function updatesAverageCost(): bool
    {
        return in_array($this, [self::Opening, self::StockIn], true);
    }

    /**
     * Receipts of new stock: for a batch-tracked item they first settle the
     * location's oversold (unallocated) stock onto the batch that arrived
     * (docs/PHARMACY.md §6.4). Returns, voids, counts and transfers name
     * their batch and never settle.
     */
    public function settlesUnallocated(): bool
    {
        return in_array($this, [self::Opening, self::StockIn], true);
    }

    /** Movements only the purchasing module writes (hidden from filters while it is off). */
    public function isPurchasing(): bool
    {
        return in_array($this, [self::SupplierReturn, self::ReceiptReversal], true);
    }

    /**
     * value => label for the type filters; the purchasing types only when the shop has purchasing.
     *
     * @return array<string, string>
     */
    public static function options(bool $withPurchasing): array
    {
        return collect(self::cases())
            ->reject(fn (self $type): bool => $type->isPurchasing() && ! $withPurchasing)
            ->mapWithKeys(fn (self $type): array => [$type->value => $type->label()])
            ->all();
    }

    public function requiresReason(): bool
    {
        return in_array($this, [self::AdjustmentIn, self::AdjustmentOut], true);
    }

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }

    /** The label of a stored reason code: adjustments and returns each have their own list. */
    public function reasonLabel(?string $code): ?string
    {
        return match ($this) {
            self::AdjustmentIn, self::AdjustmentOut => AdjustmentReason::tryFrom((string) $code)?->label(),
            self::Return => ReturnReason::tryFrom((string) $code)?->label(),
            default => null,
        };
    }
}
