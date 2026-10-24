<?php

namespace App\Services\Import;

use App\Enums\ImportType;
use App\Enums\StockMovementType;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\VariantUnit;
use App\Services\BatchService;
use App\Services\Data\BatchInput;
use App\Services\StockService;
use App\Support\Quantity;
use App\Support\UnitCost;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Opening stock (docs/PHARMACY.md §10): what is on the shelf at go-live,
 * per item, location and batch, at its cost. The item is found by SKU, by
 * the barcode of the item or of one of its packs, or by its exact name; the
 * quantity is in the base unit or a pack named on the row (or the pack whose
 * barcode found the item), and the unit cost is per that unit.
 *
 * Each row is an "opening" movement through StockService (rule 1): into its
 * batch with receive() for a batch-tracked item (BatchService's rules: no
 * expired stock, one expiry per batch number; short expiry is a warning the
 * owner accepts by confirming), through record() otherwise, at the cost per
 * base unit (the unit cost ÷ the pack's contents, 4 places), which sets the
 * location's average. The movement references the import run.
 *
 * Opening stock is counted once: a row whose item already has opening stock
 * in that batch at that location (any batch, for an item without batches) is
 * skipped, so the same file can be run again. Rows of one file for the same
 * batch (two shelves) are added together.
 */
final class OpeningStockImport implements ImportDefinition
{
    public function __construct(
        private readonly StockService $stock,
        private readonly BatchService $batches,
    ) {}

    public function type(): ImportType
    {
        return ImportType::OpeningStock;
    }

    public function sheetName(Shop $shop): string
    {
        return 'Opening stock';
    }

    public function fields(Shop $shop): array
    {
        $codes = array_values(array_map(strval(...), Location::query()->where('is_active', true)->ordered()->pluck('code')->all()));
        $multi = $shop->hasModule('multi_location');
        $batches = $shop->hasModule('pharmacy') || StockBatch::query()->exists();

        return array_values(array_filter([
            new ImportField('item', 'Item', true, ['SKU', 'Barcode', 'Item code', 'Item name', 'Product', 'Name', 'Medicine', 'Description'], 'The item\'s SKU, its barcode (or a pack\'s barcode), or its name exactly as on file.', text: true, width: 34),
            new ImportField('location', 'Location', $multi, ['Location code', 'Store', 'Pool', 'Branch'], $multi ? 'The location code: '.implode(', ', $codes).'. Stock is usually received at the default location.' : 'Optional: the shop has one location.', options: $codes, width: 11),
            $batches ? new ImportField('batch_no', 'Batch no.', aliases: ['Batch', 'Lot', 'Lot no', 'Batch number'], help: 'As printed on the pack. Required for medicines (batch-tracked items).', text: true, width: 14) : null,
            $batches ? new ImportField('expiry', 'Expiry', aliases: ['Expiry date', 'Exp', 'Exp date', 'Expires'], help: 'As printed: 03/2027 (end of that month) or a full date 15/03/2027. Required for medicines; expired stock is refused.', text: true, width: 11) : null,
            new ImportField('quantity', 'Quantity', true, ['Qty', 'Stock', 'On hand', 'Count', 'Quantity on hand', 'Balance'], 'How many, in the unit below. Whole numbers for whole-units items.', width: 11),
            new ImportField('unit', 'Unit', aliases: ['UOM', 'Pack', 'Unit of measure'], help: 'The base unit (Tablet, ml) or a pack\'s name (Box of 100). Blank: the base unit, or the pack whose barcode is in Item.', width: 14),
            new ImportField('unit_cost', 'Unit cost', aliases: ['Cost', 'Cost price', 'Purchase price', 'Buying price', 'Rate', 'Unit price'], help: 'What one of the unit above cost, in '.$shop->currency.' (a box of 100 at 380.00). Sets the average cost. Blank: the item\'s current average cost.', width: 11),
        ]));
    }

    public function instructions(Shop $shop): array
    {
        return array_values(array_filter([
            'One row per item and batch on the shelf at go-live: what you counted, where, and what it cost.',
            'Import the items first: this file finds each item by SKU, barcode or exact name.',
            $shop->hasModule('pharmacy') ? 'Medicines need the batch number and expiry printed on the pack. Expired stock is refused; stock expiring within '.$this->batches->nearExpiryWarningDays().' days is a warning you accept by importing.' : null,
            'Quantity can be in packs: put the pack\'s name in Unit (3 in "Box of 100" is 300 tablets) and the cost of one pack in Unit cost.',
            'Opening stock is entered once: a row for a batch (or an item without batches) that already has opening stock at that location is skipped, so the same file can be imported again safely.',
            'The three grey rows are examples: delete them or leave them, they are never imported.',
        ], fn (?string $line): bool => $line !== null));
    }

    public function examples(Shop $shop): array
    {
        $default = $shop->defaultLocation()->code;
        $other = Location::query()->where('is_active', true)->where('code', '!=', $default)->ordered()->value('code') ?? $default;

        if (! $shop->hasModule('pharmacy')) {
            return [
                ['item' => 'EX-HELMET-M', 'location' => $default, 'quantity' => 4, 'unit' => null, 'unit_cost' => 12500.00],
                ['item' => '8901234500024', 'location' => $default, 'quantity' => 6, 'unit' => 'Can of 4', 'unit_cost' => 7200.00],
                ['item' => 'Example Brake Pad Set', 'location' => $default, 'quantity' => 10, 'unit' => null, 'unit_cost' => null],
            ];
        }

        return [
            ['item' => 'EX-PCM-500', 'location' => $default, 'batch_no' => 'PX2401', 'expiry' => '09/2028', 'quantity' => 12, 'unit' => 'Box of 100', 'unit_cost' => 360.00],
            ['item' => 'EX-PCM-500', 'location' => $other, 'batch_no' => 'PX2401', 'expiry' => '09/2028', 'quantity' => 150, 'unit' => 'Tablet', 'unit_cost' => 3.60],
            ['item' => 'Example Chlorphenamine 2 mg/5 ml Syrup 100 ml', 'location' => $default, 'batch_no' => 'CS-118', 'expiry' => '31/01/2028', 'quantity' => 24, 'unit' => 'Bottle', 'unit_cost' => 290.00],
        ];
    }

    public function check(array $rows, ImportContext $context): void
    {
        $shop = $context->shop;
        $multi = $shop->hasModule('multi_location');
        $default = $shop->defaultLocation();
        $locations = Location::query()->get()->keyBy(fn (Location $location): string => Str::upper($location->code));
        $index = $this->itemIndex();
        $today = $this->batches->today();
        $maxExpiry = $today->addYears(BatchService::MAX_EXPIRY_YEARS);
        $warningDays = $this->batches->nearExpiryWarningDays();
        $mayAcceptShort = $context->user->can('batches.manage');

        $resolved = [];

        foreach ($rows as $row) {
            $reference = $row->text('item', 255, required: true);
            $code = $row->text('location', 20);
            $quantity = $row->decimal('quantity', required: true, positive: true);
            $unitName = $row->text('unit', 30);
            $cost = $row->cost('unit_cost', $context->currencyDecimals());
            $batchNo = $row->text('batch_no', 50);
            $expiry = $row->expiry('expiry');

            $location = $code === null ? ($multi ? null : $default) : $locations->get(Str::upper($code));

            if ($code === null && $multi) {
                $row->error('Location is missing: '.$locations->keys()->implode(', ').'.');
            } elseif ($code !== null && $location === null) {
                $row->error("Unknown location \"{$code}\": use ".$locations->filter(fn (Location $l): bool => $l->is_active)->keys()->implode(', ').'.');
            } elseif ($location !== null && ! $location->is_active) {
                $row->error("{$location->name} is not active.");
            } elseif ($location !== null && ! $multi && $location->getKey() !== $default->getKey()) {
                $row->error("Stock can only be counted at {$default->name} (one location).");
            }

            [$variantId, $packId] = $reference === null ? [null, null] : $this->findItem($row, $reference, $index);

            $resolved[] = compact('row', 'variantId', 'packId', 'location', 'quantity', 'unitName', 'cost', 'batchNo', 'expiry');
        }

        // Every item named in the file, with its units and batches, in a few queries.
        $variantIds = array_values(array_unique(array_filter(array_column($resolved, 'variantId'))));
        /** @var Collection<int, ProductVariant> $variants */
        $variants = ProductVariant::query()->with(['product', 'units'])->whereKey($variantIds)->get()->keyBy('id');
        $openings = $this->existingOpenings($variantIds);
        $knownBatches = StockBatch::query()
            ->whereIn('product_variant_id', $variantIds)
            ->where('is_unallocated', false)
            ->get(['product_variant_id', 'batch_no', 'expiry_date'])
            ->groupBy(fn (StockBatch $batch): string => $batch->product_variant_id.'|'.$batch->batch_no);
        $fileExpiries = [];

        foreach ($resolved as $line) {
            /** @var ImportRow $row */
            $row = $line['row'];
            $variant = $line['variantId'] === null ? null : $variants->get($line['variantId']);

            if ($variant === null) {
                continue;
            }

            $product = $variant->product;

            if (! $product->track_stock) {
                $row->error("{$product->name} is a service: it has no stock.");

                continue;
            }

            $unit = $this->unitOf($row, $variant, $line['unitName'], $line['packId']);
            $baseQuantity = null;
            $baseCost = null;

            if ($unit !== null && $line['quantity'] !== null) {
                if ($product->whole_units_only && ! Quantity::isWhole((float) $line['quantity'])) {
                    $row->error("{$variant->sku} is counted in whole units only: {$line['quantity']} {$unit->name} is not allowed.");
                }

                $baseQuantity = $unit->toBase((float) $line['quantity']);
                $baseCost = $line['cost'] === null ? null : UnitCost::normalize(bcdiv($line['cost'], UnitCost::normalize((string) $unit->factor), 8));
            }

            if ($line['cost'] === null) {
                $row->warn('No unit cost: valued at the item\'s current average cost.');
            }

            $batch = null;
            $key = $variant->getKey().'|'.($line['location']?->getKey() ?? 0);

            if ($product->tracksBatches()) {
                $batchNo = $line['batchNo'] === null ? '' : BatchService::normalizeBatchNo($line['batchNo']);
                $expiry = $line['expiry'];

                if ($batchNo === '') {
                    $row->error("Batch no. is missing: {$variant->sku} tracks batches.");
                }

                if ($expiry === null && ! $row->filled('expiry')) {
                    $row->error("Expiry is missing: {$variant->sku} tracks batches.");
                }

                if ($expiry !== null) {
                    $shown = $expiry->format('d/m/Y');

                    if ($expiry->lessThanOrEqualTo($today)) {
                        $row->error("Batch {$batchNo} expired on {$shown}: expired stock can't be taken in. Leave it out and write it off.");
                    } elseif ($expiry->greaterThan($maxExpiry)) {
                        $row->error("Batch {$batchNo} expires on {$shown}, more than ".BatchService::MAX_EXPIRY_YEARS.' years away: check the date.');
                    } elseif ($expiry->lessThanOrEqualTo($today->addDays($warningDays))) {
                        $mayAcceptShort
                            ? $row->warn("Short expiry: batch {$batchNo} expires on {$shown}, within {$warningDays} days.")
                            : $row->error("Batch {$batchNo} expires on {$shown}, within {$warningDays} days: only someone who may accept short expiry (batches.manage) can import it.");
                    }
                }

                if ($batchNo !== '' && $expiry !== null) {
                    $expiryDate = $expiry->toDateString();
                    $onFile = ($knownBatches->get($variant->getKey().'|'.$batchNo) ?? collect())
                        ->first(fn (StockBatch $known): bool => $known->expiry_date?->toDateString() !== $expiryDate);
                    $fileKey = $variant->getKey().'|'.$batchNo;

                    if ($onFile !== null) {
                        $row->error("Batch {$batchNo} of {$variant->sku} is on file with expiry ".$onFile->expiry_date?->format('d/m/Y').': one batch has one expiry.');
                    } elseif (isset($fileExpiries[$fileKey]) && $fileExpiries[$fileKey][0] !== $expiryDate) {
                        $row->error("Batch {$batchNo} is on row {$fileExpiries[$fileKey][1]} with another expiry: one batch has one expiry.");
                    }

                    $fileExpiries[$fileKey] ??= [$expiryDate, $row->number];
                    $batch = ['batch_no' => $batchNo, 'expiry' => $expiryDate, 'short' => $expiry->lessThanOrEqualTo($today->addDays($warningDays))];
                    $key .= '|'.$batchNo.'|'.$expiryDate;
                }
            } elseif ($line['batchNo'] !== null || $row->filled('expiry')) {
                $row->warn("{$variant->sku} does not track batches: the batch and expiry are ignored.");
            }

            if ($row->hasErrors() || $line['location'] === null || $baseQuantity === null) {
                continue;
            }

            if (isset($openings[$key])) {
                $row->skip("{$variant->sku} already has opening stock".($batch === null ? '' : " in batch {$batch['batch_no']}")." at {$line['location']->code}: skipped.");

                continue;
            }

            $row->action = ImportRow::CREATE;
            $row->data = [
                'variant_id' => (int) $variant->getKey(),
                'location_id' => (int) $line['location']->getKey(),
                'quantity' => $baseQuantity,
                'cost' => $baseCost,
                'batch' => $batch,
            ];
        }
    }

    public function commit(array $rows, ImportContext $context, \Closure $progress): void
    {
        $writes = array_values(array_filter($rows, fn (ImportRow $row): bool => $row->action === ImportRow::CREATE));

        // The receipts' lock order: variants by id, then levels by (location, variant).
        usort($writes, fn (ImportRow $a, ImportRow $b): int => [$a->data['variant_id'], $a->data['location_id'], $a->number] <=> [$b->data['variant_id'], $b->data['location_id'], $b->number]);

        $variants = ProductVariant::query()->with('product')->whereKey(array_column(array_column($writes, 'data'), 'variant_id'))->get()->keyBy('id');
        $locations = Location::query()->get()->keyBy('id');
        $skipped = count($rows) - count($writes);

        foreach ($writes as $done => $row) {
            /** @var ProductVariant $variant */
            $variant = $variants[$row->data['variant_id']];
            /** @var Location $location */
            $location = $locations[$row->data['location_id']];
            $batch = $row->data['batch'];

            $row->write(fn () => $batch !== null
                ? $this->stock->receive(
                    $variant, $location,
                    new BatchInput($batch['batch_no'], $batch['expiry'], acceptNearExpiry: $batch['short']),
                    StockMovementType::Opening, $row->data['quantity'], $context->user,
                    reference: $context->run, unitCost: $row->data['cost'], note: $context->note(),
                )
                : $this->stock->record(
                    $variant, $location, StockMovementType::Opening, $row->data['quantity'], $context->user,
                    reference: $context->run, unitCost: $row->data['cost'], note: $context->note(),
                ));

            $progress($skipped + $done + 1);
        }
    }

    /**
     * Finds the item a row names: SKU, then the item's barcode, then a
     * pack's barcode (which also picks that pack), then the exact name.
     *
     * @param  array<string, mixed>  $index
     * @return array{0: int|null, 1: int|null} variant id, pack id
     */
    private function findItem(ImportRow $row, string $reference, array $index): array
    {
        $code = (string) preg_replace('/\s+/', '', $reference);

        if (isset($index['sku'][Str::lower($code)])) {
            return [$index['sku'][Str::lower($code)], null];
        }

        if (isset($index['barcode'][$code])) {
            return [$index['barcode'][$code], null];
        }

        if (isset($index['packs'][$code])) {
            return $index['packs'][$code];
        }

        $named = $index['names'][Str::lower(Str::squish($reference))] ?? [];

        if (count($named) === 1) {
            return [$named[0], null];
        }

        $row->error(count($named) > 1
            ? count($named)." items are called \"{$reference}\": use the SKU to say which."
            : "Unknown item \"{$reference}\": no item has this SKU, barcode or name. Import it with the items template first.");

        return [null, null];
    }

    private function unitOf(ImportRow $row, ProductVariant $variant, ?string $name, ?int $packId): ?VariantUnit
    {
        $units = $variant->units->where('is_active', true);

        if ($name === null) {
            $unit = $packId === null ? $units->firstWhere('is_base', true) : $units->firstWhere('id', $packId);

            return $unit ?? $units->firstWhere('is_base', true);
        }

        $wanted = Str::lower(Str::squish($name));
        $unit = $units->first(fn (VariantUnit $unit): bool => Str::lower($unit->name) === $wanted
            || Str::lower(VariantUnit::describe($unit->name, (string) $unit->factor)) === $wanted);

        if ($unit === null) {
            $row->error("Unknown unit \"{$name}\" for {$variant->sku}: its units are ".$units->map(fn (VariantUnit $unit): string => $unit->name)->implode(', ').'.');
        }

        return $unit;
    }

    /**
     * Every item's SKU, barcode, pack barcodes and name, loaded once.
     *
     * @return array<string, mixed>
     */
    private function itemIndex(): array
    {
        $index = ['sku' => [], 'barcode' => [], 'packs' => [], 'names' => []];

        foreach (ProductVariant::query()->toBase()->join('products', 'products.id', '=', 'product_variants.product_id')->whereNull('products.deleted_at')->get(['product_variants.id', 'product_variants.sku', 'product_variants.barcode', 'products.name']) as $variant) {
            $index['sku'][Str::lower((string) $variant->sku)] = (int) $variant->id;

            if ($variant->barcode !== null) {
                $index['barcode'][(string) $variant->barcode] = (int) $variant->id;
            }

            $index['names'][Str::lower(Str::squish((string) $variant->name))][] = (int) $variant->id;
        }

        foreach (VariantUnit::query()->whereNotNull('barcode')->where('is_active', true)->toBase()->get(['id', 'product_variant_id', 'barcode']) as $unit) {
            $index['packs'][(string) $unit->barcode] = [(int) $unit->product_variant_id, (int) $unit->id];
        }

        return $index;
    }

    /**
     * Opening stock already counted for these items: "variant|location" for
     * items without batches, "variant|location|batch|expiry" for batches.
     *
     * @param  list<int>  $variantIds
     * @return array<string, true>
     */
    private function existingOpenings(array $variantIds): array
    {
        $keys = [];

        foreach (array_chunk($variantIds, 1000) as $chunk) {
            $movements = StockMovement::query()
                ->toBase()
                ->leftJoin('stock_batches', 'stock_batches.id', '=', 'stock_movements.stock_batch_id')
                ->where('stock_movements.type', StockMovementType::Opening->value)
                ->whereIn('stock_movements.product_variant_id', $chunk)
                ->get(['stock_movements.product_variant_id', 'stock_movements.location_id', 'stock_batches.batch_no', 'stock_batches.expiry_date']);

            foreach ($movements as $movement) {
                $key = $movement->product_variant_id.'|'.$movement->location_id;
                $keys[$key] = true;

                if ($movement->batch_no !== null) {
                    $keys[$key.'|'.$movement->batch_no.'|'.substr((string) $movement->expiry_date, 0, 10)] = true;
                }
            }
        }

        return $keys;
    }
}
