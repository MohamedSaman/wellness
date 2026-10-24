<?php

namespace App\Support;

use App\Models\Shop;
use Illuminate\Support\Facades\Auth;

/**
 * Holds the shop (tenant) the current request, job or command works for.
 *
 * - Web requests: resolved automatically from the logged-in user's shop_id.
 * - Console commands, seeders, queued jobs: call set($shop) explicitly.
 *
 * Every model using BelongsToShop is filtered by this shop and new records
 * are stamped with it, so one shop can never read or write another's data.
 */
class CurrentShop
{
    private ?Shop $shop = null;

    public function set(?Shop $shop): void
    {
        $this->shop = $shop;
    }

    public function clear(): void
    {
        $this->shop = null;
    }

    public function get(): ?Shop
    {
        if ($this->shop === null) {
            $shopId = Auth::user()?->getAttribute('shop_id');

            if ($shopId !== null) {
                $this->shop = Shop::query()->whereKey($shopId)->first();
            }
        }

        return $this->shop;
    }

    public function id(): ?int
    {
        return $this->get()?->getKey();
    }

    public function getOrFail(): Shop
    {
        return $this->get() ?? throw new \LogicException(
            'No current shop. Log in as a shop user, or call app(CurrentShop::class)->set($shop) in commands and jobs.'
        );
    }

    /**
     * Run a callback as a specific shop, then restore the previous one.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAs(Shop $shop, callable $callback): mixed
    {
        $previous = $this->shop;
        $this->shop = $shop;

        try {
            return $callback();
        } finally {
            $this->shop = $previous;
        }
    }
}
