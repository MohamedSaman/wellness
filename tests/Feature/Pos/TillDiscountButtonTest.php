<?php

namespace Tests\Feature\Pos;

use Inertia\Testing\AssertableInertia as Assert;

/**
 * The till shows the Discount button (and line discounts, and F4) only to
 * a cashier who may give a discount (decided 2026-10-22): no limit
 * (pos.discount.unlimited, the owner) or a cashier limit above 0. The
 * clinic's cashiers have 0, so they see none; checkout still refuses a
 * discount above the limit (CheckoutService), whatever the till shows.
 */
class TillDiscountButtonTest extends PharmacyTestCase
{
    private function canDiscount(): bool
    {
        $can = null;
        $this->get(route('pos.index'))->assertOk()->assertInertia(function (Assert $page) use (&$can): void {
            $can = $page->toArray()['props']['cashier']['canDiscount'];
        });

        return (bool) $can;
    }

    public function test_a_cashier_with_a_limit_of_0_gets_no_discount_button(): void
    {
        $this->assertSame(0, (int) $this->shop->setting('max_cashier_discount_percent'));

        $this->actingAs($this->cashier);
        $this->assertFalse($this->canDiscount());

        $this->shop->update(['settings' => [...($this->shop->settings ?? []), 'max_cashier_discount_percent' => 5]]);
        $this->assertTrue($this->canDiscount());
    }

    public function test_the_owner_without_a_limit_keeps_it(): void
    {
        $this->actingAs($this->owner);

        $this->assertTrue($this->canDiscount());
    }
}
