<?php

namespace App\Services\Import;

use App\Enums\DosageForm;
use App\Enums\DrugSchedule;
use App\Enums\ImportExisting;
use App\Enums\ImportType;
use App\Enums\StorageCondition;
use App\Enums\TaxCategory;
use App\Models\Brand;
use App\Models\Category;
use App\Models\DrugDetail;
use App\Models\Generic;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\StockMovement;
use App\Models\VariantUnit;
use App\Services\Data\DrugData;
use App\Services\ProductCatalogService;
use App\Services\StockService;
use App\Support\Quantity;
use Illuminate\Support\Str;

/**
 * Items (docs/PHARMACY.md §10): in a pharmacy, medicines with their drug
 * details (generic names, strength, dosage form, schedule, storage, NMRA
 * number) and other products; in a retail shop, plain items. Each row is
 * one product with one variant: its base unit, loose price, up to three
 * packs (name, contents, price, barcode, sold, bought), selling loose and
 * the reorder level per location, category and manufacturer (or brand).
 *
 * Matching (re-running a file is safe): a row is the item already on file
 * with its SKU, else with its barcode, else (a row with neither) with
 * exactly its name. A matched item is skipped or updated (from the cells
 * that are filled in; never its base unit once it has stock movements),
 * never created twice. A barcode already on another item or pack is an
 * error, so is a SKU on another item.
 *
 * Defaults (as the medicine form): a blank base unit, "whole units only"
 * and "sell loose" come from the dosage form (config pos.pharmacy.unit_defaults);
 * a medicine is tax exempt and batch-tracked. Packs are only what the file
 * lists (the clinic's real pack sizes), never the suggested ones. An item
 * without a barcode gets an in-store one, as from the product form.
 * Written through ProductCatalogService (selling loose per location too)
 * and StockService::setReorderLevel().
 */
final class ItemsImport implements ImportDefinition
{
    public const PACKS = 3;

    public function __construct(
        private readonly ProductCatalogService $catalog,
        private readonly StockService $stock,
    ) {}

    public function type(): ImportType
    {
        return ImportType::Items;
    }

    public function sheetName(Shop $shop): string
    {
        return $shop->hasModule('pharmacy') ? 'Medicines' : 'Items';
    }

