<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use Tests\Feature\Pos\Concerns\BootsDashboardWidgets;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/** Every dashboard widget boots on the clinic: freshly installed (nothing sold, nothing in stock) and with a day's trade. */
class DashboardWidgetsBootTest extends BatchTestCase
{
    use BootsDashboardWidgets;
    use WorksWithCheckout;

    public function test_every_widget_class_loads(): void
    {
        $this->assertNotEmpty($this->dashboardWidgets());

        foreach ($this->dashboardWidgets() as $widget) {
            $this->assertTrue(class_exists($widget), $widget);
        }
    }

    public function test_every_widget_renders_on_an_empty_clinic(): void
    {
        $this->assertGreaterThan(0, $this->bootEveryWidget($this->owner));
    }

    public function test_every_widget_renders_with_a_days_trade(): void
    {
        $tabs = $this->medicine();
        $this->receive($tabs, 'G1', '03/2027', 50);
        $this->receive($tabs, 'D1', '11/2026', 20, $this->doctors);

        app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->genTill(),
            cashier: $this->cashier,
            lines: [new CartLine($tabs->getKey(), 2)],
            payments: [new PaymentInput(PaymentMethod::Cash, 100_000)],
        ));

        $this->assertGreaterThan(0, $this->bootEveryWidget($this->owner));
    }
}
