<?php

namespace App\Services\Data;

use Carbon\CarbonInterface;

/**
 * A batch as typed on a receipt, an opening count or a stock count: the
 * number printed on the pack and its expiry. The expiry can be a date or a
 * printed month ("03/2027", "2027-03"), which means the last day of that
 * month. acceptNearExpiry is the owner's go-ahead for stock that expires
 * soon (docs/PHARMACY.md §11).
 */
final readonly class BatchInput
{
    public function __construct(
        public string $batchNo,
        public CarbonInterface|string $expiry,
        public bool $acceptNearExpiry = false,
    ) {}
}
