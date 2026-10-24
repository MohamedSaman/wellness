<?php

namespace App\Enums;

/**
 * What a label can print (docs/PHARMACY.md §9, branch 7b), top to bottom:
 * the shop's short name, the item's name, its strength (medicines) and the
 * pack ("Box of 30"), its SKU and part number (spare parts), the barcode
 * with its number under it, the price of that unit, and the batch and
 * expiry. A template switches each one on or off.
 */
enum LabelField: string
{
    case ShopName = 'shop_name';
    case Name = 'name';
    case Strength = 'strength';
    case Pack = 'pack';
    case Sku = 'sku';
    case PartNumber = 'part_number';
    case Barcode = 'barcode';
    case BarcodeText = 'barcode_text';
    case Price = 'price';
    case Batch = 'batch';
    case Expiry = 'expiry';

    public function label(): string
    {
        return match ($this) {
            self::ShopName => 'Shop name',
            self::Name => 'Item name',
            self::Strength => 'Strength',
            self::Pack => 'Pack (e.g. "Box of 30")',
            self::Sku => 'SKU',
            self::PartNumber => 'Part number',
            self::Barcode => 'Barcode',
            self::BarcodeText => 'Barcode number under the bars',
            self::Price => 'Price',
            self::Batch => 'Batch number',
            self::Expiry => 'Expiry date',
        };
    }

    /** Only shown and printed in a pharmacy. */
    public function pharmacyOnly(): bool
    {
        return in_array($this, [self::Strength, self::Batch, self::Expiry], true);
    }

    /**
     * What a new template prints. A pharmacy: shop name, name, strength,
     * pack, barcode and price, and the batch (as on receipts); the expiry is
     * off, as the doctor asked for receipts (2026-10-21). A spare-parts shop:
     * name, SKU, part number, price and barcode; vehicle fitment is never
     * printed.
     *
     * @return array<string, bool>
     */
    public static function defaults(bool $pharmacy): array
    {
        $on = $pharmacy
            ? [self::ShopName, self::Name, self::Strength, self::Pack, self::Barcode, self::BarcodeText, self::Price, self::Batch]
            : [self::Name, self::Pack, self::Sku, self::PartNumber, self::Barcode, self::BarcodeText, self::Price];

        return collect(self::cases())->mapWithKeys(fn (self $field): array => [$field->value => in_array($field, $on, true)])->all();
    }
}
