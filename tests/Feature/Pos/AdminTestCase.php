<?php

namespace Tests\Feature\Pos;

use Filament\Facades\Filament;

/**
 * Base for tests of the Filament admin panel (/admin).
 */
abstract class AdminTestCase extends PosTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }
}
