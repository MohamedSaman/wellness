<?php

namespace App\Services\Data;

final readonly class CartLine
{
    /**
     * @param  list<BatchPick>|null  $batches
     */
    public function __construct(
        public int $variantId,
        public float $quantity,
        public int $lineDiscount = 0, // minor units
        // What an offline till charged per unit (minor units). Only read when
        // CheckoutData::$isOfflineSync is set; online sales always use the price list.
        public ?int $tillUnitPrice = null,
        // The unit sold (box, strip, tablet); null = the variant's base unit.
        // quantity is in this unit.
        public ?int $unitId = null,
        // The location the line's stock comes from (multi_location); null = the
        // till's location. The till does not send it yet (checkout branch).
        public ?int $locationId = null,
        // Batch-tracked items: the batches to take, in base units. Online, a
        // pharmacist's pick (pos.batch_override); offline, the till's proposal.
        // null = first expiry first out.
        public ?array $batches = null,
        // A typed unit price (minor units) for a product that allows it (a service
        // such as the consultation fee); online it needs pos.price_override.
        public ?int $unitPriceOverride = null,
        // Offline: the till's tillUnitPrice was a typed override, not the price list's.
        public bool $tillPriceOverride = false,
        // Prescription lines: the prescriber's name and the prescription's reference.
        public ?string $prescriber = null,
        public ?string $prescriptionRef = null,
    ) {}
}
