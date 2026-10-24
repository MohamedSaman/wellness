<?php

namespace App\Filament\Support;

use App\Support\CurrentShop;
use Illuminate\Validation\Rules\Unique;

class ShopRules
{
    /**
     * Filament's ->unique() queries the table directly (bypassing the tenant
     * scope), so limit it to the current shop.
     */
    public static function limitToCurrentShop(Unique $rule): Unique
    {
        return $rule->where('shop_id', app(CurrentShop::class)->getOrFail()->getKey());
    }
}
