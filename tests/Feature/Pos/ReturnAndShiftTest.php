<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Enums\SaleStatus;
use App\Exceptions\PosException;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\PaymentInput;
use App\Services\ReturnService;
use App\Services\ShiftService;
use App\Support\Money;

class ReturnAndShiftTest extends PosTestCase
{
    public function test_partial_return_refunds_what_was_paid_and_restocks(): void
    {
        $plug = $this->variant('CPR8EA-9');
        $sale = app(CheckoutService::class)->checkout($this->cart([new CartLine($plug->getKey(), 4)]));
        $item = $sale->items->first();

        $return = app(ReturnService::class)->process($sale, [$item->getKey() => 1], ReturnReason::WrongItem, PaymentMethod::Cash, $this->owner);

        $this->assertSame(Money::toMinor(1450), $return->refund_total);
        $this->assertSame(37.0, $this->stockOf($plug));
        $this->assertSame(SaleStatus::PartiallyReturned, $sale->fresh()->status);

        // Returning the rest refunds exactly the remainder and closes the sale.
        app(ReturnService::class)->process($sale, [$item->getKey() => 3], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);
        $this->assertSame(SaleStatus::Returned, $sale->fresh()->status);
        $this->assertSame($sale->grand_total, $sale->fresh()->refunded_total);
    }

    public function test_cannot_return_more_than_was_sold(): void
    {
        $sale = app(CheckoutService::class)->checkout($this->cart([new CartLine($this->variant('CPR8EA-9')->getKey(), 1)]));

        $this->expectException(PosException::class);

        app(ReturnService::class)->process($sale, [$sale->items->first()->getKey() => 2], ReturnReason::WrongItem, PaymentMethod::Cash, $this->owner);
    }

    public function test_cashier_cannot_process_returns_by_default(): void
    {
        $sale = app(CheckoutService::class)->checkout($this->cart([new CartLine($this->variant('CPR8EA-9')->getKey(), 1)]));

        $this->expectException(PosException::class);

        app(ReturnService::class)->process($sale, [$sale->items->first()->getKey() => 1], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->cashier);
    }

    public function test_shift_close_calculates_expected_cash_and_difference(): void
    {
        $shifts = app(ShiftService::class);
        $shift = $shifts->open($this->till, $this->cashier, Money::toMinor(5000));

        // Sale of 1,450.00 paid with 2,000.00 cash => 550.00 change.
        app(CheckoutService::class)->checkout($this->cart(
            [new CartLine($this->variant('CPR8EA-9')->getKey(), 1)],
            [new PaymentInput(PaymentMethod::Cash, Money::toMinor(2000))],
        ));

        $closed = $shifts->close($shift, $this->cashier, Money::toMinor(6400));

        $this->assertSame(Money::toMinor(6450), $closed->expected_cash);
        $this->assertSame(Money::toMinor(-50), $closed->difference);
    }

    public function test_only_one_open_shift_per_till(): void
    {
        $shifts = app(ShiftService::class);
        $shifts->open($this->till, $this->cashier, 0);

        $this->expectException(PosException::class);

        $shifts->open($this->till, $this->owner, 0);
    }
}
