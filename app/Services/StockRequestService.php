<?php

namespace App\Services;

use App\Enums\StockRequestStatus;
use App\Exceptions\PosException;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\StockBatch;
use App\Models\StockLevel;
use App\Models\StockRequest;
use App\Models\StockRequestItem;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\VariantUnit;
use App\Services\Data\FulfilLine;
use App\Services\Data\RequestLine;
use App\Services\Data\TransferLine;
use App\Support\CurrentShop;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Stock requests between locations (multi_location, docs/PHARMACY.md §9,
 * branch 10). The clinic receives everything at General Pharmacy (GEN); the
 * OPD (DOC) asks it for stock:
 *
 *   - create(): someone with stock.request at a location they may use
 *     (User::mayUseLocation(); a DOC cashier at DOC) asks another location,
 *     by default the shop's default one, for items in any of their units;
 *   - fulfil(): someone with stock.transfer (the owner, grantable) sends all
 *     or part of what is outstanding: one posted transfer from -> to, first
 *     expiry first out unless a batch is named (StockTransferService), that
 *     names the request. Never more than is outstanding per item;
 *   - cancel(): what was not sent will not be. The requester may cancel
 *     while nothing has been sent; stock.transfer any open request.
 *
 *   requested -> partly sent -> sent;  requested / partly sent -> cancelled
 *
 * Requests never move stock themselves; only their transfers do.
 */
class StockRequestService
{
    public const MAX_LINES = 200;

    public function __construct(
        private readonly StockTransferService $transfers,
        private readonly BatchService $batches,
        private readonly CurrentShop $currentShop,
    ) {}

    /**
     * @param  list<RequestLine>  $lines
     */
    public function create(Location $from, Location $to, array $lines, User $user, ?string $note = null): StockRequest
    {
        $shop = $this->assertModule();

        if (! $user->can('stock.request')) {
            throw new PosException('You do not have permission to request stock.');
        }

        $this->assertLocations($shop, $from, $to);

        if (! $user->mayUseLocation($to)) {
            throw new PosException("You can't request stock for {$to->name}.");
        }

        if ($lines === []) {
            throw new PosException('Add at least one item to the request.');
        }

        if (count($lines) > self::MAX_LINES) {
            throw new PosException('A request can have at most '.self::MAX_LINES.' items.');
        }

        $prepared = $this->prepare($lines);
        $note = blank($note) ? null : trim((string) $note);

        return DB::transaction(function () use ($shop, $from, $to, $prepared, $user, $note): StockRequest {
            // One number at a time per shop.
            Shop::query()->whereKey($shop->getKey())->lockForUpdate()->firstOrFail();

            $request = StockRequest::query()->create([
                'request_number' => 'RQ-'.str_pad((string) (StockRequest::query()->count() + 1), 6, '0', STR_PAD_LEFT),
                'from_location_id' => $from->getKey(),
                'to_location_id' => $to->getKey(),
                'status' => StockRequestStatus::Requested,
                'note' => $note,
                'requested_by' => $user->getKey(),
                'requested_at' => now(),
            ]);

            foreach ($prepared as $i => $line) {
                $request->items()->create([
                    'product_variant_id' => $line['variant']->getKey(),
                    'variant_unit_id' => $line['unit']->getKey(),
                    'unit_name' => $line['unit']->name,
                    'unit_factor' => (float) $line['unit']->factor,
                    'quantity' => $line['quantity'],
                    'base_quantity' => $line['base_quantity'],
                    'sent_base_quantity' => 0,
                    'sort_order' => $i,
                ]);
            }

            return $request->load('items');
        });
    }

