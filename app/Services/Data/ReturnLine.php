<?php

namespace App\Services\Data;

/**
 * One line of a customer return (ReturnService::processLines()): a line of
 * the sale, the quantity coming back in the unit it was sold in or, with
 * $inBaseUnits, in base units (10 tablets from a box of 30), whether it goes
 * back on the shelf, and for a line that took several batches how much
 * comes back from each (sale_item_batch_id => base units; null = latest
 * expiry first). At the till (branch 19) each line also says where its
 * stock goes: $toLocationId, Quarantine or the till's location
 * (StockService::returnTo()); null keeps the admin's rule (the line's own
 * location, when restocked).
 */
final readonly class ReturnLine
{
    /**
     * @param  array<int, float>|null  $batches
     */
    public function __construct(
        public int $saleItemId,
        public float $quantity,
        public bool $inBaseUnits = false,
        public bool $restock = true,
        public ?array $batches = null,
        public ?int $toLocationId = null,
    ) {}
}
