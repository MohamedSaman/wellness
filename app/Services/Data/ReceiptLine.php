<?php

namespace App\Services\Data;

/**
 * One line of a goods received note as entered: the item, the unit it came
 * in (box, strip, loose), how many were paid for and how many came free,
 * the cost of one of that unit before discount, the line discount and any
 * tax the invoice shows on the line (minor units). Batch-tracked items also carry the batch number and expiry
 * printed on the pack (a date, or a month such as 03/2027) and the owner's
 * go-ahead for short expiry.
 */
final readonly class ReceiptLine
{
    public function __construct(
        public int $variantId,
        public float $quantity,
        public ?int $unitId = null,
        public float $freeQuantity = 0,
        public int $unitCost = 0,
        public int $discount = 0,
        public ?string $batchNo = null,
        public ?string $expiry = null,
        public bool $acceptShortExpiry = false,
        // The purchase order line it delivers, when received against an order.
        public ?int $purchaseOrderItemId = null,
        // Tax (VAT) on the line: part of the cost, as the shop does not reclaim it.
        public int $tax = 0,
    ) {}
}
