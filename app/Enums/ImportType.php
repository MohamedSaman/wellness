<?php

namespace App\Enums;

use App\Models\Shop;

/**
 * What an import file holds (docs/PHARMACY.md §10). Items and opening stock
 * are for every shop; suppliers need the suppliers module and opening
 * invoices the purchasing module.
 */
enum ImportType: string
{
    case Items = 'items';
    case Suppliers = 'suppliers';
    case OpeningStock = 'opening_stock';
    case OpeningInvoices = 'opening_invoices';

    public function label(): string
    {
        return match ($this) {
            self::Items => 'Items',
            self::Suppliers => 'Suppliers',
            self::OpeningStock => 'Opening stock',
            self::OpeningInvoices => 'Supplier opening invoices',
        };
    }

    /** Pharmacies import medicines (and the odd sundry) with the items template. */
    public function labelFor(Shop $shop): string
    {
        return $this === self::Items && $shop->hasModule('pharmacy') ? 'Medicines and products' : $this->label();
    }

    /** The template's file name, without extension. */
    public function templateName(Shop $shop): string
    {
        return match ($this) {
            self::Items => $shop->hasModule('pharmacy') ? 'medicines-template' : 'items-template',
            self::Suppliers => 'suppliers-template',
            self::OpeningStock => 'opening-stock-template',
            self::OpeningInvoices => 'opening-invoices-template',
        };
    }

    /** The module the import writes through, if it needs one. */
    public function module(): ?string
    {
        return match ($this) {
            self::Suppliers => 'suppliers',
            self::OpeningInvoices => 'purchasing',
            default => null,
        };
    }

    public function availableFor(Shop $shop): bool
    {
        $module = $this->module();

        return $module === null || $shop->hasModule($module);
    }

    /** Whether rows can match something already on file, which is then skipped or updated. */
    public function canUpdateExisting(): bool
    {
        return in_array($this, [self::Items, self::Suppliers], true);
    }

    /**
     * The types this shop can import, in the order they are best loaded
     * (suppliers before their invoices, items before their stock).
     *
     * @return array<string, string> value => label
     */
    public static function options(Shop $shop): array
    {
        return collect([self::Suppliers, self::Items, self::OpeningStock, self::OpeningInvoices])
            ->filter(fn (self $type): bool => $type->availableFor($shop))
            ->mapWithKeys(fn (self $type): array => [$type->value => $type->labelFor($shop)])
            ->all();
    }
}
