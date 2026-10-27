<?php

namespace Tests\Feature\Pos;

use App\Http\Controllers\DashboardController;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

/**
 * /dashboard on the clinic: the owner goes to the admin dashboard; the
 * cashier gets a welcome by the shop's clock (Asia/Colombo) with a button
 * to the till. Signing in still takes the cashier straight to the till.
 */
class InertiaDashboardTest extends PharmacyTestCase
{
    public function test_the_owner_goes_to_the_admin_dashboard(): void
    {
        $this->actingAs($this->owner)->get(route('dashboard'))->assertRedirect('/admin');
    }

    public function test_the_cashier_is_welcomed_by_the_shops_clock_with_a_way_to_the_till(): void
    {
        // 18:30 in Colombo is 13:00 UTC.
        $this->travelTo(CarbonImmutable::parse('2026-10-18 13:00:00', 'UTC'));

        $this->actingAs($this->cashier)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('greeting', 'Good evening')
                ->where('canUsePos', true));

        $this->travelTo(CarbonImmutable::parse('2026-10-18 03:00:00', 'UTC')); // 08:30
        $this->assertSame('Good morning', DashboardController::greeting($this->cashier));
        $this->travelTo(CarbonImmutable::parse('2026-10-18 08:00:00', 'UTC')); // 13:30
        $this->assertSame('Good afternoon', DashboardController::greeting($this->cashier));
    }

    public function test_signing_in_still_takes_the_cashier_to_the_till(): void
    {
        $this->assertSame('/pos', $this->cashier->homePath());
        $this->assertSame('/admin', $this->owner->homePath());
    }
}
