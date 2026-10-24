<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Exceptions\PosException;
use App\Models\Sale;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;

/**
 * What the pharmacy profile's starting settings mean for the demo clinic
 * (client answers, 2026-10-20). The short-expiry window and receiving at
 * the default location are tested with batches and purchasing.
 */
class ClinicSettingsTest extends BatchTestCase
{
    private function sellWithDiscount(User $cashier, int $discount): Sale
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'A', '12/2027', 10);

        return app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->till,
            cashier: $cashier,
            lines: [new CartLine($panadol->getKey(), 1)],
            payments: [new PaymentInput(PaymentMethod::Cash, 10_000)],
            saleDiscount: $discount,
        ));
    }

    public function test_the_clinic_gives_no_discounts_at_the_till_but_the_owner_still_can(): void
    {
        $this->assertSame(0, $this->shop->setting('max_cashier_discount_percent'));

        try {
            $this->sellWithDiscount($this->cashier, 100); // 1.00 off 20.00
            $this->fail('A clinic cashier gave a discount.');
        } catch (PosException $exception) {
            $this->assertStringContainsString('above your limit', $exception->getMessage());
        }

        $this->assertSame(1_900, $this->sellWithDiscount($this->owner, 100)->grand_total);
    }
}
