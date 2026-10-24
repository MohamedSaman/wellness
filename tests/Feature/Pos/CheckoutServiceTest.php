<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Enums\TaxCategory;
use App\Exceptions\PosException;
use App\Models\Product;
use App\Models\Sale;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\PaymentInput;
use App\Support\Money;
use Illuminate\Support\Str;

class CheckoutServiceTest extends PosTestCase
{
    private CheckoutService $checkout;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checkout = app(CheckoutService::class);
    }

    public function test_a_cash_sale_uses_server_prices_gives_change_and_reduces_stock(): void
    {
        $plug = $this->variant('CPR8EA-9');      // LKR 1,450.00
        $oil = $this->variant('MOTUL-3000');      // LKR 3,200.00

        $sale = $this->checkout->checkout($this->cart(
            [new CartLine($plug->getKey(), 2), new CartLine($oil->getKey(), 1)],
            [new PaymentInput(PaymentMethod::Cash, Money::toMinor(10000))],
        ));

        $this->assertSame('T1-000001', $sale->invoice_no);
        $this->assertSame(SaleStatus::Completed, $sale->status);
        $this->assertSame(Money::toMinor(6100), $sale->grand_total);
        $this->assertSame(Money::toMinor(3900), $sale->change_due);
        $this->assertCount(2, $sale->items);
        $this->assertSame(38.0, $this->stockOf($plug));
        $this->assertSame(23.0, $this->stockOf($oil));
    }

    public function test_invoice_numbers_increase_per_till(): void
    {
        $plug = $this->variant('CPR8EA-9');

        $this->checkout->checkout($this->cart([new CartLine($plug->getKey(), 1)]));
        $second = $this->checkout->checkout($this->cart([new CartLine($plug->getKey(), 1)]));

        $this->assertSame('T1-000002', $second->invoice_no);
    }

    public function test_the_same_sale_uuid_is_only_recorded_once(): void
    {
        $plug = $this->variant('CPR8EA-9');
        $uuid = (string) Str::uuid7();

        $first = $this->checkout->checkout($this->cart([new CartLine($plug->getKey(), 1)], uuid: $uuid));
        $retry = $this->checkout->checkout($this->cart([new CartLine($plug->getKey(), 1)], uuid: $uuid));

        $this->assertSame($first->getKey(), $retry->getKey());
        $this->assertSame(1, Sale::query()->count());
        $this->assertSame(39.0, $this->stockOf($plug));
    }

    public function test_payment_must_cover_the_total(): void
    {
        $this->expectException(PosException::class);

        $this->checkout->checkout($this->cart(
            [new CartLine($this->variant('CPR8EA-9')->getKey(), 1)],
            [new PaymentInput(PaymentMethod::Cash, Money::toMinor(1000))],
        ));
    }

    public function test_change_cannot_be_given_from_a_card_payment(): void
    {
        $this->expectException(PosException::class);

        $this->checkout->checkout($this->cart(
            [new CartLine($this->variant('CPR8EA-9')->getKey(), 1)],
            [new PaymentInput(PaymentMethod::Card, Money::toMinor(5000))],
        ));
    }

    public function test_cashier_discount_is_limited_but_owner_is_not(): void
    {
        $helmet = $this->variant('VEGA-OFF-ROAD-HELMET'); // LKR 18,500.00
        $bigDiscount = Money::toMinor(5000);               // ~27%, above the 10% cashier limit

        try {
            $this->checkout->checkout($this->cart([new CartLine($helmet->getKey(), 1)], saleDiscount: $bigDiscount));
            $this->fail('Cashier should not be able to give a 27% discount.');
        } catch (PosException $e) {
            $this->assertStringContainsString('above your limit', $e->getMessage());
        }

        $sale = $this->checkout->checkout($this->cart([new CartLine($helmet->getKey(), 1)], cashier: $this->owner, saleDiscount: $bigDiscount));

        $this->assertSame(Money::toMinor(13500), $sale->grand_total);
    }

    public function test_tax_inclusive_prices_split_out_the_tax(): void
    {
        $this->shop->update(['tax_enabled' => true, 'tax_rate' => 18, 'prices_include_tax' => true]);
        $this->useShop($this->shop->fresh());

        $sale = $this->checkout->checkout($this->cart([new CartLine($this->variant('MOTUL-3000')->getKey(), 1)]));

        // 3,200.00 incl. 18% VAT => tax = 3200 * 18 / 118 = 488.14
        $this->assertSame(Money::toMinor(3200), $sale->grand_total);
        $this->assertSame(48814, $sale->tax_total);
    }

    public function test_retail_products_are_all_standard_rated_and_taxed_as_before(): void
    {
        $this->assertSame(0, Product::query()->where('tax_category', '!=', TaxCategory::Standard)->count());

        $this->shop->update(['tax_enabled' => true, 'tax_rate' => 10, 'prices_include_tax' => false]);
        $this->useShop($this->shop->fresh());

        $sale = $this->checkout->checkout($this->cart([
            new CartLine($this->variant('MOTUL-3000')->getKey(), 1),
            new CartLine($this->variant('CPR8EA-9')->getKey(), 2),
        ]));

        // 3,200.00 + 2 x 1,450.00 = 6,100.00, plus 10% on every line.
        $this->assertSame(Money::toMinor(610), $sale->tax_total);
        $this->assertSame(['standard', 'standard'], $sale->items->map(fn ($item) => $item->tax_category?->value)->all());
    }

    public function test_cannot_sell_items_that_are_out_of_stock(): void
    {
        $this->expectException(PosException::class);

        $this->checkout->checkout($this->cart([new CartLine($this->variant('CPR8EA-9')->getKey(), 41)]));
    }

    public function test_owner_can_void_a_sale_and_stock_comes_back(): void
    {
        $plug = $this->variant('CPR8EA-9');
        $sale = $this->checkout->checkout($this->cart([new CartLine($plug->getKey(), 5)]));
        $this->assertSame(35.0, $this->stockOf($plug));

        $voided = $this->checkout->void($sale, $this->owner, 'Customer changed mind before leaving');

        $this->assertSame(SaleStatus::Voided, $voided->status);
        $this->assertSame(40.0, $this->stockOf($plug));
    }

    public function test_cashier_cannot_void_a_sale(): void
    {
        $sale = $this->checkout->checkout($this->cart([new CartLine($this->variant('CPR8EA-9')->getKey(), 1)]));

        $this->expectException(PosException::class);

        $this->checkout->void($sale, $this->cashier, 'Trying');
    }
}
