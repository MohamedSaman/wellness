<?php

namespace App\Services\Data;

/** One line of a stock transfer as entered: an item, a quantity and the unit it is in. */
final readonly class TransferLine
{
    public function __construct(
        public int $variantId,
        public float $quantity,
        // The unit the quantity is in (box, strip, tablet); null = the base unit.
        public ?int $unitId = null,
        // Batch-tracked items: the batch to move (any batch with stock at the source);
        // null = first expiry first out over the batches that have not expired.
        public ?int $batchId = null,
    ) {}
}
