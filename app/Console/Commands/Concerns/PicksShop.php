<?php

namespace App\Console\Commands\Concerns;

use App\Models\Shop;

/** --shop=<id or code>, or the only shop of the install when it has one (as pos:module does). */
trait PicksShop
{
    private function pickShop(): ?Shop
    {
        $value = $this->option('shop');

        if ($value === null) {
            return Shop::query()->count() === 1 ? Shop::query()->first() : null;
        }

        return Shop::query()->where('id', $value)->orWhere('code', $value)->first();
    }
}
