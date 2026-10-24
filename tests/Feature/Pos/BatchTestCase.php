<?php

namespace Tests\Feature\Pos;

use Carbon\CarbonImmutable;
use Tests\Feature\Pos\Concerns\WorksWithBatches;

/**
 * Batch and expiry tests on the demo clinic (docs/PHARMACY.md §6), with the
 * clock fixed at 2026-10-18 10:00 in Colombo so expiry dates are exact.
 * The helpers (medicines, receipts, sales, assertLedgerMatches()) are in
 * WorksWithBatches, shared with the MySQL group.
 */
abstract class BatchTestCase extends PharmacyTestCase
{
    use WorksWithBatches;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-18 04:30:00', 'UTC'));
    }
}