    public function fields(Shop $shop): array
    {
        $pharmacy = $shop->hasModule('pharmacy');
        $money = $shop->currency;
        $yesNo = ['Yes', 'No'];

        $fields = [
            new ImportField('sku', 'SKU', aliases: ['Item code', 'Code', 'Product code', 'Item no'], help: 'Your code for the item. Blank: one is made from the name. An item already on file with this SKU is skipped or updated.', text: true, width: 18),
            new ImportField('name', 'Name', true, ['Item name', 'Product name', 'Product', 'Item', 'Description', 'Trade name', 'Medicine', 'Drug name'], $pharmacy ? 'Trade name and strength, as on the shelf: Panadol 500 mg Tablets.' : 'As on the shelf and the receipt.', width: 34),
        ];

        if ($pharmacy) {
            $fields = [
                ...$fields,
                new ImportField('medicine', 'Medicine', aliases: ['Is medicine', 'Drug'], help: 'Yes for a medicine (needs generic name, dosage form and schedule), No for anything else (cotton wool, a consultation). Blank: Yes.', options: $yesNo, width: 11),
                new ImportField('generic_names', 'Generic name(s)', aliases: ['Generic', 'Generics', 'Generic name', 'INN', 'Active ingredient', 'Composition'], help: 'Required for a medicine. Two or more for a combination, joined with + : Amoxicillin + Clavulanic acid.', width: 28),
                new ImportField('brand_name', 'Brand name', aliases: ['Brand', 'Trade brand'], help: 'The brand on the pack (Panadol). Optional; found at the till.', width: 16),
                new ImportField('manufacturer', 'Manufacturer', aliases: ['Company', 'Marketed by', 'Mfr', 'Made by'], help: 'Optional. Added to Manufacturers if new.', width: 22),
                new ImportField('strength', 'Strength', aliases: ['Strength value', 'Dose'], help: 'The number: 500, 2.5 ("500 mg" in this one cell is read as 500 and mg). Optional; needs a strength unit.', width: 10),
                new ImportField('strength_unit', 'Strength unit', aliases: ['Unit of strength', 'Strength uom'], help: 'mg, g, mcg, IU, ml, %, mg/5 ml ... (others can be typed).', options: array_values((array) config('pos.pharmacy.strength_units', [])), width: 12, strictList: false),
                new ImportField('dosage_form', 'Dosage form', aliases: ['Form', 'Dosage', 'Type'], help: 'Required for a medicine. It suggests the base unit, whole units only and selling loose when those are blank.', options: array_values(DosageForm::options()), width: 14),
                new ImportField('schedule', 'Schedule', aliases: ['Category of sale', 'Legal category', 'Rx', 'Classification'], help: 'Required for a medicine: OTC, Prescription only or Controlled.', options: ['OTC', 'Prescription only', 'Controlled'], width: 16),
            ];
        }

        $fields = [
            ...$fields,
            new ImportField('tax_category', 'Tax', aliases: ['Tax category', 'VAT', 'Taxable'], help: $pharmacy ? 'Exempt or Standard. Blank: Exempt for a medicine, Standard for anything else.' : 'Standard or Exempt. Blank: Standard.', options: ['Exempt', 'Standard'], width: 11),
        ];

        if ($pharmacy) {
            $fields = [
                ...$fields,
                new ImportField('storage_condition', 'Storage', aliases: ['Storage condition', 'Store at', 'Storage temperature'], help: 'Optional: Room, Cool or Fridge. Fridge items are not put back into stock on a return by default.', options: ['Room', 'Cool', 'Fridge'], width: 11),
                new ImportField('registration_no', 'NMRA reg. no.', aliases: ['NMRA', 'NMRA no', 'Registration no', 'Reg no', 'NMRA registration'], help: 'Optional.', text: true, width: 16),
            ];
        } else {
            $fields = [
                ...$fields,
                new ImportField('category', 'Category', aliases: ['Group', 'Department'], help: 'Optional. Added if new.', width: 18),
                new ImportField('manufacturer', 'Brand', aliases: ['Manufacturer', 'Make'], help: 'Optional. Added if new.', width: 16),
                new ImportField('part_number', 'Part number', aliases: ['Part no', 'Part #'], help: 'Optional.', text: true, width: 16),
            ];
        }

        $fields = [
            ...$fields,
            new ImportField('base_unit', $pharmacy ? 'Base unit' : 'Unit', aliases: ['Base unit', 'Unit', 'UOM', 'Stock unit', 'Loose unit'], help: $pharmacy ? 'What stock is counted and sold loose in: Tablet, Capsule, ml, Bottle, Tube, Vial. Blank: from the dosage form. Can\'t change once the item has stock.' : 'What stock is counted in: pcs, litre, metre. Blank: pcs. Can\'t change once the item has stock.', width: 12),
            new ImportField('whole_units_only', 'Whole units only', aliases: ['Whole units', 'No half units'], help: $pharmacy ? 'Yes: no half tablets, no half boxes. Blank: Yes for a medicine.' : 'Yes: no fractions (no 0.5 piece). Blank: No.', options: $yesNo, width: 12),
            new ImportField('barcode', 'Barcode', aliases: ['EAN', 'Barcode no', 'UPC', 'Item barcode'], help: 'The manufacturer\'s barcode of the loose unit. Blank: the item gets an in-store barcode. Must not be on another item or pack.', text: true, width: 16),
            new ImportField('price', $pharmacy ? 'Loose price' : 'Price', aliases: ['Price', 'Selling price', 'Unit price', 'MRP', 'Retail price', 'Loose price', 'Price per unit'], help: "Selling price of one base unit, in {$money}. Blank: not for sale until a price is set."),
        ];

        for ($n = 1; $n <= self::PACKS; $n++) {
            $fields = [
                ...$fields,
                new ImportField("pack{$n}_name", "Pack {$n} name", aliases: $n === 1 ? ['Pack name', 'Pack', 'Pack size name'] : [], help: 'Box of 30, Strip of 10, Bottle. Each pack needs its own name.', width: 14),
                new ImportField("pack{$n}_factor", "Pack {$n} contains", aliases: $n === 1 ? ['Pack size', 'Pack qty', 'Units per pack', 'Contains'] : [], help: 'Base units in one pack: 30 tablets, 100 ml. A whole number for whole-units items.', width: 12),
                new ImportField("pack{$n}_price", "Pack {$n} price", aliases: $n === 1 ? ['Pack price', 'Box price'] : [], help: "Selling price of one pack, in {$money}. Its own price: nothing is worked out from the loose price. Blank: the pack is not for sale until priced.", width: 12),
                new ImportField("pack{$n}_barcode", "Pack {$n} barcode", aliases: $n === 1 ? ['Pack barcode', 'Box barcode'] : [], help: 'Optional. Must not be on another item or pack.', text: true, width: 16),
                new ImportField("pack{$n}_sold", "Pack {$n} sold", help: 'Yes or No: sold in this pack. Blank: Yes.', options: $yesNo, width: 10),
                new ImportField("pack{$n}_bought", "Pack {$n} bought", help: 'Yes or No: bought in this pack. Blank: Yes.', options: $yesNo, width: 10),
            ];
        }

        $locations = $this->locations($shop);

        if ($locations === null) {
            $fields = [
                ...$fields,
                new ImportField('sell_loose', 'Sell loose', aliases: ['Loose sale', 'Allow unit sale'], help: 'Yes or No: sell the base unit on its own as well as the packs. Blank: from the dosage form (Yes for other items). An item with no packs always sells loose.', options: $yesNo, width: 11),
                new ImportField('reorder_level', 'Reorder level', aliases: ['Reorder', 'Minimum stock', 'Min stock', 'ROL'], help: 'In base units. Optional; 0 = not watched.', width: 12),
            ];
        } else {
            foreach ($locations as $location) {
                $fields[] = new ImportField("sell_loose:{$location->code}", "Sell loose at {$location->code}", aliases: ["Loose at {$location->code}"], help: "Yes or No: sell the base unit on its own at {$location->name}. Blank: from the dosage form (Yes for other items). An item with no packs always sells loose.", options: $yesNo, width: 12);
            }

            foreach ($locations as $location) {
                $fields[] = new ImportField("reorder:{$location->code}", "Reorder level at {$location->code}", aliases: ["Reorder {$location->code}", "Min stock {$location->code}"], help: "Reorder level at {$location->name}, in base units. Optional; 0 = not watched there.", width: 12);
            }
        }

        if ($pharmacy) {
            $fields[] = new ImportField('category', 'Category', aliases: ['Group', 'Therapeutic group', 'Department'], help: 'Optional. Added if new.', width: 18);
        } else {
            $fields[] = new ImportField('bin_location', 'Bin / shelf', aliases: ['Bin', 'Shelf', 'Rack', 'Bin location'], help: 'Optional.', text: true, width: 12);
        }

        return $fields;
    }

    public function instructions(Shop $shop): array
    {
        $pharmacy = $shop->hasModule('pharmacy');

        return array_values(array_filter([
            $pharmacy
                ? 'One row per medicine or product, as it is sold: Panadol 500 mg Tablets and Panadol 1 g Tablets are two rows.'
                : 'One row per item. Items with sizes or colours (variants) are entered in the product form.',
            'Only the name is required'.($pharmacy ? '; a medicine also needs its generic name, dosage form and schedule.' : '.'),
            $pharmacy ? 'Stock is counted in the base unit (Tablet, ml, Bottle). Packs are what the item is also sold or bought in, each with its own price and barcode: a syrup is counted in ml with a pack "Bottle" containing 100.' : 'Stock is counted in the unit. Packs (a box, a drum) are optional, each with its own price and barcode.',
            $pharmacy ? 'Blank base unit, whole units only and sell loose take the dosage form\'s usual values. Packs are only the ones you list.' : null,
            $pharmacy ? 'Sell loose at a location: whether the base unit (a tablet, an ml) is sold on its own there. General Pharmacy usually sells syrup by the bottle only; Doctor\'s Medicines may dispense loose ml.' : null,
            'A price left blank means the item (or pack) is not for sale until a price is set: the check warns about it.',
            'An item already on file (same SKU, else same barcode, else same name when the row has neither) is skipped, or updated if you choose "Update" on the upload page. The same file can be imported again: nothing is created twice. Updating changes only the cells that are filled in, and never the base unit of an item that has stock.',
            'Import the stock on hand afterwards with the opening stock template.',
            'The three grey rows are examples: delete them or leave them, they are never imported.',
        ], fn (?string $line): bool => $line !== null));
    }

