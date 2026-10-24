<?php

namespace App\Services\Data;

/** One item of a stock request as entered: an item, a quantity and the unit it is in. */
final readonly class RequestLine
{
    public function __construct(
        public int $variantId,
        public float $quantity,
        // The unit the quantity is in (box, strip, tablet); null = the base unit.
        public ?int $unitId = null,
    ) {}
}
