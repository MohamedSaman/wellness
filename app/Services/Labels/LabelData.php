<?php

namespace App\Services\Labels;

/**
 * What one label says, before the template decides which of it to print
 * and LabelLayout fits it on the label. Built from the item, the unit and
 * the batch as they are when the labels are printed.
 */
final readonly class LabelData
{
    public function __construct(
        public string $name,
        public ?string $barcode,
        public ?string $shopName = null,
        public ?string $strength = null,
        public ?string $pack = null,
        public ?string $sku = null,
        public ?string $partNumber = null,
        public ?string $price = null,
        public ?string $batchNo = null,
        public ?string $expiry = null,
    ) {}
}