    /**
     * Send what is outstanding, or part of it: one transfer from the
     * request's source to the location that asked, posted now. Lines with
     * nothing to send are skipped.
     *
     * @param  list<FulfilLine>  $lines
     */
    public function fulfil(StockRequest $request, array $lines, User $user, ?string $note = null): StockTransfer
    {
        $this->assertModule();

        if (! $user->can('stock.transfer')) {
            throw new PosException('You do not have permission to send stock.');
        }

        $lines = array_values(array_filter($lines, fn (FulfilLine $line): bool => Quantity::normalize($line->quantity) > 0));

        if ($lines === []) {
            throw new PosException('Enter how much to send of at least one item.');
        }

        return DB::transaction(function () use ($request, $lines, $user, $note): StockTransfer {
            $request = StockRequest::query()->with(['items.variant.product', 'items.variant.units', 'fromLocation', 'toLocation'])->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if (! $request->isOpen()) {
                throw new PosException("{$request->request_number} is {$request->status->label()}: there is nothing more to send.");
            }

            $items = $request->items->keyBy('id');
            $transferLines = [];
            $sent = [];

            foreach ($lines as $line) {
                /** @var StockRequestItem|null $item */
                $item = $items->get($line->itemId) ?? throw new PosException("Item #{$line->itemId} is not on {$request->request_number}.");
                $variant = $item->variant;
                $unitId = $line->unitId ?? $item->variant_unit_id;
                /** @var VariantUnit|null $unit */
                $unit = $unitId === null ? $variant->units->firstWhere('is_base', true) : $variant->units->firstWhere('id', $unitId);

                if ($unit === null || ! $unit->is_active) {
                    throw new PosException("That unit is not one of {$variant->sku}'s units.");
                }

                $base = $unit->toBase(Quantity::normalize($line->quantity));
                $outstanding = Quantity::normalize($item->outstandingBase() - ($sent[$item->getKey()] ?? 0));

                if ($base > $outstanding) {
                    throw new PosException(sprintf(
                        '%s: only %s %s %s still to send.',
                        $variant->sku,
                        Quantity::format($outstanding),
                        $variant->product->unit,
                        $outstanding == 1.0 ? 'is' : 'are',
                    ));
                }

                $sent[$item->getKey()] = Quantity::normalize(($sent[$item->getKey()] ?? 0) + $base);
                $transferLines[] = new TransferLine((int) $variant->getKey(), Quantity::normalize($line->quantity), (int) $unit->getKey(), $line->batchId);
            }

            $transfer = $this->transfers->create(
                $request->fromLocation,
                $request->toLocation,
                $transferLines,
                $user,
                blank($note) ? "For {$request->request_number}" : trim((string) $note),
                $request,
            );

            foreach ($sent as $itemId => $base) {
                /** @var StockRequestItem $item */
                $item = $items->get($itemId);
                $item->update(['sent_base_quantity' => Quantity::normalize((float) $item->sent_base_quantity + $base)]);
            }

            $complete = $request->items->every(fn (StockRequestItem $item): bool => $item->outstandingBase() <= 0);
            $request->update([
                'status' => $complete ? StockRequestStatus::Sent : StockRequestStatus::PartlySent,
                'closed_at' => $complete ? now() : null,
            ]);

            return $transfer;
        }, attempts: 3);
    }

    /** Nothing more will be sent. What was sent stays sent; its transfers stand. */
    public function cancel(StockRequest $request, User $user): StockRequest
    {
        $this->assertModule();

        return DB::transaction(function () use ($request, $user): StockRequest {
            $request = StockRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if (! $this->mayCancel($request, $user)) {
                throw new PosException($request->isOpen()
                    ? "You can't cancel {$request->request_number}: stock has already been sent for it."
                    : "{$request->request_number} is {$request->status->label()}.");
            }

            $request->update([
                'status' => StockRequestStatus::Cancelled,
                'cancelled_by' => $user->getKey(),
                'cancelled_at' => now(),
                'closed_at' => now(),
            ]);

            return $request;
        });
    }

    /** Who may cancel: stock.transfer any open request; the asking side while nothing has been sent. */
    public function mayCancel(StockRequest $request, User $user): bool
    {
        if (! $request->isOpen()) {
            return false;
        }

        if ($user->can('stock.transfer')) {
            return true;
        }

        return $request->status === StockRequestStatus::Requested
            && $user->can('stock.request')
            && $user->mayUseLocation((int) $request->to_location_id);
    }

    /** Whether the user may see a request: stock.transfer every one; stock.request those for their locations. */
    public function mayView(StockRequest $request, User $user): bool
    {
        return $user->can('stock.transfer')
            || ($user->can('stock.request') && $user->mayUseLocation((int) $request->to_location_id));
    }

