<?php

namespace App\Services\Data;

/**
 * One item of a return without a receipt (docs/PHARMACY.md, branch 18): the
 * item and unit, how many, the value refunded per unit (null = the unit's
 * price today), where the stock goes (Quarantine by default, or back to
 * sellable stock at the till's location), and the batch printed on the pack
 * (required for a medicine back on sale; may be unknown for Quarantine).
 */
final readonly class NoReceiptLine
{
    public function __construct(
        public int $variantId,
        public float $quantity,
        public ?int $unitId = null,
        public ?int $unitValue = null,
        public bool $toQuarantine = true,
        public ?string $batchNo = null,
        public ?string $expiry = null,
    ) {}
}
