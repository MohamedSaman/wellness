<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Models\Shift;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\PaymentInput;
use App\Services\ReturnService;
use App\Services\ShiftService;

/**
 * A cashier's own shift summary at the till (docs/PHARMACY.md §9, branch
 * 11): bills, sales, refunds, voids and net, what each payment method took
 * and the drawer, expected against counted. It is all a cashier sees of
 * the takings: another cashier's shift is refused, the owner may read any.
 */
class ShiftSummaryTest extends PosTestCase
{
    /**
     * A shift with two bills (one with change), a card bill, a void and a
     * cash refund: float 5,000.00 + cash 2,900.00 - refund 1,450.00.
     */
    private function aShift(): Shift
    {
        $shift = app(ShiftService::class)->open($this->till, $this->cashier, 500_000);
        $checkout = app(CheckoutService::class);
        $plug = $this->variant('CPR8EA-9')->getKey(); // 1,450.00

        $first = $checkout->checkout($this->cart([new CartLine($plug, 2)], [new PaymentInput(PaymentMethod::Cash, 300_000)]));
        $checkout->checkout($this->cart([new CartLine($plug, 1)], [new PaymentInput(PaymentMethod::Card, 145_000)]));
        $voided = $checkout->checkout($this->cart([new CartLine($plug, 1)], [new PaymentInput(PaymentMethod::Cash, 145_000)]));
        $checkout->void($voided, $this->owner, 'Rung up twice');
        app(ReturnService::class)->process($first, [$first->items[0]->getKey() => 1], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);

        return $shift->fresh();
    }

    public function test_the_summary_adds_up_to_the_drawer(): void
    {
        $shift = $this->aShift();
        $summary = app(ShiftService::class)->summary($shift);

        $this->assertSame([2, 435_000, 145_000, 1, 1, 145_000, 290_000], [
            $summary['bills'], $summary['gross'], $summary['refunds'], $summary['refunds_count'], $summary['voids'], $summary['voids_total'], $summary['net'],
        ]);
        $this->assertSame([['method' => 'cash', 'label' => 'Cash', 'amount' => 290_000], ['method' => 'card', 'label' => 'Card', 'amount' => 145_000]], $summary['payments']);
        $this->assertSame(['opening' => 500_000, 'sales' => 290_000, 'refunds' => 145_000, 'expected' => 645_000, 'counted' => null, 'difference' => null], $summary['cash']);
        $this->assertSame(app(ShiftService::class)->expectedCash($shift), $summary['cash']['expected']);

        $closed = app(ShiftService::class)->close($shift, $this->cashier, 640_000);
        $this->assertSame(['opening' => 500_000, 'sales' => 290_000, 'refunds' => 145_000, 'expected' => 645_000, 'counted' => 640_000, 'difference' => -5_000], app(ShiftService::class)->summary($closed)['cash']);
    }

    public function test_the_cashier_sees_their_own_shift_at_the_till_and_on_closing(): void
    {
        $this->aShift();

        $this->actingAs($this->cashier)
            ->getJson(route('pos.shift.expected', ['device' => $this->till->uuid]))
            ->assertOk()
            ->assertJsonPath('summary.bills', 2)
            ->assertJsonPath('summary.net', 290_000)
            ->assertJsonPath('summary.cash.expected', 645_000)
            ->assertJsonPath('expected_cash', 645_000);

        $closed = $this->actingAs($this->cashier)
            ->postJson(route('pos.shift.close'), ['device' => $this->till->uuid, 'counted_cash' => '6450'])
            ->assertOk()
            ->assertJsonPath('summary.cash.difference', 0)
            ->assertJsonPath('closed.difference', 0)
            ->json('closed.uuid');

        $this->actingAs($this->cashier)
            ->get(route('pos.shift.summary', ['shift' => $closed]))
            ->assertOk()
            ->assertSee('Shift summary')
            ->assertSee($this->shop->formatMoney(290_000))
            ->assertSee($this->shop->formatMoney(645_000))
            ->assertSee($this->cashier->name);
    }

    public function test_a_cashier_cannot_read_another_cashiers_shift_but_the_owner_can(): void
    {
        $shift = $this->aShift();

        $other = User::factory()->create(['shop_id' => $this->shop->getKey()]);
        $other->assignRole('cashier');

        $this->actingAs($other)->get(route('pos.shift.summary', ['shift' => $shift->uuid]))->assertForbidden();
        $this->actingAs($this->owner)->get(route('pos.shift.summary', ['shift' => $shift->uuid]))->assertOk()->assertSee($this->shop->formatMoney(645_000));

        // On a till where someone else's shift is open, the cashier gets the drawer, not the takings.
        $this->actingAs($other)
            ->getJson(route('pos.shift.expected', ['device' => $this->till->uuid]))
            ->assertOk()
            ->assertJsonPath('summary', null);
    }
}