    /**
     * The requests a user may see.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  a query on stock_requests
     * @return Builder<TModel>
     */
    public function visibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('stock.transfer')) {
            return $query;
        }

        if (! $user->can('stock.request')) {
            return $query->whereRaw('1 = 0');
        }

        $ids = $user->usableLocationIds();

        return $ids === null ? $query : $query->whereIn('to_location_id', $ids);
    }

    /** Requests still waiting for stock (requested or partly sent): the dashboard's and the menu's badge. */
    public function openCount(?User $user = null): int
    {
        $query = StockRequest::query()->whereIn('status', StockRequestStatus::openValues());

        return ($user === null ? $query : $this->visibleTo($query, $user))->count();
    }

    /**
     * What is still to send, item by item, with what the source holds and
     * the batches a transfer would take now (first expiry first out over the
     * active batches that have not expired, as StockService::transfer()
     * does), for the pick list. Read only: the transfer allocates again.
     *
     * @return list<array{item: StockRequestItem, outstanding: float, on_hand: float, batches: list<array{label: string, quantity: float}>, short: float}>
     */
    public function pickList(StockRequest $request): array
    {
        $request->loadMissing(['items.variant.product']);
        $today = $this->batches->today();
        $picks = [];

        foreach ($request->items as $item) {
            $outstanding = $item->outstandingBase();

            if ($outstanding <= 0) {
                continue;
            }

            $variant = $item->variant;
            $onHand = Quantity::normalize((float) (StockLevel::query()
                ->where('location_id', $request->from_location_id)
                ->where('product_variant_id', $variant->getKey())
                ->value('quantity') ?? 0));
            $batches = [];
            $left = $outstanding;

            if ($variant->product->tracksBatches()) {
                foreach (StockBatch::query()->where('location_id', $request->from_location_id)->where('product_variant_id', $variant->getKey())->sellableOn($today)->get() as $batch) {
                    if ($left <= 0) {
                        break;
                    }

                    $take = min($left, Quantity::normalize((float) $batch->quantity));
                    $batches[] = ['label' => $batch->label(), 'quantity' => $take];
                    $left = Quantity::normalize($left - $take);
                }
            } else {
                $left = Quantity::normalize(max(0.0, $outstanding - max(0.0, $onHand)));
            }

            $picks[] = ['item' => $item, 'outstanding' => $outstanding, 'on_hand' => $onHand, 'batches' => $batches, 'short' => $left];
        }

        return $picks;
    }

    /**
     * Each line's item and unit, and its quantity in base units. Any active
     * unit of a stocked item, whole units of it for a whole-units product;
     * each item once.
     *
     * @param  list<RequestLine>  $lines
     * @return list<array{variant: ProductVariant, unit: VariantUnit, quantity: float, base_quantity: float}>
     */
    private function prepare(array $lines): array
    {
        $variants = ProductVariant::query()
            ->with(['product', 'units'])
            ->whereKey(array_values(array_unique(array_map(fn (RequestLine $line): int => $line->variantId, $lines))))
            ->get()
            ->keyBy('id');

        $seen = [];
        $prepared = [];

        foreach ($lines as $line) {
            /** @var ProductVariant|null $variant */
            $variant = $variants->get($line->variantId) ?? throw new PosException("Item #{$line->variantId} was not found.");

            if (! $variant->product->track_stock) {
                throw new PosException("{$variant->sku} is a service; it has no stock to request.");
            }

            if (isset($seen[$variant->getKey()])) {
                throw new PosException("{$variant->sku} is on the request twice: enter it once.");
            }

            $seen[$variant->getKey()] = true;

            /** @var VariantUnit|null $unit */
            $unit = $line->unitId === null ? $variant->units->firstWhere('is_base', true) : $variant->units->firstWhere('id', $line->unitId);

            if ($unit === null || ! $unit->is_active) {
                throw new PosException("That unit is not one of {$variant->sku}'s units.");
            }

            $quantity = Quantity::normalize($line->quantity);

            if ($quantity <= 0) {
                throw new PosException("Enter how much of {$variant->sku} you need.");
            }

            app(StockService::class)->assertWholeUnits($variant, $quantity, $unit->name);

            $prepared[] = ['variant' => $variant, 'unit' => $unit, 'quantity' => $quantity, 'base_quantity' => $unit->toBase($quantity)];
        }

        return $prepared;
    }

    private function assertModule(): Shop
    {
        $shop = $this->currentShop->getOrFail();

        if (! $shop->hasModule('multi_location')) {
            throw new PosException('Stock requests need the multi_location module.');
        }

        return $shop;
    }

    private function assertLocations(Shop $shop, Location $from, Location $to): void
    {
        if ((int) $from->getKey() === (int) $to->getKey()) {
            throw new PosException('Ask another location: the stock comes from one location and goes to another.');
        }

        foreach ([$from, $to] as $location) {
            if ((int) $location->shop_id !== (int) $shop->getKey()) {
                throw new \LogicException('Locations must belong to the current shop.');
            }

            if (! $location->is_active) {
                throw new PosException("{$location->name} is not active.");
            }
        }
    }
}
