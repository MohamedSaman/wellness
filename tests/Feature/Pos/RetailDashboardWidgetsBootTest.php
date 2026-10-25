<?php

namespace Tests\Feature\Pos;

use Tests\Feature\Pos\Concerns\BootsDashboardWidgets;

/** Every dashboard widget boots on the retail demo shop (pharmacy off). */
class RetailDashboardWidgetsBootTest extends AdminTestCase
{
    use BootsDashboardWidgets;

    public function test_every_widget_renders_on_the_retail_shop(): void
    {
        $this->assertGreaterThan(0, $this->bootEveryWidget($this->owner));
    }
}