    public function examples(Shop $shop): array
    {
        $codes = array_map(fn (Location $location): string => $location->code, $this->locations($shop) ?? []);
        $perLocation = fn (string $prefix, array $values): array => array_combine(
            array_map(fn (string $code): string => "{$prefix}:{$code}", $codes),
            array_map(fn (int $i): mixed => $values[$i] ?? $values[0], array_keys($codes)),
        ) ?: [];

        if (! $shop->hasModule('pharmacy')) {
            return [
                ['sku' => 'EX-HELMET-M', 'name' => 'Example Full Face Helmet M', 'category' => 'Helmets', 'manufacturer' => 'Example Brand', 'part_number' => null, 'tax_category' => 'Standard', 'base_unit' => 'pcs', 'whole_units_only' => 'Yes', 'barcode' => '8901234500017', 'price' => 18500.00, 'reorder_level' => 2, 'bin_location' => 'A1', ...$perLocation('reorder', [2])],
                ['sku' => 'EX-OIL-20W50', 'name' => 'Example Engine Oil 20W-50', 'category' => 'Oils', 'manufacturer' => 'Example Oils', 'part_number' => 'EO-2050', 'tax_category' => 'Standard', 'base_unit' => 'litre', 'whole_units_only' => 'No', 'barcode' => null, 'price' => 2400.00, 'pack1_name' => 'Can of 4', 'pack1_factor' => 4, 'pack1_price' => 9000.00, 'pack1_barcode' => '8901234500024', 'pack1_sold' => 'Yes', 'pack1_bought' => 'Yes', 'sell_loose' => 'Yes', 'reorder_level' => 10, 'bin_location' => 'C3', ...$perLocation('reorder', [10]), ...$perLocation('sell_loose', ['Yes'])],
                ['sku' => null, 'name' => 'Example Brake Pad Set', 'category' => 'Spare parts', 'manufacturer' => null, 'part_number' => 'BP-110', 'tax_category' => null, 'base_unit' => null, 'whole_units_only' => null, 'barcode' => null, 'price' => null, 'reorder_level' => null, 'bin_location' => null],
            ];
        }

        return [
            ['sku' => 'EX-PCM-500', 'name' => 'Example Paracetamol 500 mg Tablets', 'medicine' => 'Yes', 'generic_names' => 'Paracetamol', 'brand_name' => 'Examplol', 'manufacturer' => 'Example Pharma', 'strength' => 500, 'strength_unit' => 'mg', 'dosage_form' => 'Tablet', 'schedule' => 'OTC', 'tax_category' => 'Exempt', 'storage_condition' => 'Room', 'registration_no' => 'EX/0001', 'base_unit' => 'Tablet', 'whole_units_only' => 'Yes', 'barcode' => '4790000000017', 'price' => 5.00, 'pack1_name' => 'Box of 100', 'pack1_factor' => 100, 'pack1_price' => 450.00, 'pack1_barcode' => '4790000000024', 'pack1_sold' => 'Yes', 'pack1_bought' => 'Yes', 'category' => 'Pain relief', ...$perLocation('sell_loose', ['Yes']), ...$perLocation('reorder', [300, 100]), 'sell_loose' => 'Yes', 'reorder_level' => 300],
            ['sku' => 'EX-AMC-625', 'name' => 'Example Co-amoxiclav 625 mg Tablets', 'medicine' => 'Yes', 'generic_names' => 'Amoxicillin + Clavulanic acid', 'brand_name' => null, 'manufacturer' => 'Example Pharma', 'strength' => 625, 'strength_unit' => 'mg', 'dosage_form' => 'Tablet', 'schedule' => 'Prescription only', 'tax_category' => null, 'storage_condition' => null, 'registration_no' => null, 'base_unit' => null, 'whole_units_only' => null, 'barcode' => null, 'price' => 95.00, 'pack1_name' => 'Box of 16', 'pack1_factor' => 16, 'pack1_price' => 1450.00, 'category' => 'Antibiotics', ...$perLocation('reorder', [32])],
            ['sku' => 'EX-CPM-SYR', 'name' => 'Example Chlorphenamine 2 mg/5 ml Syrup 100 ml', 'medicine' => 'Yes', 'generic_names' => 'Chlorphenamine', 'brand_name' => null, 'manufacturer' => null, 'strength' => 2, 'strength_unit' => 'mg/5 ml', 'dosage_form' => 'Syrup', 'schedule' => 'OTC', 'tax_category' => 'Exempt', 'storage_condition' => 'Room', 'registration_no' => null, 'base_unit' => 'ml', 'whole_units_only' => 'Yes', 'barcode' => null, 'price' => 4.00, 'pack1_name' => 'Bottle', 'pack1_factor' => 100, 'pack1_price' => 380.00, 'pack1_sold' => 'Yes', 'pack1_bought' => 'Yes', 'category' => 'Cough and cold', ...$perLocation('sell_loose', ['No', 'Yes']), 'sell_loose' => 'No'],
        ];
    }

