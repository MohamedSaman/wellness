<?php

namespace App\Services;

use App\Exceptions\PosException;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\StockBatch;
use App\Models\StockRequest;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\VariantUnit;
use App\Services\Data\TransferLine;
use App\Support\CurrentShop;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Transfers between locations (multi_location, docs/PHARMACY.md §5). Saving a
 * transfer posts it: one document (from, to, lines in any unit, note, user)
 * and, per line, a transfer_out / transfer_in pair in the ledger, all in one
 * transaction (StockService::transfer). Cost travels at the source
 * location's average. A transfer can never take more than the source has on
 * hand, and is never edited afterwards: a mistake is a transfer back.
 *
 * Batch-tracked items move batch by batch (docs/PHARMACY.md §6.3): the batch
 * named on the line, or first expiry first out. Each arrives as the same
 * batch at the destination; the line keeps one row per batch moved.
 */
class StockTransferService
{
    public const MAX_LINES = 200;

    public function __construct(
        private readonly StockService $stock,
        private readonly CurrentShop $currentShop,
    ) {}

    /**
     * @param  list<TransferLine>  $lines
     * @param  StockRequest|null  $request  the stock request this transfer fulfils (StockRequestService::fulfil())
     */
    public function create(Location $from, Location $to, array $lines, User $user, ?string $note = null, ?StockRequest $request = null): StockTransfer
    {
        if (! $user->can('stock.transfer')) {
            throw new PosException('You do not have permission to transfer stock.');
        }

        $shop = $this->currentShop->getOrFail();

        if (! $shop->hasModule('multi_location')) {
            throw new PosException('Transfers need the multi_location module.');
        }

        if ((int) $from->getKey() === (int) $to->getKey()) {
            throw new PosException('Choose two different locations.');
        }

        foreach ([$from, $to] as $location) {
            if ((int) $location->shop_id !== (int) $shop->getKey()) {
                throw new \LogicException('Locations must belong to the current shop.');
            }

            if (! $location->is_active) {
                throw new PosException("{$location->name} is not active.");
            }
        }

        if ($lines === []) {
            throw new PosException('Add at least one item to transfer.');
        }

        if (count($lines) > self::MAX_LINES) {
            throw new PosException('A transfer can have at most '.self::MAX_LINES.' lines.');
        }

        $prepared = $this->prepare($lines, $from);
        $note = blank($note) ? null : trim((string) $note);

        return DB::transaction(function () use ($shop, $from, $to, $prepared, $user, $note, $request): StockTransfer {
            // One number at a time per shop.
            Shop::query()->whereKey($shop->getKey())->lockForUpdate()->firstOrFail();

            $transfer = StockTransfer::query()->create([
                'transfer_number' => 'TR-'.str_pad((string) (StockTransfer::query()->count() + 1), 6, '0', STR_PAD_LEFT),
                'from_location_id' => $from->getKey(),
                'to_location_id' => $to->getKey(),
                'stock_request_id' => $request?->getKey(),
                'note' => $note,
                'user_id' => $user->getKey(),
                'transferred_at' => now(),
            ]);

            $moves = $this->stock->transfer(
                $from,
                $to,
                array_map(fn (array $line): array => ['variant' => $line['variant'], 'quantity' => $line['base_quantity'], 'batch' => $line['batch']], $prepared),
                $user,
                $transfer,
                $note,
                $transfer->transferred_at,
            );

            foreach ($prepared as $i => $line) {
                $item = $transfer->items()->create([
                    'product_variant_id' => $line['variant']->getKey(),
                    'variant_unit_id' => $line['unit']->getKey(),
                    'unit_name' => $line['unit']->name,
                    'unit_factor' => (float) $line['unit']->factor,
                    'quantity' => $line['quantity'],
                    'base_quantity' => $line['base_quantity'],
                    'unit_cost' => $moves[$i]['unit_cost'],
                    'out_movement_id' => $moves[$i]['out']?->getKey(),
                    'in_movement_id' => $moves[$i]['in']?->getKey(),
                ]);

                foreach ($moves[$i]['batches'] as $piece) {
                    $item->batches()->create([
                        'from_stock_batch_id' => $piece['from']->getKey(),
                        'to_stock_batch_id' => $piece['to']->getKey(),
                        'batch_no' => $piece['from']->batch_no,
                        'expiry_date' => $piece['from']->expiry_date?->toDateString(),
                        'quantity' => $piece['quantity'],
                        'out_movement_id' => $piece['out']->getKey(),
                        'in_movement_id' => $piece['in']->getKey(),
                    ]);
                }
            }

            return $transfer->load('items.batches');
        }, attempts: 3);
    }

    /**
     * Each line's item and unit, and its quantity in base units. Any active
     * unit can be used (a box, a strip, loose tablets), in whole units of it
     * for a whole-units product.
     *
     * @param  list<TransferLine>  $lines
     * @return list<array{variant: ProductVariant, unit: VariantUnit, quantity: float, base_quantity: float, batch: StockBatch|null}>
     */
    private function prepare(array $lines, Location $from): array
    {
        $variants = ProductVariant::query()
            ->with(['product', 'units'])
            ->whereKey(array_values(array_unique(array_map(fn (TransferLine $line): int => $line->variantId, $lines))))
            ->get()
            ->keyBy('id');

        $prepared = [];

        foreach ($lines as $line) {
            /** @var ProductVariant|null $variant */
            $variant = $variants->get($line->variantId)
                ?? throw new PosException("Item #{$line->variantId} was not found.");

            if (! $variant->product->track_stock) {
                throw new PosException("{$variant->sku} is a service; it has no stock to transfer.");
            }

            /** @var VariantUnit|null $unit */
            $unit = $line->unitId === null
                ? $variant->units->firstWhere('is_base', true)
                : $variant->units->firstWhere('id', $line->unitId);

            if ($unit === null || ! $unit->is_active) {
                throw new PosException("That unit is not one of {$variant->sku}'s units.");
            }

            $quantity = Quantity::normalize($line->quantity);

            if ($quantity <= 0) {
                throw new PosException('Quantities must be greater than zero.');
            }

            $this->stock->assertWholeUnits($variant, $quantity, $unit->name);

            $batch = null;

            if ($line->batchId !== null) {
                if (! $variant->product->tracksBatches()) {
                    throw new PosException("{$variant->sku} does not track batches.");
                }

                $batch = StockBatch::query()
                    ->where('product_variant_id', $variant->getKey())
                    ->where('location_id', $from->getKey())
                    ->where('is_unallocated', false)
                    ->find($line->batchId) ?? throw new PosException("That batch of {$variant->sku} is not at {$from->name}.");
            }

            $prepared[] = ['variant' => $variant, 'unit' => $unit, 'quantity' => $quantity, 'base_quantity' => $unit->toBase($quantity), 'batch' => $batch];
        }

        return $prepared;
    }
}
