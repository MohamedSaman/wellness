<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Models\Device;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\User;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use App\Support\CurrentShop;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Seeds the demo moto-gear shop (owner, cashier, helmets, jackets, parts)
 * and sets it as the current shop for every POS test.
 */
abstract class PosTestCase extends TestCase
{
    use RefreshDatabase;

    protected Shop $shop;

    protected User $owner;

    protected User $cashier;

    protected Device $till;

    protected Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->shop = Shop::query()->where('code', 'DEMO')->firstOrFail();
        $this->useShop($this->shop);

        $this->owner = User::query()->where('email', 'owner@pos.test')->firstOrFail();
        $this->cashier = User::query()->where('email', 'cashier@pos.test')->firstOrFail();
        $this->till = Device::query()->where('code', 'T1')->firstOrFail();
        $this->location = $this->shop->defaultLocation();
    }

    protected function useShop(?Shop $shop): void
    {
        app(CurrentShop::class)->set($shop);
    }

    protected function variant(string $skuStartsWith): ProductVariant
    {
        return ProductVariant::query()->where('sku', 'like', $skuStartsWith.'%')->orderBy('id')->firstOrFail();
    }

    protected function stockOf(ProductVariant $variant): float
    {
        return $variant->stockAt($this->location);
    }

    /**
     * @param  list<CartLine>  $lines
     * @param  list<PaymentInput>|null  $payments
     */
    protected function cart(array $lines, ?array $payments = null, ?User $cashier = null, int $saleDiscount = 0, ?string $uuid = null): CheckoutData
    {
        return new CheckoutData(
            device: $this->till,
            cashier: $cashier ?? $this->cashier,
            lines: $lines,
            payments: $payments ?? [new PaymentInput(PaymentMethod::Cash, 10_000_000)],
            saleDiscount: $saleDiscount,
            uuid: $uuid,
        );
    }
}