    public function check(array $rows, ImportContext $context): void
    {
        $shop = $context->shop;
        $pharmacy = $shop->hasModule('pharmacy');
        $locations = $this->locations($shop);
        $index = $this->catalogIndex();

        $seenSku = [];
        $seenBarcode = [];
        $seenName = [];
        $seenDrug = [];

        foreach ($rows as $row) {
            $item = $this->parse($row, $context, $pharmacy, $locations);

            if ($item === null) {
                continue;
            }

            // Duplicates within the file.
            if ($item['sku'] !== null) {
                $key = Str::lower($item['sku']);

                if (isset($seenSku[$key])) {
                    $row->error("SKU {$item['sku']} is also on row {$seenSku[$key]}.");
                }

                $seenSku[$key] ??= $row->number;
            }

            foreach (array_unique(array_diff_assoc($item['barcodes'], array_unique($item['barcodes']))) as $barcode) {
                $row->error("Duplicate barcode {$barcode}: used twice on this row.");
            }

            foreach ($item['barcodes'] as $barcode) {
                if (isset($seenBarcode[$barcode]) && $seenBarcode[$barcode] !== $row->number) {
                    $row->error("Duplicate barcode {$barcode}: also on row {$seenBarcode[$barcode]}.");
                }

                $seenBarcode[$barcode] ??= $row->number;
            }

            $nameKey = self::normalName($item['name']);

            if (isset($seenName[$nameKey])) {
                [$otherRow, $otherHasCode] = $seenName[$nameKey];
                $hasCode = $item['sku'] !== null || $item['barcode'] !== null;

                if (! $hasCode && ! $otherHasCode) {
                    $row->error("Same name as row {$otherRow}, and neither has a SKU or barcode to tell them apart: list each item once.");
                } else {
                    $row->warn("Same name as row {$otherRow}: check it is not the same item.");
                }
            } else {
                $seenName[$nameKey] = [$row->number, $item['sku'] !== null || $item['barcode'] !== null];
            }

            $this->match($row, $item, $index, $context);

            if ($row->action === ImportRow::CREATE) {
                foreach ($item['missing'] as $message) {
                    $row->error($message);
                }
            }

            if ($row->action === ImportRow::CREATE && $item['drug'] !== null && $item['missing'] === []) {
                $drugKey = self::drugKey($item['drug']);

                if ($drugKey !== null) {
                    $same = $index['drugs'][$drugKey] ?? null;

                    if ($same !== null) {
                        $row->warn("Possible duplicate of {$same} (same generic, strength and dosage form): check it is not the same medicine.");
                    } elseif (isset($seenDrug[$drugKey])) {
                        $row->warn("Possible duplicate of row {$seenDrug[$drugKey]} (same generic, strength and dosage form).");
                    }

                    $seenDrug[$drugKey] ??= $row->number;
                }
            }

            if ($row->action === ImportRow::CREATE && isset($index['names'][$nameKey]) && ($item['sku'] !== null || $item['barcode'] !== null)) {
                $row->warn("An item called {$item['name']} is already on file ({$index['names'][$nameKey]['sku']}): check this is not the same item.");
            }

            if (in_array($row->action, [ImportRow::CREATE, ImportRow::UPDATE], true)) {
                $this->warnUnpriced($row, $item);
            }

            if ($row->hasErrors()) {
                $row->action = null;
            }
        }
    }

    public function commit(array $rows, ImportContext $context, \Closure $progress): void
    {
        $locations = collect($this->locations($context->shop) ?? [])->keyBy('code');
        $categories = [];
        $brands = [];

        foreach ($rows as $done => $row) {
            if (in_array($row->action, [ImportRow::CREATE, ImportRow::UPDATE], true)) {
                $row->write(function () use ($row, $context, $locations, &$categories, &$brands): void {
                    /** @var array<string, mixed> $item */
                    $item = $row->data['item'];
                    $categoryId = $item['category'] === null ? null : ($categories[Str::lower($item['category'])] ??= $this->category($item['category']));
                    $brandId = $item['manufacturer'] === null ? null : ($brands[Str::lower($item['manufacturer'])] ??= $this->brand($item['manufacturer']));

                    $loose = [];

                    foreach ($item['sell_loose_at'] as $code => $sellable) {
                        $loose[(int) $locations[$code]->getKey()] = $sellable;
                    }

                    $variant = $row->action === ImportRow::CREATE
                        ? $this->create($item, $loose, $categoryId, $brandId, $context)
                        : $this->update($row, $item, $loose, $categoryId, $brandId, $context);

                    foreach ($item['reorder_at'] as $code => $level) {
                        $this->stock->setReorderLevel($variant, $locations[$code], (float) $level);
                    }
                });
            }

            $progress($done + 1);
        }
    }

