<?php

namespace App\Services\Data;

use App\Enums\TaxCategory;

/**
 * An item created from a goods received note or purchase order line
 * because it is not in the catalog yet (ReceivingItemService). A medicine
 * carries its drug details; anything else is a plain item. Prices are
 * optional: an item without one is received but not for sale yet.
 */
final readonly class QuickItemData
{
    /**
     * @param  list<array{name: string, factor: int|float|string, barcode?: string|null, price?: int|null}>  $packs  prices in minor units
     */
    public function __construct(
        public string $name,
        public ?DrugData $drug = null,
        public string $unit = 'pcs',
        public ?string $barcode = null,
        public bool $allowUnitSale = true,
        public array $packs = [],
        // The base unit's selling price; null = no price, not for sale yet.
        public ?int $price = null,
        public ?int $cost = null,
        public ?int $categoryId = null,
        public ?int $brandId = null,
        public ?TaxCategory $taxCategory = null,
    ) {}
}
