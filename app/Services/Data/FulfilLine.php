<?php

namespace App\Services\Data;

/** What is sent now for one item of a stock request: a quantity in a unit of the item, optionally from a named batch. */
final readonly class FulfilLine
{
    public function __construct(
        public int $itemId,
        public float $quantity,
        // The unit sent (box, strip, tablet); null = the unit the item was asked for in.
        public ?int $unitId = null,
        // Batch-tracked items: the batch to send; null = first expiry first out.
        public ?int $batchId = null,
    ) {}
}