    /**
     * The row's values, checked, with the defaults applied for a new item.
     * Null when the row can't even be read as an item (no name).
     *
     * @param  list<Location>|null  $locations
     * @return array<string, mixed>|null
     */
    private function parse(ImportRow $row, ImportContext $context, bool $pharmacy, ?array $locations): ?array
    {
        $decimals = $context->currencyDecimals();
        $name = $row->text('name', 255, required: true);
        $medicineCell = $pharmacy ? $row->yesNo('medicine') : null;
        $medicine = $pharmacy && ($medicineCell ?? true);
        $drug = null;
        $form = null;

        // Required for a new medicine only: an update keeps what is on file for a blank cell.
        $missing = [];

        if ($medicine) {
            $generics = array_values(array_filter(array_map(
                fn (string $part): string => Str::squish($part),
                preg_split('/[+;]/', (string) $row->text('generic_names', 500)) ?: [],
            )));
            $form = $row->choice('dosage_form', self::dosageForms(), implode(', ', DosageForm::options()));
            $schedule = $row->choice('schedule', self::schedules(), 'OTC, Prescription only or Controlled');
            [$strength, $strengthUnit] = $this->strength($row);

            foreach (['generic_names' => $generics === [], 'dosage_form' => $form === null, 'schedule' => $schedule === null] as $key => $blank) {
                if ($blank && ! $row->filled($key)) {
                    $missing[] = "{$row->label($key)} is missing.";
                }
            }

            if ($strength !== null && $strengthUnit === null) {
                $row->error('Strength unit is missing: say what the strength is measured in (mg, ml, mg/5 ml ...).');
            }

            if ($strengthUnit !== null && ! $row->filled('strength')) {
                $row->error('Strength unit without a strength: enter the number in Strength.');
            }

            $storage = $row->choice('storage_condition', self::storageConditions(), 'Room, Cool or Fridge');

            $drug = [
                'generic_names' => $generics === [] ? null : $generics,
                'dosage_form' => $form,
                'schedule' => $schedule,
                'brand_name' => $row->text('brand_name', 150),
                'strength' => $strength,
                'strength_unit' => $strength === null ? null : $strengthUnit,
                'storage_condition' => $storage,
                'registration_no' => $row->text('registration_no', 50),
            ];
        }

        $defaults = $form === null ? null : DosageForm::from($form)->unitDefaults();
        $tax = $row->choice('tax_category', self::taxCategories(), 'Exempt or Standard');
        $wholeCell = $row->yesNo('whole_units_only');
        $baseCell = $row->text('base_unit', 30);
        $base = $baseCell ?? ($defaults['base_unit'] ?? ($pharmacy ? 'Piece' : 'pcs'));
        $whole = $wholeCell ?? ($defaults['whole_units_only'] ?? $medicine);

        $packs = [];
        $packNames = [Str::lower($base) => $base];

        for ($n = 1; $n <= self::PACKS; $n++) {
            $keys = ["pack{$n}_name", "pack{$n}_factor", "pack{$n}_price", "pack{$n}_barcode", "pack{$n}_sold", "pack{$n}_bought"];

            if (! collect($keys)->contains(fn (string $key): bool => $row->filled($key))) {
                continue;
            }

            $packName = $row->text("pack{$n}_name", 30);
            $factor = $row->decimal("pack{$n}_factor", positive: true);

            if ($packName === null) {
                $row->error("Pack {$n} name is missing.");
            }

            if ($factor === null && ! $row->filled("pack{$n}_factor")) {
                $missing[] = "Pack {$n} contains is missing: how many {$base} are in one ".($packName ?? 'pack').'?';
            }

            if ($factor !== null && $whole && ! Quantity::isWhole((float) $factor)) {
                $row->error("Pack {$n} contains {$factor} {$base}: it must be a whole number (whole units only).");
            }

            if ($packName !== null) {
                if (isset($packNames[Str::lower($packName)])) {
                    $row->error("Two units are called {$packName}: each pack needs its own name, different from the base unit.");
                }

                $packNames[Str::lower($packName)] = $packName;
            }

            $packs[] = [
                'name' => $packName,
                'factor' => $factor,
                'price' => $row->money("pack{$n}_price", $decimals),
                'barcode' => $row->code("pack{$n}_barcode", 100),
                'is_sellable' => $row->yesNo("pack{$n}_sold") ?? true,
                'is_purchasable' => $row->yesNo("pack{$n}_bought") ?? true,
            ];
        }

        $looseDefault = $defaults['allow_unit_sale'] ?? true;
        $sellLooseAt = [];
        $reorderAt = [];

        foreach ($locations ?? [] as $location) {
            $sellable = $row->yesNo("sell_loose:{$location->code}");

            if ($sellable !== null) {
                $sellLooseAt[$location->code] = $sellable;
            }

            $level = $row->decimal("reorder:{$location->code}");

            if ($level !== null) {
                $reorderAt[$location->code] = $level;
            }
        }

        $barcode = $row->code('barcode', 100);
        $sku = $row->code('sku', 100);

        if ($name === null) {
            return null;
        }

        $item = [
            'missing' => $missing,
            'sku' => $sku,
            'name' => $name,
            'medicine' => $medicine,
            'medicine_cell' => $medicineCell,
            'drug' => $drug,
            'tax' => $tax,
            'base_unit' => $base,
            'base_unit_cell' => $baseCell,
            'whole_units_only' => $whole,
            'whole_units_cell' => $wholeCell,
            'barcode' => $barcode,
            'price' => $row->money('price', $decimals),
            'packs' => $packs,
            'sell_loose' => $locations === null ? $row->yesNo('sell_loose') : null,
            'sell_loose_default' => $looseDefault,
            'sell_loose_at' => $sellLooseAt,
            'reorder_level' => $locations === null ? $row->decimal('reorder_level') : null,
            'reorder_at' => $reorderAt,
            'category' => $row->text('category', 255),
            'manufacturer' => $row->text('manufacturer', 255),
            'part_number' => $pharmacy ? null : $row->text('part_number', 100),
            'bin_location' => $pharmacy ? null : $row->text('bin_location', 50),
        ];

        $item['barcodes'] = array_values(array_filter([$barcode, ...array_column($packs, 'barcode')], fn (?string $code): bool => $code !== null));
        $row->data['item'] = $item;

        return $item;
    }

    /**
     * Finds the item already on file, decides create, update or skip, and
     * checks the barcodes and SKU against the rest of the catalog.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $index
     */
    private function match(ImportRow $row, array $item, array $index, ImportContext $context): void
    {
        /** @var array<string, array{id: int, product_id: int, sku: string, deleted: bool}> $bySku */
        $bySku = $index['sku'];
        /** @var array<string, array{id: int, product_id: int, sku: string, deleted: bool}> $byBarcode */
        $byBarcode = $index['barcode'];
        /** @var array<string, array{variant_id: int, unit_id: int, sku: string}> $packBarcodes */
        $packBarcodes = $index['packs'];

        $found = null;
        $how = null;

        if ($item['sku'] !== null && isset($bySku[Str::lower($item['sku'])])) {
            $found = $bySku[Str::lower($item['sku'])];
            $how = "SKU {$found['sku']}";
        }

        if ($item['barcode'] !== null && isset($byBarcode[$item['barcode']])) {
            $byCode = $byBarcode[$item['barcode']];

            if ($found !== null && $found['id'] !== $byCode['id']) {
                $row->error("SKU {$item['sku']} is item {$found['sku']} but barcode {$item['barcode']} is on {$byCode['sku']}: they can't both be this row.");

                return;
            }

            $found ??= $byCode;
            $how ??= "barcode {$item['barcode']}";
        }

        if ($found === null && $item['sku'] === null && $item['barcode'] === null) {
            $named = $index['names'][self::normalName($item['name'])] ?? null;

            if ($named !== null && $named['count'] > 1) {
                $row->error("{$named['count']} items on file are called {$item['name']}: add the SKU to say which one.");

                return;
            }

            if ($named !== null) {
                $found = $byBarcode[$named['barcode']] ?? $bySku[Str::lower($named['sku'])] ?? null;
                $how = 'name';
            }
        }

        if ($found !== null && $found['deleted']) {
            $row->error("{$how} belongs to a deleted item ({$found['sku']}).");

            return;
        }

        // Barcodes must be free, or already this item's own.
        foreach ($item['barcodes'] as $barcode) {
            $holder = $byBarcode[$barcode] ?? null;
            $pack = $packBarcodes[$barcode] ?? null;

            if ($holder !== null && ($found === null || $holder['id'] !== $found['id'] || $barcode !== $item['barcode'])) {
                $row->error("Duplicate barcode {$barcode}: it is already on {$holder['sku']}.");
            } elseif ($pack !== null && ($found === null || $pack['variant_id'] !== $found['id'] || $barcode === $item['barcode'])) {
                $row->error("Duplicate barcode {$barcode}: it is already on a pack of {$pack['sku']}.");
            }
        }

        if ($found === null) {
            if ($item['sku'] !== null && Str::length($item['sku']) > 100) {
                $row->error('SKU is too long.');
            }

            $row->action = ImportRow::CREATE;

            return;
        }

        $row->data['variant_id'] = $found['id'];

        if ($context->existing === ImportExisting::Skip) {
            $row->skip("Already on file ({$found['sku']}, matched by {$how}): skipped.");

            return;
        }

        $row->action = ImportRow::UPDATE;
        $row->warn("Already on file ({$found['sku']}, matched by {$how}): it will be updated from this row.");

        $this->checkUpdate($row, $item, $found, $bySku);
    }

