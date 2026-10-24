<?php

namespace App\Models\Concerns;

use App\Models\Shop;
use App\Support\CurrentShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant isolation. Add to every model that has a shop_id column.
 *
 *  - Reads are filtered to the current shop automatically. With no current
 *    shop, queries return nothing (secure by default).
 *  - New records get the current shop's id; shop_id cannot be mass-assigned,
 *    so it can never be changed from request data.
 *  - Creating a record with no current shop throws, so data can't be orphaned.
 *
 * To deliberately query across shops (super-admin tools only):
 *   Model::withoutGlobalScope('shop')->...
 */
trait BelongsToShop
{
    public static function bootBelongsToShop(): void
    {
        static::addGlobalScope('shop', function (Builder $query): void {
            $currentShop = app(CurrentShop::class);
            $shopId = $currentShop->id();

            if ($shopId !== null) {
                $query->where($query->getModel()->qualifyColumn('shop_id'), $shopId);
            } else {
                // No shop = guests, users not linked to a shop, and jobs or
                // commands that forgot to set one: they see nothing.
                $query->whereRaw('1 = 0');
            }
        });

        static::creating(function ($model): void {
            if ($model->getAttribute('shop_id') === null) {
                $model->setAttribute('shop_id', app(CurrentShop::class)->getOrFail()->getKey());
            }
        });

        static::updating(function ($model): void {
            if ($model->isDirty('shop_id')) {
                throw new \LogicException('A record cannot be moved to another shop.');
            }
        });
    }

    public function initializeBelongsToShop(): void
    {
        if (! in_array('shop_id', $this->guarded, true) && $this->guarded !== ['*']) {
            $this->guarded[] = 'shop_id';
        }
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
