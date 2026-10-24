<?php

namespace App\Services;

use App\Enums\AdjustmentReason;
use App\Exceptions\PosException;
use App\Models\Location;
use App\Models\Shop;
use App\Models\StockBatch;
use App\Models\StockWriteOff;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Services\Data\WriteOffLine;
use App\Support\CurrentShop;
use App\Support\Quantity;
use App\Support\UnitCost;
use Illuminate\Support\Facades\DB;

/**
 * Expiry write-off (docs/PHARMACY.md §9, branch 10): expired, near-expiry or
 * damaged batches leave stock for good, with a reason (expired, damaged,
 * other), valued at what each batch cost. One write-off note per location
 * (WO-000001), posted when saved and never changed: each line is an
 * adjustment_out of its batch through StockService::writeOff(), at the
 * batch's purchase cost, referencing the note. Owner only
 * (stock.write_off), audited (stock_written_off). Batches selected across
 * locations make one note per location, all or none.
 *
 * The other ways out of the expiry list are a transfer to a location that
 * does not sell (quarantine, StockTransferService) and a supplier return
 * (SupplierReturnService).
 */
class StockWriteOffService
{
    public const MAX_LINES = 200;

    public function __construct(
        private readonly StockService $stock,
        private readonly CurrentShop $currentShop,
        private readonly AuditObserver $audit,
    ) {}

    /**
     * @param  list<WriteOffLine>  $lines  batches of any locations
     * @return list<StockWriteOff> one per location, in the shop's location order
     */
    public function writeOff(array $lines, AdjustmentReason $reason, User $user, ?string $note = null): array
    {
        $this->authorize($user);

        if (! $reason->isWriteOff()) {
            throw new PosException('Write stock off as expired, damaged or other.');
        }

        $note = blank($note) ? null : trim((string) $note);

        if ($reason->requiresNote() && $note === null) {
            throw new PosException('Add a note explaining the write-off.');
        }

        if ($lines === []) {
            throw new PosException('Choose at least one batch to write off.');
        }

        if (count($lines) > self::MAX_LINES) {
            throw new PosException('A write-off can have at most '.self::MAX_LINES.' batches.');
        }

        $byLocation = $this->prepare($lines);

        return DB::transaction(function () use ($byLocation, $reason, $user, $note): array {
            // One number at a time per shop.
            Shop::query()->whereKey($this->currentShop->getOrFail()->getKey())->lockForUpdate()->firstOrFail();

            $notes = [];

            foreach ($byLocation as ['location' => $location, 'lines' => $prepared]) {
                $notes[] = $this->post($location, $prepared, $reason, $user, $note);
            }

            return $notes;
        }, attempts: 3);
    }

    /**
     * @param  list<array{batch: StockBatch, quantity: float}>  $prepared
     */
    private function post(Location $location, array $prepared, AdjustmentReason $reason, User $user, ?string $note): StockWriteOff
    {
        // A batch's purchase cost is set when it first arrives and never changes, so the
        // note's value is known before any stock moves.
        foreach ($prepared as $i => $line) {
            $prepared[$i]['unit_cost'] = UnitCost::normalize($line['batch']->unit_cost);
            $prepared[$i]['line_total'] = UnitCost::times($prepared[$i]['unit_cost'], $line['quantity']);
        }

        $total = array_sum(array_column($prepared, 'line_total'));

        $writeOff = StockWriteOff::query()->create([
            'write_off_number' => 'WO-'.str_pad((string) (StockWriteOff::query()->count() + 1), 6, '0', STR_PAD_LEFT),
            'location_id' => $location->getKey(),
            'reason' => $reason,
            'note' => $note,
            'total' => $total,
            'user_id' => $user->getKey(),
            'written_off_at' => now(),
        ]);

        foreach ($prepared as $line) {
            $batch = $line['batch'];
            $movement = $this->stock->writeOff($batch, $line['quantity'], $reason, $user, $writeOff, "Write-off {$writeOff->write_off_number}".($note === null ? '' : ": {$note}"));

            $writeOff->items()->create([
                'product_variant_id' => $batch->product_variant_id,
                'stock_batch_id' => $batch->getKey(),
                'batch_no' => $batch->batch_no,
                'expiry_date' => $batch->expiry_date?->toDateString(),
                'quantity' => $line['quantity'],
                'unit_cost' => $line['unit_cost'],
                'line_total' => $line['line_total'],
                'stock_movement_id' => $movement->getKey(),
            ]);
        }

        $this->audit->record('stock_written_off', $writeOff, [], [
            'write_off_number' => $writeOff->write_off_number,
            'location_id' => (int) $location->getKey(),
            'reason' => $reason->value,
            'note' => $note,
            'total' => $total,
            'batches' => array_map(fn (array $line): array => [
                'stock_batch_id' => (int) $line['batch']->getKey(),
                'batch_no' => $line['batch']->batch_no,
                'quantity' => $line['quantity'],
            ], $prepared),
        ], (int) $user->getKey());

        return $writeOff->load('items');
    }

    /**
     * The batches, grouped by location (shop order), each line's quantity in
     * base units (all the batch holds when none is given). Lines are sorted
     * by item and batch, the order stock levels are locked in.
     *
     * @param  list<WriteOffLine>  $lines
     * @return list<array{location: Location, lines: list<array{batch: StockBatch, quantity: float}>}>
     */
    private function prepare(array $lines): array
    {
        $ids = array_map(fn (WriteOffLine $line): int => $line->batchId, $lines);

        if (count($ids) !== count(array_unique($ids))) {
            throw new PosException('Each batch can be written off once per write-off.');
        }

        $batches = StockBatch::query()->with(['variant.product', 'location'])->whereKey($ids)->get()->keyBy('id');
        $groups = [];

        foreach ($lines as $line) {
            /** @var StockBatch|null $batch */
            $batch = $batches->get($line->batchId) ?? throw new PosException("Batch #{$line->batchId} was not found.");

            if ($batch->is_unallocated) {
                throw new PosException('Unallocated stock has no batch to write off: count the real batches instead.');
            }

            $quantity = Quantity::normalize($line->quantity ?? (float) $batch->quantity);

            if ($quantity <= 0) {
                throw new PosException("Batch {$batch->label()} of {$batch->variant->sku} has nothing to write off.");
            }

            $groups[(int) $batch->location_id][] = ['batch' => $batch, 'quantity' => $quantity];
        }

        $ordered = [];

        foreach (Location::query()->ordered()->whereKey(array_keys($groups))->get() as $location) {
            $prepared = $groups[(int) $location->getKey()];
            usort($prepared, fn (array $a, array $b): int => [$a['batch']->product_variant_id, $a['batch']->getKey()] <=> [$b['batch']->product_variant_id, $b['batch']->getKey()]);
            $ordered[] = ['location' => $location, 'lines' => $prepared];
        }

        return $ordered;
    }

    private function authorize(User $user): void
    {
        if (! $user->can('stock.write_off')) {
            throw new PosException('You do not have permission to write stock off.');
        }

        if (! $this->currentShop->getOrFail()->hasModule('pharmacy')) {
            throw new PosException('Write-offs of batches need the pharmacy module.');
        }
    }
}
