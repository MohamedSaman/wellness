<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Exceptions\PosException;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Location;
use App\Models\NoReceiptReturn;
use App\Models\NoReceiptReturnItem;
use App\Models\ProductVariant;
use App\Models\Shift;
use App\Models\Shop;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\VariantUnit;
use App\Observers\AuditObserver;
use App\Services\Data\BatchInput;
use App\Services\Data\NoReceiptLine;
use App\Support\CurrentShop;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\UnitCost;
use Illuminate\Support\Facades\DB;

/**
 * Returns without a receipt (docs/PHARMACY.md, branch 18): a patient brings
 * back something bought before the system went live, or without the bill.
 * The owner (returns.no_receipt) refunds it in cash from the till's drawer
 * in its open shift, at today's price or a value typed; a reason is
 * required. The stock goes to Quarantine by default (it can't be checked),
 * or back to sellable stock at the till's location, through
 * StockService::returnWithoutReceipt(). Numbered NR-000001, audited, a
 * REFUND – NO RECEIPT slip. Pharmacy only; online only.
 */
class NoReceiptReturnService
{
    public const CREATED_EVENT = 'no_receipt_return_created';

    public const REPRINT_EVENT = 'no_receipt_return_reprinted';

    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly StockService $stock,
        private readonly AuditObserver $audit,
    ) {}

    public function enabled(): bool
    {
        return $this->shop()->hasModule('pharmacy');
    }

    /** The shop's location for stock that can't be sold: active and not selling, Quarantine by code first. */
    public function quarantine(): ?Location
    {
        return Location::query()
            ->where('is_active', true)
            ->where('is_sellable', false)
            ->orderByRaw("CASE WHEN code = 'QUAR' THEN 0 ELSE 1 END")
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  list<NoReceiptLine>  $lines
     */
    public function create(Device $till, User $user, array $lines, string $reason, ?string $note = null): NoReceiptReturn
    {
        if (! $this->enabled()) {
            throw new PosException('This shop does not take returns without a receipt.');
        }

        if (! $user->can('returns.no_receipt')) {
            throw new PosException('You are not allowed to refund items without a receipt.');
        }

        $reason = trim($reason);
        $note = blank($note) ? null : trim((string) $note);

        if ($reason === '') {
            throw new PosException('Give the reason for the return.');
        }

        if ($lines === []) {
            throw new PosException('Add the item that came back.');
        }

        /** @var Location $here */
        $here = $till->location ?? throw new PosException('This till has no location.');
        $quarantine = $this->quarantine();
        $priceList = $this->shop()->defaultPriceList();
        $prepared = [];

        foreach ($lines as $line) {
            /** @var ProductVariant|null $variant */
            $variant = ProductVariant::query()->with('product')->find($line->variantId);

            if ($variant === null) {
                throw new PosException('That item was not found.');
            }

            if (! $variant->product->track_stock) {
                throw new PosException("{$variant->sku} is a service: there is nothing to take back.");
            }

            /** @var VariantUnit|null $unit */
            $unit = $line->unitId === null
                ? $variant->units()->where('is_base', true)->first()
                : $variant->units()->whereKey($line->unitId)->first();

            if ($unit === null) {
                throw new PosException("Choose a unit of {$variant->sku}.");
            }

            $quantity = Quantity::normalize($line->quantity);

            if ($quantity <= 0) {
                throw new PosException('Quantities must be greater than zero.');
            }

            if ($variant->product->whole_units_only && ! Quantity::isWhole($quantity)) {
                throw new PosException("{$variant->sku} comes back in whole units: no half {$unit->name}.");
            }

            $listPrice = $unit->priceOn($priceList);
            $value = $line->unitValue ?? $listPrice;

            if ($value === null || $value <= 0) {
                throw new PosException("{$variant->sku} has no price for one {$unit->name}: enter the value to refund.");
            }

            $destination = $line->toQuarantine ? $quarantine : $here;

            if ($destination === null) {
                throw new PosException('This shop has no quarantine location: put it back on sale, or add a location that does not sell.');
            }

            $batch = null;

            if ($variant->product->tracksBatches()) {
                $batchNo = trim((string) $line->batchNo);
                $expiry = trim((string) $line->expiry);

                if (($batchNo === '') !== ($expiry === '')) {
                    throw new PosException("Enter both the batch number and the expiry of {$variant->sku}, or neither (Quarantine only).");
                }

                $batch = $batchNo === '' ? null : new BatchInput($batchNo, $expiry);
            }

            $prepared[] = compact('variant', 'unit', 'quantity', 'listPrice', 'value', 'destination', 'batch');
        }

        return DB::transaction(function () use ($till, $user, $here, $reason, $note, $prepared): NoReceiptReturn {
            // The shop row numbers one return at a time (NR-000001); the shift row keeps a closing shift waiting.
            Shop::query()->whereKey($this->shop()->getKey())->lockForUpdate()->firstOrFail();
            $shift = $this->lockOpenShift($till);

            $return = NoReceiptReturn::query()->create([
                'number' => $this->nextNumber(),
                'shift_id' => $shift->getKey(),
                'device_id' => $till->getKey(),
                'location_id' => $here->getKey(),
                'user_id' => $user->getKey(),
                'reason' => mb_substr($reason, 0, 255),
                'note' => $note,
                'refund_total' => 0,
                'refund_method' => PaymentMethod::Cash,
                'returned_at' => now(),
            ]);

            $total = 0;

            foreach ($prepared as $line) {
                /** @var ProductVariant $variant */
                $variant = $line['variant'];
                /** @var VariantUnit $unit */
                $unit = $line['unit'];
                /** @var Location $destination */
                $destination = $line['destination'];
                $baseQuantity = $unit->toBase($line['quantity']);
                $amount = Money::multiply((int) $line['value'], $line['quantity']);
                $total += $amount;
                $averageBefore = StockLevel::query()
                    ->where('product_variant_id', $variant->getKey())
                    ->where('location_id', $destination->getKey())
                    ->value('average_cost');

                $movement = $this->stock->returnWithoutReceipt($variant, $destination, $line['batch'], $baseQuantity, $user, $return, "No receipt {$return->number}", (int) $till->getKey());

                NoReceiptReturnItem::query()->create([
                    'no_receipt_return_id' => $return->getKey(),
                    'product_variant_id' => $variant->getKey(),
                    'variant_unit_id' => $unit->getKey(),
                    'description' => trim($variant->product->name.' '.($variant->name ?? '')).($unit->is_base ? '' : " ({$unit->name})"),
                    'sku' => $variant->sku,
                    'unit_name' => $unit->name,
                    'unit_factor' => $unit->factor,
                    'quantity' => $line['quantity'],
                    'base_quantity' => $baseQuantity,
                    'list_price' => (int) ($line['listPrice'] ?? 0),
                    'unit_value' => (int) $line['value'],
                    'refund_amount' => $amount,
                    'location_id' => $destination->getKey(),
                    'stock_batch_id' => $movement->stock_batch_id,
                    'batch_no' => $movement->batch?->is_unallocated ? null : $movement->batch?->batch_no,
                    'expiry_date' => $movement->batch?->is_unallocated ? null : $movement->batch?->expiry_date,
                    'unit_cost' => $averageBefore === null ? (int) $movement->unit_cost : UnitCost::toMinor((string) $averageBefore),
                ]);
            }

            $return->update(['refund_total' => $total]);

            $this->audit->record(self::CREATED_EVENT, $return, [], [
                'number' => $return->number,
                'refund_total' => $total,
                'refund_method' => PaymentMethod::Cash->value,
                'reason' => $return->reason,
                'note' => $note,
                'shift_id' => $shift->getKey(),
                'till' => $till->code,
                'items' => $return->items()->get()->map(fn (NoReceiptReturnItem $item): array => [
                    'sku' => $item->sku,
                    'quantity' => (float) $item->quantity,
                    'unit' => $item->unit_name,
                    'unit_value' => $item->unit_value,
                    'list_price' => $item->list_price,
                    'to' => $item->location_id,
                    'batch_no' => $item->batch_no,
                ])->all(),
            ], (int) $user->getKey());

            return $return->load('items');
        });
    }

    /**
     * What these items would refund (branch 19: the credit of an exchange
     * without a bill), line by line: the value typed or the unit's price
     * today, times the quantity. Throws what create() would refuse a line
     * with for want of a price.
     *
     * @param  list<NoReceiptLine>  $lines
     * @return array{total: int, lines: list<int>}
     */
    public function preview(array $lines): array
    {
        $priceList = $this->shop()->defaultPriceList();
        $amounts = [];

        foreach ($lines as $line) {
            /** @var ProductVariant|null $variant */
            $variant = ProductVariant::query()->find($line->variantId) ?? throw new PosException('That item was not found.');
            /** @var VariantUnit|null $unit */
            $unit = $line->unitId === null
                ? $variant->units()->where('is_base', true)->first()
                : $variant->units()->whereKey($line->unitId)->first();
            $value = $line->unitValue ?? $unit?->priceOn($priceList);

            if ($unit === null || $value === null || $value <= 0) {
                throw new PosException("{$variant->sku} has no price for one ".($unit->name ?? 'unit').': enter the value to refund.');
            }

            $amounts[] = Money::multiply((int) $value, Quantity::normalize($line->quantity));
        }

        return ['total' => array_sum($amounts), 'lines' => $amounts];
    }

    /** Whoever made it, a user who may read its shift, or one who may refund without a receipt. */
    public function mayRead(?User $user, NoReceiptReturn $return): bool
    {
        return $user !== null && (
            (int) $return->user_id === (int) $user->getKey()
            || $user->can('shifts.manage')
            || $user->can('returns.no_receipt')
            || (int) $return->shift?->user_id === (int) $user->getKey()
        );
    }

    public function recordReprint(NoReceiptReturn $return, User $user, ?Device $till): AuditLog
    {
        if (! $this->mayRead($user, $return)) {
            throw new PosException('You are not allowed to print this slip.');
        }

        return $this->audit->record(self::REPRINT_EVENT, $return, [], [
            'number' => $return->number,
            'device_id' => $till?->getKey(),
            'till' => $till?->code,
            'source' => $till === null ? 'admin' : 'till',
        ], (int) $user->getKey());
    }

    /** A reprint entry of this return in the current shop, or null (audit logs are not tenant-scoped). */
    public function findReprint(NoReceiptReturn $return, int $auditLogId): ?AuditLog
    {
        return AuditLog::query()
            ->with('user')
            ->whereKey($auditLogId)
            ->where('shop_id', $this->currentShop->id())
            ->where('event', self::REPRINT_EVENT)
            ->where('auditable_type', $return->getMorphClass())
            ->where('auditable_id', $return->getKey())
            ->first();
    }

    /**
     * The slip for ReceiptLayout::noReceiptRefund(), in shop time.
     *
     * @return array<string, mixed>
     */
    public function slip(NoReceiptReturn $return, ?AuditLog $reprint = null): array
    {
        $return->loadMissing(['items.location', 'device', 'location', 'user']);
        $timezone = (string) ($this->shop()->timezone ?: config('app.timezone'));
        $device = $return->device;

        return [
            'number' => $return->number,
            'returnedAt' => $return->returned_at->setTimezone($timezone)->format('Y-m-d H:i'),
            'till' => $device === null ? null : ['code' => (string) $device->code, 'name' => $device->name, 'location' => $return->location?->name],
            'user' => (string) $return->user?->name,
            'reason' => $return->reason,
            'note' => $return->note,
            'refundTotal' => $return->refund_total,
            'method' => 'Cash',
            'items' => $return->items->map(fn (NoReceiptReturnItem $item): array => [
                'description' => $item->description,
                'quantity' => Quantity::format((string) $item->quantity),
                'unitName' => $item->unit_name,
                'unitValue' => $item->unit_value,
                'refund' => $item->refund_amount,
                'to' => $item->location?->is_sellable ? 'Back to stock: '.$item->location->name : 'To '.($item->location->name ?? 'Quarantine'),
                'batchNo' => $item->batch_no,
                'expiry' => $item->expiry_date?->format('m/Y'),
            ])->values()->all(),
            'reprint' => app(ReceiptService::class)->reprintStamp($reprint),
        ];
    }

    private function lockOpenShift(Device $till): Shift
    {
        /** @var Shift|null $shift */
        $shift = Shift::query()
            ->where('device_id', $till->getKey())
            ->whereNull('closed_at')
            ->latest('opened_at')
            ->lockForUpdate()
            ->first();

        return $shift ?? throw new PosException('Open a shift on this till first: the refund comes out of a shift\'s drawer.');
    }

    /** The next number, per shop. Only with the shop row locked. */
    private function nextNumber(): string
    {
        return 'NR-'.str_pad((string) (NoReceiptReturn::query()->count() + 1), 6, '0', STR_PAD_LEFT);
    }

    private function shop(): Shop
    {
        return $this->currentShop->getOrFail();
    }
}
