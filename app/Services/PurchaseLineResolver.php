<?php

namespace App\Services;

use App\Exceptions\PosException;
use App\Models\ProductVariant;
use App\Models\VariantUnit;
use App\Support\Quantity;
use Illuminate\Support\Collection;

/**
 * The checks every purchasing line shares (orders, goods received, supplier
 * returns): the item exists in this shop and holds stock, the unit is one of
 * its active units (bought in, for orders and receipts), and a quantity is
 * a whole number of that unit for a whole-units product.
 */
class PurchaseLineResolver
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * @param  list<int>  $variantIds
     * @return Collection<int, ProductVariant> keyed by id, with product and units
     */
    public function variants(array $variantIds): Collection
    {
        return ProductVariant::query()
            ->with(['product', 'units'])
            ->whereKey(array_values(array_unique($variantIds)))
            ->get()
            ->keyBy('id');
    }

    /** @param  Collection<int, ProductVariant>  $variants */
    public function variant(Collection $variants, int $variantId): ProductVariant
    {
        $variant = $variants->get($variantId) ?? throw new PosException("Item #{$variantId} was not found.");

        if (! $variant->product->track_stock) {
            throw new PosException("{$variant->sku} is a service; it has no stock to buy or return.");
        }

        return $variant;
    }

    /** The unit a line is in; null = the base unit. Orders and receipts need a unit it is bought in. */
    public function unit(ProductVariant $variant, ?int $unitId, bool $bought = true): VariantUnit
    {
        /** @var VariantUnit|null $unit */
        $unit = $unitId === null
            ? $variant->units->firstWhere('is_base', true)
            : $variant->units->firstWhere('id', $unitId);

        if ($unit === null || ! $unit->is_active) {
            throw new PosException("That unit is not one of {$variant->sku}'s units.");
        }

        if ($bought && ! $unit->is_purchasable) {
            throw new PosException("{$variant->sku} is not bought by the {$unit->name}.");
        }

        return $unit;
    }

    /** A quantity entered in $unit: not negative, and whole for a whole-units product. */
    public function quantity(ProductVariant $variant, VariantUnit $unit, float $quantity, string $what = 'Quantities'): float
    {
        $quantity = Quantity::normalize($quantity);

        if ($quantity < 0) {
            throw new PosException("{$what} can't be below zero.");
        }

        $this->stock->assertWholeUnits($variant, $quantity, $unit->name);

        return $quantity;
    }
}