    /**
     * What an update may not do: change the base unit of an item with stock
     * movements, or take a SKU that belongs to another item.
     *
     * @param  array<string, mixed>  $item
     * @param  array{id: int, product_id: int, sku: string, deleted: bool}  $found
     * @param  array<string, array{id: int, product_id: int, sku: string, deleted: bool}>  $bySku
     */
    private function checkUpdate(ImportRow $row, array $item, array $found, array $bySku): void
    {
        /** @var ProductVariant $variant */
        $variant = ProductVariant::query()->with(['product.drugDetail', 'units'])->findOrFail($found['id']);
        $product = $variant->product;

        if ($item['sku'] !== null && Str::lower($item['sku']) !== Str::lower($variant->sku)) {
            $other = $bySku[Str::lower($item['sku'])] ?? null;

            if ($other !== null && $other['id'] !== $found['id']) {
                $row->error("SKU {$item['sku']} is already on {$other['sku']}.");
            }
        }

        if ($item['base_unit_cell'] !== null && Str::lower($item['base_unit_cell']) !== Str::lower((string) $product->unit)) {
            $moved = StockMovement::query()->whereIn('product_variant_id', $product->variants()->withTrashed()->select('id'))->exists();

            if ($moved) {
                $row->error("The base unit can't change from {$product->unit} to {$item['base_unit_cell']}: {$variant->sku} already has stock movements, counted in {$product->unit}.");
            }
        }

        if ($item['medicine_cell'] === true && $product->drugDetail === null) {
            foreach (array_filter($item['missing'], fn (string $message): bool => ! str_starts_with($message, 'Pack ')) as $message) {
                $row->error($message.' (to make it a medicine)');
            }
        }

        if ($item['medicine_cell'] === false && $product->drugDetail !== null) {
            $row->warn('Medicine = No is ignored: the item is a medicine on file (change it in the product form).');
        }

        if ($product->variants()->count() > 1) {
            $row->warn("{$product->name} has several variants: only this variant's barcode, prices, packs and reorder levels are updated.");
            $row->data['variant_only'] = true;
        }

        $whole = $item['whole_units_cell'] ?? $product->whole_units_only;
        $existingPacks = $variant->units->where('is_base', false)->keyBy(fn (VariantUnit $unit): string => Str::lower($unit->name));

        foreach ($item['packs'] as $n => $pack) {
            if ($pack['factor'] === null && $pack['name'] !== null && ! $existingPacks->has(Str::lower($pack['name']))) {
                $row->error('Pack '.($n + 1).' contains is missing.');
            }

            if ($pack['factor'] !== null && $whole && ! Quantity::isWhole((float) $pack['factor'])) {
                $row->error('Pack '.($n + 1).' must contain a whole number of '.$product->unit.'.');
            }
        }
    }

    /**
     * The strength and its unit. A strength typed with its unit in one cell
     * ("500mg", "2 mg/5 ml"), as many shops' lists have it, is split when the
     * strength unit is blank.
     *
     * @return array{0: numeric-string|null, 1: string|null}
     */
    private function strength(ImportRow $row): array
    {
        $unit = $row->text('strength_unit', 20);
        $typed = ImportRow::stringOf($row->raw('strength'));

        if ($unit === null && preg_match('/^(\d+(?:\.\d+)?)\s*([^\d\s].{0,19})$/u', $typed, $m) === 1 && is_numeric($m[1]) && (float) $m[1] > 0) {
            return [$m[1], trim($m[2])];
        }

        return [$row->decimal('strength', positive: true), $unit];
    }

