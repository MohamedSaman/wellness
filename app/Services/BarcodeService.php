<?php

namespace App\Services;

use App\Exceptions\PosException;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\VariantUnit;
use App\Support\Barcode;
use Illuminate\Support\Facades\DB;

/**
 * In-store barcodes (docs/PHARMACY.md §9, branch 7b). An item (its base
 * unit) gets 200 + its variant id, a pack 201 + its unit id, each an EAN-13
 * with its check digit (App\Support\Barcode). A barcode is unique across
 * items (deleted ones too) and packs; should the code be taken already (a
 * barcode typed in the 2xx range), the next prefix up to 299 is used.
 *
 * Only an item or pack without a barcode gets one: a manufacturer's barcode
 * is never overwritten. The base unit always scans with the item's own
 * barcode, so it never gets one of its own. Changes go through the models,
 * so they are audited and reach the tills' catalog (updated_at).
 */
class BarcodeService
{
    /** Whether any item (deleted ones too) or pack has this barcode. */
    public function isTaken(string $code): bool
    {
        return ProductVariant::withTrashed()->where('barcode', $code)->exists()
            || VariantUnit::query()->where('barcode', $code)->exists();
    }

    /** A free in-store barcode for an item or a pack. */
    public function inStoreCode(ProductVariant|VariantUnit $for): string
    {
        $first = $for instanceof ProductVariant ? Barcode::ITEM_PREFIX : Barcode::PACK_PREFIX;
        $prefixes = [$first, ...array_values(array_diff(range(202, 299), [$first]))];

        foreach ($prefixes as $prefix) {
            $code = Barcode::inStoreEan13((int) $for->getKey(), $prefix);

            if (! $this->isTaken($code)) {
                return $code;
            }
        }

        throw new PosException('No free in-store barcode is left for this item.');
    }

    /** Give an item without a barcode its in-store one; returns the barcode either way. */
    public function ensureForVariant(ProductVariant $variant): string
    {
        if (filled($variant->barcode)) {
            return (string) $variant->barcode;
        }

        $variant->update(['barcode' => $this->inStoreCode($variant)]);

        return (string) $variant->barcode;
    }

    /**
     * The barcode a unit scans with: the item's for the base unit, the
     * pack's own for a pack, given an in-store one first when it has none.
     */
    public function ensureForUnit(VariantUnit $unit): string
    {
        if ($unit->is_base) {
            return $this->ensureForVariant(ProductVariant::withTrashed()->findOrFail($unit->product_variant_id));
        }

        if (filled($unit->barcode)) {
            return (string) $unit->barcode;
        }

        $unit->update(['barcode' => $this->inStoreCode($unit)]);

        return (string) $unit->barcode;
    }

    /**
     * Give every item and active pack of these variants without a barcode
     * an in-store one (the product list's "Give barcodes" action).
     *
     * @param  iterable<ProductVariant>  $variants
     * @return int how many barcodes were given
     */
    public function assignMissing(iterable $variants, User $user): int
    {
        if (! $user->can('products.manage') && ! $user->can('labels.print')) {
            throw new PosException('You are not allowed to give items barcodes.');
        }

        return DB::transaction(function () use ($variants): int {
            $given = 0;

            foreach ($variants as $variant) {
                if (blank($variant->barcode)) {
                    $this->ensureForVariant($variant);
                    $given++;
                }

                foreach ($variant->units()->where('is_base', false)->where('is_active', true)->whereNull('barcode')->get() as $pack) {
                    $this->ensureForUnit($pack);
                    $given++;
                }
            }

            return $given;
        });
    }
}
