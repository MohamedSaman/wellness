<?php

namespace Tests\Feature\Pos;

use App\Models\Shop;
use App\Services\ShiftService;
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

    public function test_the_retail_shift_summary_is_as_it_was(): void
    {
        $shifts = app(ShiftService::class);
        $shift = $shifts->open($this->till, $this->cashier, 100_000);
        $summary = $shifts->summary($shift);

        $this->assertSame(['opening', 'sales', 'refunds', 'expected', 'counted', 'difference'], array_keys($summary['cash']));
        $this->assertArrayNotHasKey('movements', $summary);
        $this->assertArrayNotHasKey('doctor_payments', $summary);
        $this->assertArrayNotHasKey('no_receipt_refunds', $summary);

        $this->actingAs($this->cashier)->get(route('pos.shift.summary', ['shift' => $shift->uuid]))
            ->assertOk()
            ->assertDontSee('Paid out')
            ->assertDontSee('Doctor payments')
            ->assertDontSee('without receipt');
    }

    public function test_retail_has_no_returns_without_a_receipt(): void
    {
        $this->actingAs($this->owner)->withCookie('pos_till', $this->till->uuid)->get(route('pos.index'))
            ->assertInertia(fn (Assert $page) => $page->where('noReceiptReturns', false));
        $this->actingAs($this->owner)
            ->getJson(route('pos.no-receipt-returns.index', ['device' => $this->till->uuid]))
            ->assertNotFound();
        $this->actingAs($this->owner)
            ->postJson(route('pos.no-receipt-returns.store'), ['device' => $this->till->uuid, 'reason' => 'x', 'lines' => []])
            ->assertNotFound();
    }
}
