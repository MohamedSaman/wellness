<?php

namespace App\Services\Data;

/** One line of a purchase order as entered: an item, a quantity in any of its units, and the cost expected per unit. */
final readonly class PurchaseLine
{
    public function __construct(
        public int $variantId,
        public float $quantity,
        // The unit ordered (box, strip, tablet); null = the base unit.
        public ?int $unitId = null,
        // Expected cost of one of that unit, minor units.
        public int $unitCost = 0,
    ) {}
}
