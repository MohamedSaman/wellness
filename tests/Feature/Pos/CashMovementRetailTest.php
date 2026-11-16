<?php

namespace Tests\Feature\Pos;

use App\Models\Shop;
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
}
