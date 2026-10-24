<?php

namespace App\Exceptions;

use App\Models\ProductVariant;
use Illuminate\Support\Collection;

/**
 * A new item looks like one already in the catalog (same generic, strength
 * and dosage form, or a very similar name). A warning, not a refusal: the
 * user picks the existing item or creates the new one anyway.
 */
class PossibleDuplicateItem extends PosException
{
    /** @param  Collection<int, ProductVariant>  $matches */
    public function __construct(public readonly Collection $matches)
    {
        parent::__construct('This looks like an item already in the catalog: '
            .$matches->map(fn (ProductVariant $variant): string => "{$variant->sku} ({$variant->product->name})")->implode(', ')
            .'. Use the existing item, or create it anyway.');
    }
}