    /** @param  array<string, mixed>  $item */
    private function warnUnpriced(ImportRow $row, array $item): void
    {
        $updating = $row->action === ImportRow::UPDATE;
        /** @var list<array<string, mixed>> $packs */
        $packs = $item['packs'];
        $sellsPack = in_array(true, array_column($packs, 'is_sellable'), true);
        $looseAnywhere = ! $sellsPack || ($item['sell_loose'] ?? $item['sell_loose_default']) || in_array(true, $item['sell_loose_at'], true);

        if ($item['price'] === null && $looseAnywhere && ! $updating) {
            $row->warn("No loose price: one {$item['base_unit']} is not for sale until it has a price.");
        }

        foreach ($packs as $pack) {
            if ($pack['price'] === null && $pack['is_sellable'] && $pack['name'] !== null && ! $updating) {
                $row->warn("No price for {$pack['name']}: it is not for sale until it has a price.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<int, bool>  $loose  location id => sell loose there
     */
    private function create(array $item, array $loose, ?int $categoryId, ?int $brandId, ImportContext $context): ProductVariant
    {
        $product = array_filter([
            'name' => $item['name'],
            'unit' => $item['base_unit'],
            'whole_units_only' => $item['whole_units_only'],
            'category_id' => $categoryId,
            'brand_id' => $brandId,
            'part_number' => $item['part_number'],
            'tax_category' => $item['tax'] === null
                ? ($item['medicine'] ? TaxCategory::Exempt : TaxCategory::Standard)
                : TaxCategory::from($item['tax']),
        ], fn (mixed $value): bool => $value !== null);

        $variant = [
            'sku' => $item['sku'],
            'barcode' => $item['barcode'],
            'price' => $item['price'],
            'reorder_level' => $item['reorder_level'] === null ? 0 : (float) $item['reorder_level'],
            'bin_location' => $item['bin_location'],
            'allow_unit_sale' => $item['sell_loose'] ?? $item['sell_loose_default'],
            'loose_at' => $loose,
            'packs' => array_values(array_map(fn (array $pack): array => array_filter([
                'name' => $pack['name'],
                'factor' => (float) $pack['factor'],
                'barcode' => $pack['barcode'],
                'price' => $pack['price'],
                'is_sellable' => $pack['is_sellable'],
                'is_purchasable' => $pack['is_purchasable'],
            ], fn (mixed $value): bool => $value !== null), $item['packs'])),
        ];

        if ($item['sku'] === null) {
            unset($variant['sku']);
        }

        $created = $this->catalog->create($product, [$variant], [], $context->user, [], $this->drugData($item['drug']));

        return $created->variants->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<int, bool>  $loose  location id => sell loose there
     */
    private function update(ImportRow $row, array $item, array $loose, ?int $categoryId, ?int $brandId, ImportContext $context): ProductVariant
    {
        /** @var ProductVariant $variant */
        $variant = ProductVariant::query()->with(['product.drugDetail', 'product.generics', 'product.attributeValues', 'units'])->findOrFail($row->data['variant_id']);
        $product = $variant->product;
        $variantOnly = (bool) ($row->data['variant_only'] ?? false);

        $attributes = $variantOnly ? [] : array_filter([
            'name' => $item['name'],
            'unit' => $item['base_unit_cell'],
            'whole_units_only' => $item['whole_units_cell'],
            'category_id' => $categoryId,
            'brand_id' => $brandId,
            'part_number' => $item['part_number'],
            'tax_category' => $item['tax'] === null ? null : TaxCategory::from($item['tax']),
        ], fn (mixed $value): bool => $value !== null);

        $existingPacks = $variant->units->where('is_base', false)->keyBy(fn (VariantUnit $unit): string => Str::lower($unit->name));

        $data = array_filter([
            'id' => $variant->getKey(),
            'sku' => $item['sku'],
            'barcode' => $item['barcode'],
            'price' => $item['price'],
            'reorder_level' => $item['reorder_level'] === null ? (float) $variant->reorder_level : (float) $item['reorder_level'],
            'bin_location' => $item['bin_location'] ?? $variant->bin_location,
            'is_active' => $variant->is_active,
            'allow_unit_sale' => $item['sell_loose'],
        ], fn (mixed $value): bool => $value !== null);

        if ($loose !== []) {
            $data['loose_at'] = $loose;
        }

        if ($item['packs'] !== []) {
            $data['packs'] = array_map(function (array $pack) use ($existingPacks): array {
                /** @var VariantUnit|null $existing */
                $existing = $existingPacks->get(Str::lower((string) $pack['name']));

                return array_filter([
                    'id' => $existing?->getKey(),
                    'name' => $pack['name'],
                    'factor' => $pack['factor'] === null ? (float) $existing?->factor : (float) $pack['factor'],
                    'barcode' => $pack['barcode'] ?? $existing?->barcode,
                    'price' => $pack['price'],
                    'is_sellable' => $pack['is_sellable'],
                    'is_purchasable' => $pack['is_purchasable'],
                    'is_active' => true,
                ], fn (mixed $value): bool => $value !== null);
            }, $item['packs']);
        }

        // Product-level attribute values (Helmet type ...) are kept as they are.
        $productAttributes = $product->attributeValues->mapWithKeys(fn (ProductAttributeValue $value): array => [
            (int) $value->product_attribute_id => $value->attribute_option_id !== null ? (int) $value->attribute_option_id : $value->value,
        ])->all();

        $this->catalog->update($product, $attributes, [$data], $productAttributes, null, $context->user, $variantOnly ? null : $this->mergedDrug($product, $item));

        return $variant->refresh();
    }

    /**
     * An update's drug details: what is on file, with the filled-in cells
     * over it. Null leaves them alone (not a medicine, and the row doesn't
     * make it one).
     *
     * @param  array<string, mixed>  $item
     */
    private function mergedDrug(Product $product, array $item): ?DrugData
    {
        $detail = $product->drugDetail;

        if ($detail === null) {
            return $item['medicine_cell'] === true ? $this->drugData($item['drug']) : null;
        }

        /** @var array<string, mixed>|null $drug */
        $drug = $item['drug'];
        $filled = fn (string $key): mixed => $drug[$key] ?? null;

        return new DrugData(
            genericNames: $filled('generic_names') ?? $product->generics->map(fn (Generic $generic): string => $generic->name)->values()->all(),
            dosageForm: $filled('dosage_form') !== null ? DosageForm::from($filled('dosage_form')) : $detail->dosage_form,
            schedule: $filled('schedule') !== null ? DrugSchedule::from($filled('schedule')) : $detail->schedule,
            brandName: $filled('brand_name') ?? $detail->brand_name,
            strengthValue: $filled('strength') !== null ? (float) $filled('strength') : ($detail->strength_value === null ? null : (float) $detail->strength_value),
            strengthUnit: $filled('strength') !== null ? $filled('strength_unit') : $detail->strength_unit,
            storageNote: $detail->storage_note,
            storageCondition: $filled('storage_condition') !== null ? StorageCondition::from($filled('storage_condition')) : $detail->storage_condition,
            registrationNo: $filled('registration_no') ?? $detail->registration_no,
            route: $detail->route,
            minDaysToExpiry: $detail->min_days_to_expiry,
        );
    }

    /** @param  array<string, mixed>|null  $drug */
    private function drugData(?array $drug): ?DrugData
    {
        if ($drug === null || $drug['generic_names'] === null || $drug['dosage_form'] === null || $drug['schedule'] === null) {
            return null;
        }

        return new DrugData(
            genericNames: $drug['generic_names'],
            dosageForm: DosageForm::from($drug['dosage_form']),
            schedule: DrugSchedule::from($drug['schedule']),
            brandName: $drug['brand_name'],
            strengthValue: $drug['strength'] === null ? null : (float) $drug['strength'],
            strengthUnit: $drug['strength_unit'],
            storageCondition: $drug['storage_condition'] === null ? null : StorageCondition::from($drug['storage_condition']),
            registrationNo: $drug['registration_no'],
        );
    }

    /**
     * What is on file, loaded once for the whole file: SKUs and barcodes of
     * every variant (deleted ones too: their codes stay taken), pack
     * barcodes, names, and medicines by generic, strength and form.
     *
     * @return array<string, mixed>
     */
    private function catalogIndex(): array
    {
        $sku = [];
        $barcode = [];

        foreach (ProductVariant::withTrashed()->toBase()->get(['id', 'product_id', 'sku', 'barcode', 'deleted_at']) as $variant) {
            $entry = ['id' => (int) $variant->id, 'product_id' => (int) $variant->product_id, 'sku' => (string) $variant->sku, 'deleted' => $variant->deleted_at !== null];
            $sku[Str::lower((string) $variant->sku)] = $entry;

            if ($variant->barcode !== null) {
                $barcode[(string) $variant->barcode] = $entry;
            }
        }

        $packs = [];

        foreach (VariantUnit::query()->whereNotNull('barcode')->toBase()->get(['id', 'product_variant_id', 'barcode']) as $unit) {
            $packs[(string) $unit->barcode] = ['variant_id' => (int) $unit->product_variant_id, 'unit_id' => (int) $unit->id, 'sku' => ''];
        }

        $skuOf = collect($sku)->mapWithKeys(fn (array $entry): array => [$entry['id'] => $entry['sku']]);

        foreach ($packs as $code => $pack) {
            $packs[$code]['sku'] = (string) $skuOf->get($pack['variant_id'], '?');
        }

        $names = [];

        foreach (ProductVariant::query()->toBase()->join('products', 'products.id', '=', 'product_variants.product_id')->whereNull('products.deleted_at')->get(['products.id as product_id', 'products.name', 'product_variants.sku', 'product_variants.barcode']) as $variant) {
            $key = self::normalName((string) $variant->name);
            $names[$key] = [
                'count' => ($names[$key]['count'] ?? 0) + 1,
                'sku' => $names[$key]['sku'] ?? (string) $variant->sku,
                'barcode' => $names[$key]['barcode'] ?? (string) $variant->barcode,
            ];
        }

        $drugs = [];

        foreach (DrugDetail::query()->with(['product' => fn ($query) => $query->with(['generics', 'variants'])])->get() as $detail) {
            if ($detail->product === null) {
                continue;
            }

            $key = self::drugKey([
                'generic_names' => $detail->product->generics->map(fn (Generic $generic): string => $generic->name)->all(),
                'strength' => $detail->strength_value,
                'strength_unit' => $detail->strength_unit,
                'dosage_form' => $detail->dosage_form->value,
            ]);

            if ($key !== null) {
                $drugs[$key] ??= trim(($detail->product->variants->first()->sku ?? '').' '.$detail->product->name);
            }
        }

        return ['sku' => $sku, 'barcode' => $barcode, 'packs' => $packs, 'names' => $names, 'drugs' => $drugs];
    }

    /** @param  array<string, mixed>  $drug  "amoxicillin+clavulanic acid|625|mg|tablet", or null without a strength */
    private static function drugKey(array $drug): ?string
    {
        if (($drug['strength'] ?? null) === null) {
            return null;
        }

        /** @var list<string> $generics */
        $generics = $drug['generic_names'];
        $names = array_map(fn (string $name): string => self::normalName($name), $generics);
        sort($names);

        return implode('+', $names).'|'.Quantity::normalize((string) $drug['strength']).'|'.Str::lower(Str::squish((string) $drug['strength_unit'])).'|'.$drug['dosage_form'];
    }

    private static function normalName(string $name): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower($name))));
    }

    /** @return list<Location>|null the shop's active locations, or null with one location (no per-location columns) */
    private function locations(Shop $shop): ?array
    {
        if (! $shop->hasModule('multi_location')) {
            return null;
        }

        return array_values(Location::query()->where('is_active', true)->ordered()->get()->all());
    }

    private function category(string $name): int
    {
        $existing = Category::query()->whereRaw('LOWER(name) = ?', [Str::lower($name)])->first();

        return (int) ($existing ?? Category::query()->create(['name' => $name, 'slug' => $this->slug(Category::class, $name)]))->getKey();
    }

    private function brand(string $name): int
    {
        $existing = Brand::query()->whereRaw('LOWER(name) = ?', [Str::lower($name)])->first();

        return (int) ($existing ?? Brand::query()->create(['name' => $name, 'slug' => $this->slug(Brand::class, $name)]))->getKey();
    }

    /** @param  class-string<Category|Brand>  $model */
    private function slug(string $model, string $name): string
    {
        $base = Str::slug($name) ?: 'item';
        $slug = $base;

        for ($n = 2; $model::query()->where('slug', $slug)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }

    /** @return array<string, string> */
    private static function dosageForms(): array
    {
        $choices = [];

        foreach (DosageForm::cases() as $form) {
            $choices[ImportField::normalizeHeading($form->value)] = $form->value;
            $choices[ImportField::normalizeHeading($form->value.'s')] = $form->value;
        }

        return $choices + [
            'tab' => 'tablet', 'tabs' => 'tablet', 'cap' => 'capsule', 'caps' => 'capsule', 'syr' => 'syrup', 'susp' => 'suspension',
            'drop' => 'drops', 'eyedrops' => 'drops', 'eardrops' => 'drops', 'nasaldrops' => 'drops', 'oint' => 'ointment',
            'inj' => 'injection', 'vial' => 'injection', 'ampoule' => 'injection', 'amp' => 'injection',
        ];
    }

    /** @return array<string, string> */
    private static function schedules(): array
    {
        $choices = [];

        foreach (DrugSchedule::cases() as $schedule) {
            foreach ([$schedule->value, $schedule->label(), $schedule->shortLabel()] as $text) {
                $choices[ImportField::normalizeHeading($text)] = $schedule->value;
            }
        }

        return $choices + ['pom' => 'prescription_only', 'prescription' => 'prescription_only', 'cd' => 'controlled', 'controlleddrug' => 'controlled', 'nonprescription' => 'otc'];
    }

    /** @return array<string, string> */
    private static function taxCategories(): array
    {
        $choices = [];

        foreach (TaxCategory::cases() as $category) {
            $choices[ImportField::normalizeHeading($category->value)] = $category->value;
            $choices[ImportField::normalizeHeading($category->label())] = $category->value;
        }

        return $choices + ['taxed' => 'standard', 'vat' => 'standard', 'notax' => 'exempt', 'none' => 'exempt', 'zero' => 'exempt'];
    }

    /** @return array<string, string> */
    private static function storageConditions(): array
    {
        $choices = [];

        foreach (StorageCondition::cases() as $condition) {
            $choices[ImportField::normalizeHeading($condition->value)] = $condition->value;
            $choices[ImportField::normalizeHeading($condition->label())] = $condition->value;
        }

        return $choices + ['roomtemperature' => 'room', 'cold' => 'fridge', 'refrigerate' => 'fridge', 'refrigerator' => 'fridge', 'coolplace' => 'cool'];
    }
}
