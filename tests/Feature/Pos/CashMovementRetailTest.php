<?php

namespace Tests\Feature\Pos;

use App\Models\Shop;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

/**
 * Retail is unchanged by branch 18 (docs/PHARMACY.md): the cash_movements
 * module starts off for a retail shop, so its till, shift summary and
 * reports are as they were.
 */
class CashMovementRetailTest extends PosTestCase
{
    public function test_a_retail_shop_starts_with_cash_movements_off(): void
    {
        Feature::for($this->shop)->forget('module:cash_movements');

        $this->assertFalse(Shop::query()->findOrFail($this->shop->getKey())->hasModule('cash_movements'));
        $this->assertFalse($this->shop->hasModule('pharmacy'));
    }

    public function test_the_retail_till_has_no_cash_button_nor_endpoints(): void
    {
        $this->actingAs($this->cashier)->withCookie('pos_till', $this->till->uuid)->get(route('pos.index'))
            ->assertInertia(fn (Assert $page) => $page->where('cashMovements', null));
        $this->actingAs($this->cashier)
            ->getJson(route('pos.cash-movements.index', ['device' => $this->till->uuid]))
            ->assertNotFound();
        $this->actingAs($this->cashier)
            ->postJson(route('pos.cash-movements.store'), ['device' => $this->till->uuid, 'type' => 'paid_out', 'amount' => '10', 'category' => 'expense'])
            ->assertNotFound();
    }
}
