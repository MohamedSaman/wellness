<?php

namespace App\Services\Data;

/** One line of a supplier return: an item, the batch going back (batch-tracked items), and a quantity in any of its units. */
final readonly class SupplierReturnLine
{
    public function __construct(
        public int $variantId,
        public float $quantity,
        public ?int $unitId = null,
        public ?int $batchId = null,
    ) {}
}
