<?php

namespace Tests\Feature\Pos;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Pos\Concerns\SeedsDemoClinic;
use Tests\TestCase;

/**
 * Seeds the pharmacy install profile (demo clinic: General Pharmacy and
 * Doctor's Medicines, owner, cashier) and sets the clinic as the current
 * shop. The rest of the suite stays on the retail profile (phpunit.xml).
 */
abstract class PharmacyTestCase extends TestCase
{
    use RefreshDatabase, SeedsDemoClinic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDemoClinic();
    }
}
