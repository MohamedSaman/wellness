<?php

namespace App\Services\Data;

/**
 * Labels for one item in one unit: how many copies, and the batch to print
 * on them (its number and expiry, shown when the template prints them).
 * $unitId null = the base unit, which scans with the item's own barcode.
 */
final readonly class LabelRequest
{
    public function __construct(
        public int $variantId,
        public ?int $unitId,
        public int $quantity,
        public ?int $stockBatchId = null,
        public ?string $batchNo = null,
        public ?string $expiry = null, // Y-m-d
    ) {}
}
