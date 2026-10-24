<?php

namespace App\Filament\Support;

use App\Support\CurrentShop;

/** Money is stored in minor units; screens show it in the shop's currency. */
class ShopMoney
{
    public static function format(?int $minor): ?string
    {
        if ($minor === null) {
            return null;
        }

        return app(CurrentShop::class)->getOrFail()->formatMoney($minor);
    }

    public static function decimals(): int
    {
        return (int) app(CurrentShop::class)->getOrFail()->currency_decimals;
    }

    public static function currency(): string
    {
        return (string) app(CurrentShop::class)->getOrFail()->currency;
    }
}
