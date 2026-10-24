<?php

namespace App\Services;

use App\Enums\BillType;
use App\Enums\DrugSchedule;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Enums\TaxCategory;
use App\Exceptions\PosException;
use App\Models\Device;
use App\Models\Location;
use App\Models\PriceList;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\Shop;
use App\Models\StockLevel;
use App\Models\User;
use App\Models\VariantUnit;
use App\Observers\AuditObserver;
use App\Services\Data\BatchAllocation;
use App\Services\Data\BatchPick;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use App\Support\CurrentShop;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\UnitCost;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CheckoutService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly CurrentShop $currentShop,
        private readonly BatchPlanner $planner,
        private readonly BatchService $batches,
    ) {}

    public function checkout(CheckoutData $data): Sale
    {
        $shop = $this->currentShop->getOrFail();
        $review = $this->guard($shop, $data);

        // Idempotent: a till retrying (or syncing) the same sale gets the original back.
        if ($data->uuid !== null && ($existing = $this->existingSale($shop, $data)) !== null) {
            return $existing;
        }

        $tax = $this->taxFor($shop, $data, $review);
        $locations = $this->lineLocations($data->device, $data->cashier, $data->lines, $data->isOfflineSync, $review);
        [$priceList, $variants, $totals, $pricingReview] = $this->price(
            $shop, $data->lines, $data->saleDiscount, $data->isOfflineSync, $tax, $locations,
            cashier: $data->cashier, device: $data->device, billType: $data->billType, medicinesAmount: $data->opdMedicinesAmount,
        );
        $this->checkBatchPicks($data->lines, $data->cashier, $data->isOfflineSync, $variants);
        $review = [...$review, ...$pricingReview];

        if ($data->isOfflineSync) {
            // An offline sale has already happened at the till (which enforces the same limit),
            // so the server records it at the prices charged rather than losing it.
            $review = [
                ...$review,
                ...$this->unknownUnits($data, $variants),
                ...$this->unitsNotSoldHere($data, $variants, $locations),
                ...$this->priceDifferences($shop, $data, $variants, $priceList, $totals),
                ...$this->partUnits($variants, $totals['lines']),
            ];
        } else {
            $this->checkDiscountLimit($shop, $data->cashier, $totals['subtotal'], $totals['discount_total']);
        }

        $review = [...$review, ...$this->checkPrescriptions($shop, $data, $totals['lines'])];

        [$paidTotal, $changeDue, $paymentIssues] = $this->checkPayments($data->payments, $totals['grand_total'], $data->isOfflineSync, $shop);
        $review = [...$review, ...$paymentIssues];

        $syncedAt = $data->isOfflineSync ? now() : null;
        [$clockOffset, $reportedAt, $storedSoldAt] = $this->tillClock($shop, $data, $syncedAt, $review);

        // attempts: 3 retries the whole checkout if MySQL resolves a deadlock between two tills.
        return DB::transaction(function () use ($shop, $data, $priceList, $variants, $totals, $tax, $paidTotal, $changeDue, $review, $locations, $syncedAt, $clockOffset, $reportedAt, $storedSoldAt): Sale {
            $shift = $this->openShiftFor($shop, $data, $review);
            $invoiceNo = $this->invoiceNumberFor($shop, $data, $review);
            $isOpd = $data->billType === BillType::Opd;
            $listValue = $isOpd ? self::medicinesListValue($totals['lines']) : null;

            $sale = Sale::query()->create([
                'uuid' => $data->uuid,
                'location_id' => $data->device->location_id,
                'device_id' => $data->device->getKey(),
                'shift_id' => $shift?->getKey(),
                'user_id' => $data->cashier->getKey(),
                'price_list_id' => $priceList->getKey(),
                'invoice_no' => $invoiceNo,
                'status' => SaleStatus::Completed,
                'bill_type' => $data->billType,
                'subtotal' => $totals['subtotal'],
                'discount_total' => $totals['discount_total'],
                'tax_total' => $totals['tax_total'],
                'grand_total' => $totals['grand_total'],
                'opd_medicines_amount' => $isOpd ? $this->medicinesAmount($data->opdMedicinesAmount) : null,
                'opd_medicines_list_value' => $listValue,
                'opd_below_list' => $listValue !== null && self::belowList($shop, $this->medicinesAmount($data->opdMedicinesAmount), $listValue),
                'paid_total' => $paidTotal,
                'change_due' => $changeDue,
                'tax_rate' => $tax['rate'],
                'prices_include_tax' => $tax['inclusive'],
                'customer_name' => $data->customerName,
                'customer_phone' => $data->customerPhone,
                'notes' => $data->notes,
                // As the till's clock said (and the receipt printed), unless no column can hold it.
                'sold_at' => $storedSoldAt ?? $data->soldAt ?? now(),
                'synced_at' => $syncedAt,
                // Null: the sale's own time (Sale::booted()); set when an offline till's clock was out.
                'reported_at' => $reportedAt,
                'clock_offset_seconds' => $clockOffset,
                'needs_review' => $review !== [],
                'review_reasons' => $review === [] ? null : $review,
            ]);

            $items = $sale->items()->createMany($totals['lines'])->values();

            foreach ($data->payments as $payment) {
                $sale->payments()->create([
                    'method' => $payment->method,
                    'amount' => $payment->amount,
                    'reference' => $payment->reference,
                ]);
            }

            $this->auditPriceOverrides($sale, $data, $items);

            $allowNegative = $data->isOfflineSync || (bool) $shop->setting('allow_negative_stock');
            $locationsById = collect($locations)->keyBy(fn (Location $location): int => (int) $location->getKey());

            $batchReview = [];

            // Lock stock rows in a fixed order (by location, then variant) so two tills never
            // deadlock. A box and loose tablets of one item are two movements under the same lock,
            // and the second line's batches are allocated after the first's.
            $stockLines = collect($totals['lines'])->sortBy([['location_id', 'asc'], ['product_variant_id', 'asc']]);

            foreach ($stockLines as $i => $line) {
                /** @var ProductVariant $variant */
                $variant = $variants->get($line['product_variant_id']);
                /** @var Location $location */
                $location = $locationsById->get($line['location_id']);

                if (! $variant->product->track_stock) {
                    continue;
                }

                if (! $variant->product->tracksBatches()) {
                    // Stock moves in base units from the line's location, at that location's average cost.
                    $this->stock->record(
                        $variant,
                        $location,
                        StockMovementType::Sale,
                        $line['base_quantity'],
                        $data->cashier,
                        reference: $sale,
                        deviceId: $data->device->getKey(),
                        allowNegative: $allowNegative,
                        occurredAt: $sale->sold_at,
                        allowPartUnits: $data->isOfflineSync,
                    );

                    continue;
                }

                // First expiry first out from the line's location (docs/PHARMACY.md §6.2): a
                // pharmacist's pick must be usable; an offline till's proposal is honoured where
                // it still is, and the rest re-allocated. Offline, what the batches can't cover
                // goes to the unallocated batch; the sale already happened.
                $proposal = $data->lines[$i]->batches;
                $allocation = $this->stock->issue(
                    $variant,
                    $location,
                    StockMovementType::Sale,
                    $line['base_quantity'],
                    $data->cashier,
                    reference: $sale,
                    proposal: $proposal,
                    strictProposal: ! $data->isOfflineSync,
                    allowNegative: $allowNegative,
                    occurredAt: $sale->sold_at,
                    deviceId: $data->device->getKey(),
                    allowPartUnits: $data->isOfflineSync,
                );

                /** @var SaleItem $item */
                $item = $items[$i];
                $this->recordBatches($item, $allocation);
                $batchReview = [...$batchReview, ...$this->batchReview($variant, $location, $item, $proposal, $allocation)];
            }

            if ($batchReview !== []) {
                $sale->update(['needs_review' => true, 'review_reasons' => [...($sale->review_reasons ?? []), ...$batchReview]]);
            }

            return $sale->load(['items', 'payments']);
        }, attempts: 3);
    }

    /**
     * What a cart would cost right now, using exactly the maths checkout uses
     * (server price list, line and sale discounts, tax, an OPD bill's
     * Medicines amount) and the same cashier rules. Tills show these figures
     * and never calculate their own. With the till, units are checked at its
     * location and each batch-tracked line shows the batches first expiry
     * first out would take today (BatchPlanner). Cost prices are deliberately
     * left out so the result is safe to return to a cashier. So are an OPD
     * bill's per-line medicine shares: an unpriced medicine weighs by its
     * cost, and its share next to a priced line's would give that cost away.
     * Its medicine lines carry their unit and list price, with line_total,
     * tax_amount and sale_discount_share null; the bill's medicines_total and
     * medicines_tax are the sums over them (null on a standard bill), with
     * medicines_list_value (the priced medicines at list price, never a cost)
     * and medicines_below_list (the typed amount is below the shop's warning
     * share of it: a warning, never a refusal).
     *
     * @param  list<CartLine>  $lines
     * @return array{subtotal:int, discount_total:int, tax_total:int, grand_total:int, medicines_total:int|null, medicines_tax:int|null, medicines_list_value:int|null, medicines_below_list:bool|null, lines:list<array<string, mixed>>}
     *
     * @throws PosException
     */
    public function quote(array $lines, int $saleDiscount, User $cashier, ?Device $device = null, BillType $billType = BillType::Standard, ?int $medicinesAmount = null): array
    {
        if ($lines === []) {
            throw new PosException('The cart is empty.');
        }

        if ($saleDiscount < 0) {
            throw new PosException('Discount cannot be negative.');
        }

        $shop = $this->currentShop->getOrFail();
        $review = [];
        $locations = $device === null ? null : $this->lineLocations($device, $cashier, $lines, false, $review);
        [, $variants, $totals] = $this->price(
            $shop, $lines, $saleDiscount, false, $this->taxFor($shop), $locations,
            cashier: $cashier, device: $device, billType: $billType, medicinesAmount: $medicinesAmount, isQuote: true,
        );
        $this->checkBatchPicks($lines, $cashier, false, $variants);

        $this->checkDiscountLimit($shop, $cashier, $totals['subtotal'], $totals['discount_total']);

        $plans = $locations === null ? [] : $this->batchPreview($lines, $variants, $locations, $totals['lines'], ! $shop->setting('allow_negative_stock'));

        $isOpd = $billType === BillType::Opd;
        $medicines = array_filter($totals['lines'], fn (array $line): bool => (bool) $line['is_opd_medicine']);

        $totals['medicines_total'] = $isOpd ? (int) array_sum(array_column($medicines, 'line_total')) : null;
        $totals['medicines_tax'] = $isOpd ? (int) array_sum(array_column($medicines, 'tax_amount')) : null;
        $totals['medicines_list_value'] = $isOpd ? self::medicinesListValue($totals['lines']) : null;
        $totals['medicines_below_list'] = $isOpd ? self::belowList($shop, $medicinesAmount, $totals['medicines_list_value']) : null;
        $totals['lines'] = array_map(fn (array $line, int $i): array => [
            ...Arr::except($line, ['unit_cost']),
            // A medicine's share stays on the server (see above): only the bill's Medicines figures go out.
            ...($line['is_opd_medicine'] ? ['line_total' => null, 'tax_amount' => null, 'sale_discount_share' => null] : []),
            'batches' => $plans[$i]['batches'] ?? [],
            'shortfall' => $plans[$i]['shortfall'] ?? 0.0,
        ], $totals['lines'], array_keys($totals['lines']));

        return $totals;
    }

    /**
     * The batches each batch-tracked line would take today at its location,
     * in the order first expiry first out takes them (a pick first, refused
     * as checkout refuses it), and what the sale could not cover. Read only:
     * the sale itself allocates again, under lock.
     *
     * @param  list<CartLine>  $lines
     * @param  Collection<int, ProductVariant>  $variants
     * @param  array<int, Location>  $locations
     * @param  list<array<string, mixed>>  $priced  the lines as calculate() priced them
     * @param  bool  $capAtLevel  no negative stock: what the stock level can't cover is shortfall too
     * @return array<int, array{batches: list<array{batch_id:int, batch_no:string, expiry:string|null, quantity:float}>, shortfall: float}> keyed like $lines
     *
     * @throws PosException a batch pick checkout would refuse
     */
    private function batchPreview(array $lines, Collection $variants, array $locations, array $priced, bool $capAtLevel): array
    {
        $byLocation = [];

        foreach ($lines as $i => $line) {
            /** @var ProductVariant $variant */
            $variant = $variants->get($line->variantId);

            if ($variant->product->tracksBatches()) {
                $byLocation[(int) $locations[$i]->getKey()][$i] = $line;
            }
        }

        $plans = [];
        $today = $this->batches->today();

        foreach ($byLocation as $locationLines) {
            $planLines = [];

            foreach ($locationLines as $i => $line) {
                /** @var ProductVariant $variant */
                $variant = $variants->get($line->variantId);
                $planLines[] = ['variant' => $variant, 'base_quantity' => (float) $priced[$i]['base_quantity'], 'picks' => $line->batches];
            }

            $planned = $this->planner->plan($locations[array_key_first($locationLines)], $planLines, $today, $capAtLevel);

            foreach (array_keys($locationLines) as $n => $i) {
                $plans[$i] = $planned[$n];
            }
        }

        return $plans;
    }

    /**
     * How a set of payments settles a total, without recording anything.
     * `error` holds the same message checkout would refuse the sale with.
     *
     * @param  list<PaymentInput>  $payments
     * @return array{paid_total:int, change_due:int, short_by:int, error:string|null}
     */
    public function previewPayments(array $payments, int $grandTotal): array
    {
        $paid = array_sum(array_map(fn (PaymentInput $payment): int => $payment->amount, $payments));

        try {
            $this->checkPayments($payments, $grandTotal, false, $this->currentShop->get());
            $error = null;
        } catch (PosException $exception) {
            $error = $exception->getMessage();
        }

        return [
            'paid_total' => $paid,
            'change_due' => max(0, $paid - $grandTotal),
            'short_by' => max(0, $grandTotal - $paid),
            'error' => $error,
        ];
    }

    /**
     * Loads the items, checks what the cart asks of the price list (typed
     * prices, an OPD bill) and prices every line. Online a problem refuses
     * the sale; offline it comes back as review reasons.
     *
     * @param  list<CartLine>  $lines
     * @param  array{rate: float, inclusive: bool}  $tax
     * @param  array<int, Location>|null  $locations  where each line's stock comes from; null for a quote without a till
     * @return array{0: PriceList, 1: Collection<int, ProductVariant>, 2: array{subtotal:int, discount_total:int, tax_total:int, grand_total:int, lines:list<array<string, mixed>>}, 3: list<array<string, mixed>>}
     */
    private function price(
        Shop $shop,
        array $lines,
        int $saleDiscount,
        bool $isOfflineSync,
        array $tax,
        ?array $locations,
        User $cashier,
        ?Device $device,
        BillType $billType = BillType::Standard,
        ?int $medicinesAmount = null,
        bool $isQuote = false,
    ): array {
        $priceList = $shop->defaultPriceList();
        $variants = $this->loadVariants($lines, $priceList, $isOfflineSync);
        $baseCosts = $locations === null ? [] : $this->baseCosts($lines, $variants, $locations);

        $review = [
            ...$this->checkPriceOverrides($shop, $lines, $variants, $cashier, $isOfflineSync, $billType),
            ...$this->checkOpdBill($lines, $variants, $saleDiscount, $cashier, $device, $billType, $medicinesAmount, $isOfflineSync, $isQuote),
        ];

        $totals = $this->calculate(
            $lines, $saleDiscount, $variants, $priceList, $tax, $isOfflineSync, $locations, $baseCosts,
            billType: $billType, medicinesAmount: $this->medicinesAmount($medicinesAmount),
        );

        return [$priceList, $variants, $totals, $review];
    }

    /**
     * An OPD bill's medicines at list price (decided 2026-10-22): Σ list
     * price × quantity of its medicine lines that have a price on the list.
     * An unpriced medicine adds nothing: it is weighed by its cost, which
     * never reaches the till. Mirrored in pricing.ts.
     *
     * @param  list<array<string, mixed>>  $lines  calculate()'s lines
     */
    public static function medicinesListValue(array $lines): int
    {
        $value = 0;

        foreach ($lines as $line) {
            if ($line['is_opd_medicine'] && $line['list_price'] !== null) {
                $value += Money::multiply((int) $line['list_price'], (float) $line['quantity']);
            }
        }

        return $value;
    }

    /**
     * The Medicines amount is below the shop's warning share of the list
     * value (opd_below_list_warning_percent; 0 = never). Nothing typed yet,
     * or nothing priced, is no warning. Whole numbers, as in pricing.ts.
     */
    public static function belowList(Shop $shop, ?int $medicinesAmount, int $listValue): bool
    {
        $percent = max(0, min(100, (int) $shop->setting('opd_below_list_warning_percent', 100)));

        return $percent > 0 && $medicinesAmount !== null && $listValue > 0 && $medicinesAmount * 100 < $percent * $listValue;
    }

    /** The Medicines amount an OPD bill spreads over its medicine lines: none typed is nothing. */
    private function medicinesAmount(?int $amount): int
    {
        return max(0, $amount ?? 0);
    }

    /**
     * Where each line's stock comes from: its own location, or the till's.
     * Online, a line's own location must be an active, sellable location of
     * the shop, and the cashier must be allowed to sell at the till's
     * location and every line's (users' allowed locations, docs/PHARMACY.md
     * §4.4). An offline sale already happened, so a location that is unknown
     * or not for sale falls back to the till's, and either problem is flagged.
     *
     * @param  list<CartLine>  $lines
     * @param  list<array<string, mixed>>  $review
     * @return array<int, Location> keyed like $lines
     */
    private function lineLocations(Device $device, User $cashier, array $lines, bool $isOfflineSync, array &$review): array
    {
        $till = $device->location()->firstOrFail();
        $ids = array_values(array_unique(array_filter(array_map(fn (CartLine $line): ?int => $line->locationId, $lines), fn (?int $id): bool => $id !== null)));
        $found = Location::query()->whereKey($ids)->get()->keyBy('id');
        $locations = [];
        $flagged = false;

        if (! $isOfflineSync && ! $cashier->mayUseLocation($till)) {
            throw new PosException("You can't sell at {$till->name}.");
        }

        foreach ($lines as $i => $line) {
            /** @var Location|null $location */
            $location = $line->locationId === null ? $till : $found->get($line->locationId);

            if ($location !== null && ($location->is($till) || ($location->is_active && $location->is_sellable))) {
                if (! $isOfflineSync && ! $cashier->mayUseLocation($location)) {
                    throw new PosException("You can't sell at {$location->name}.");
                }

                $locations[$i] = $location;

                continue;
            }

            if (! $isOfflineSync) {
                throw new PosException($location === null ? 'That location was not found.' : "Nothing can be sold from {$location->name}.");
            }

            $locations[$i] = $till;

            if (! $flagged) {
                $review[] = ['type' => 'location', 'message' => "A line was sold from a location that is unknown or not for sale; its stock was taken from {$till->name}."];
                $flagged = true;
            }
        }

        // The sale already happened: one reason naming every location the cashier may not use.
        $notAllowed = collect([$till, ...$locations])
            ->unique(fn (Location $location): int => (int) $location->getKey())
            ->reject(fn (Location $location): bool => $cashier->mayUseLocation($location));

        if ($isOfflineSync && $notAllowed->isNotEmpty()) {
            $review[] = [
                'type' => 'location',
                'message' => "{$cashier->name} is not allowed to sell at {$notAllowed->pluck('name')->join(', ', ' and ')}.",
            ];
        }

        return $locations;
    }

    /**
     * Each line's average cost per base unit at its location (DECIMAL(20,4)).
     * A location that never held the item uses the item's blended cost.
     *
     * @param  list<CartLine>  $lines
     * @param  Collection<int, ProductVariant>  $variants
     * @param  array<int, Location>  $locations
     * @return array<int, string> keyed like $lines
     */
    private function baseCosts(array $lines, Collection $variants, array $locations): array
    {
        $levels = StockLevel::query()
            ->whereIn('product_variant_id', $variants->keys()->all())
            ->whereIn('location_id', array_values(array_unique(array_map(fn (Location $location): int => (int) $location->getKey(), $locations))))
            ->get(['location_id', 'product_variant_id', 'average_cost'])
            ->keyBy(fn (StockLevel $level): string => "{$level->location_id}:{$level->product_variant_id}");

        $costs = [];

        foreach ($lines as $i => $line) {
            /** @var ProductVariant $variant */
            $variant = $variants->get($line->variantId);
            $level = $levels->get("{$locations[$i]->getKey()}:{$line->variantId}");
            $costs[$i] = UnitCost::normalize($level->average_cost ?? (int) $variant->cost_price);
        }

        return $costs;
    }

    /**
     * The tax rule for a sale: the shop's current one, or for an offline sale the
     * one the till used (from its last catalog sync), so the totals match what was paid.
     *
     * @param  list<array<string, mixed>>  $review
     * @return array{rate: float, inclusive: bool}
     */
    private function taxFor(Shop $shop, ?CheckoutData $data = null, array &$review = []): array
    {
        $current = [
            'rate' => $shop->tax_enabled ? (float) $shop->tax_rate : 0.0,
            'inclusive' => (bool) $shop->prices_include_tax,
        ];

        if ($data === null || ! $data->isOfflineSync || $data->tillTaxRate === null) {
            return $current;
        }

        $till = [
            'rate' => round($data->tillTaxRate, 2),
            'inclusive' => $data->tillPricesIncludeTax ?? $current['inclusive'],
        ];

        if (abs($till['rate'] - $current['rate']) > 0.001 || $till['inclusive'] !== $current['inclusive']) {
            $review[] = [
                'type' => 'tax',
                'message' => sprintf(
                    'The till charged tax at %s%%%s; the shop setting is now %s%%%s.',
                    $till['rate'], $till['inclusive'] ? ' (included)' : '',
                    $current['rate'], $current['inclusive'] ? ' (included)' : '',
                ),
            ];
        }

        return $till;
    }

    /**
     * An offline till sold at the prices in its catalog copy. When the server's
     * price list differs, the sale keeps what the customer paid and the
     * difference is listed for the owner. A price the till typed (a price
     * override) and an OPD bill's medicine lines (priced by the Medicines
     * amount) are not price-list prices, so they are never listed here.
     *
     * @param  Collection<int, ProductVariant>  $variants
     * @param  array{grand_total:int, lines:list<array<string, mixed>>}  $totals
     * @return list<array<string, mixed>>
     */
    private function priceDifferences(Shop $shop, CheckoutData $data, Collection $variants, PriceList $priceList, array $totals): array
    {
        $differences = [];
        $grandTotal = $totals['grand_total'];

        foreach ($data->lines as $i => $line) {
            /** @var ProductVariant $variant */
            $variant = $variants->get($line->variantId);
            $serverPrice = $this->unitFor($variant, $line->unitId, true)->priceOn($priceList);

            if ($line->tillUnitPrice === null || $line->tillUnitPrice === $serverPrice || $line->tillPriceOverride || $totals['lines'][$i]['is_opd_medicine']) {
                continue;
            }

            $differences[] = [
                'type' => 'price',
                'sku' => $variant->sku,
                'till_price' => $line->tillUnitPrice,
                'server_price' => $serverPrice,
                'message' => sprintf(
                    '%s sold at %s; the price list says %s.',
                    $variant->sku,
                    $shop->formatMoney($line->tillUnitPrice),
                    $serverPrice === null ? 'no price' : $shop->formatMoney($serverPrice),
                ),
            ];
        }

        if ($data->tillGrandTotal !== null && $data->tillGrandTotal !== $grandTotal) {
            $differences[] = [
                'type' => 'total',
                'till_total' => $data->tillGrandTotal,
                'server_total' => $grandTotal,
                'message' => sprintf(
                    'The till showed a total of %s; the same items work out at %s.',
                    $shop->formatMoney($data->tillGrandTotal),
                    $shop->formatMoney($grandTotal),
                ),
            ];
        }

        return $differences;
    }

    /**
     * An offline till sold in a unit the server does not know for the item
     * (deleted, or another item's): the line was recorded in the base unit at
     * the till's price, and listed for the owner.
     *
     * @param  Collection<int, ProductVariant>  $variants
     * @return list<array<string, mixed>>
     */
    private function unknownUnits(CheckoutData $data, Collection $variants): array
    {
        $issues = [];

        foreach ($data->lines as $line) {
            /** @var ProductVariant $variant */
            $variant = $variants->get($line->variantId);

            if ($line->unitId !== null && $variant->units->firstWhere('id', $line->unitId) === null) {
                $issues[] = [
                    'type' => 'unit',
                    'sku' => $variant->sku,
                    'message' => "{$variant->sku} was sold in a unit this item does not have; it was recorded in {$variant->product->unit}.",
                ];
            }
        }

        return $issues;
    }

    /**
     * An offline till sold in a unit that is not sold at the line's location
     * (loose ml of a syrup at a counter that sells bottles only): recorded as
     * sold and listed for the owner. Only for lines that name their unit:
     * a sale from an older till (no unit) is replayed as before.
     *
     * @param  Collection<int, ProductVariant>  $variants
     * @param  array<int, Location>  $locations
     * @return list<array<string, mixed>>
     */
    private function unitsNotSoldHere(CheckoutData $data, Collection $variants, array $locations): array
    {
        $issues = [];

        foreach ($data->lines as $i => $line) {
            /** @var ProductVariant $variant */
            $variant = $variants->get($line->variantId);
            /** @var VariantUnit|null $unit */
            $unit = $variant->units->firstWhere('id', $line->unitId);

            // An unknown unit is listed by unknownUnits().
            if ($line->unitId === null || $unit === null || $variant->canSellUnitAt($unit, $locations[$i])) {
                continue;
            }

            $issues[] = [
                'type' => 'unit',
                'sku' => $variant->sku,
                'message' => "{$variant->sku} was sold by the {$unit->name} at {$locations[$i]->name}, where it is not sold loose.",
            ];
        }

        return $issues;
    }

    /**
     * A part unit sold offline (half a tablet, half a box): the till should
     * have refused it, but the sale happened, so it is recorded and listed
     * for the owner.
     *
     * @param  Collection<int, ProductVariant>  $variants
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function partUnits(Collection $variants, array $lines): array
    {
        $issues = [];

        foreach ($lines as $line) {
            /** @var ProductVariant $variant */
            $variant = $variants->get($line['product_variant_id']);

            if ($variant->product->whole_units_only && (! Quantity::isWhole($line['quantity']) || ! Quantity::isWhole($line['base_quantity']))) {
                $issues[] = [
                    'type' => 'quantity',
                    'sku' => $variant->sku,
                    'message' => sprintf(
                        '%s was sold as %s %s; it is sold in whole units only.',
                        $variant->sku,
                        Quantity::format($line['quantity']),
                        $line['unit_name'],
                    ),
                ];
            }
        }

        return $issues;
    }

    /**
     * Choosing a batch instead of first expiry first out is for whoever may
     * (pos.batch_override), and only on items that track batches. An offline
     * till's proposal is not a choice: it is its own FEFO, checked on replay.
     *
     * @param  list<CartLine>  $lines
     * @param  Collection<int, ProductVariant>  $variants
     */
    private function checkBatchPicks(array $lines, User $cashier, bool $isOfflineSync, Collection $variants): void
    {
        if ($isOfflineSync) {
            return;
        }

        foreach ($lines as $line) {
            if ($line->batches === null || $line->batches === []) {
                continue;
            }

            /** @var ProductVariant $variant */
            $variant = $variants->get($line->variantId);

            if (! $variant->product->tracksBatches()) {
                throw new PosException("{$variant->sku} does not track batches.");
            }

            if (! $cashier->can('pos.batch_override')) {
                throw new PosException('You are not allowed to choose a batch. Leave it to first expiry, first out.');
            }
        }
    }

    /** One sale_item_batches row per batch taken, with the batch number and expiry as sold. */
    private function recordBatches(SaleItem $item, BatchAllocation $allocation): void
    {
        foreach ($allocation->pieces as ['batch' => $batch, 'quantity' => $quantity, 'movement' => $movement]) {
            $item->batches()->create([
                'stock_batch_id' => $batch->getKey(),
                'location_id' => $batch->location_id,
                'stock_movement_id' => $movement->getKey(),
                'quantity' => $quantity,
                'batch_no' => $batch->batch_no,
                'expiry_date' => $batch->expiry_date?->toDateString(),
                'is_unallocated' => $batch->is_unallocated,
                'batch_unit_cost' => $batch->unit_cost,
            ]);
        }
    }

    /**
     * Review reasons from a line's batches (docs/PHARMACY.md §7.2): the
     * till's proposal was changed (`batch`, the proposal kept on the line),
     * it named a batch that had expired by the sale date (`expired_sold`),
     * or the batches could not cover the line (`batch_shortfall`).
     *
     * @param  list<BatchPick>|null  $proposal
     * @return list<array<string, mixed>>
     */
    private function batchReview(ProductVariant $variant, Location $location, SaleItem $item, ?array $proposal, BatchAllocation $allocation): array
    {
        $reasons = [];
        $unit = $variant->product->unit;
        $recorded = implode(', ', array_map(
            fn (array $piece): string => $piece['batch']->label().' x '.Quantity::format($piece['quantity']),
            $allocation->pieces,
        ));

        if ($proposal !== null && $allocation->proposalChanged()) {
            $item->update(['till_batches' => BatchPick::toArray($proposal)]);

            $reasons[] = [
                'type' => 'batch',
                'sku' => $variant->sku,
                'message' => "{$variant->sku}: the till's batches were changed. ".implode(' ', $allocation->differences)." Recorded as {$recorded}.",
            ];
        }

        if ($allocation->expiredProposed) {
            $reasons[] = [
                'type' => 'expired_sold',
                'sku' => $variant->sku,
                'message' => "{$variant->sku} was sold from a batch that had expired by the sale date. Check what was handed over.",
            ];
        }

        if ($allocation->shortfall > 0) {
            $reasons[] = [
                'type' => 'batch_shortfall',
                'sku' => $variant->sku,
                'quantity' => $allocation->shortfall,
                'message' => sprintf(
                    '%s: %s %s sold at %s beyond the recorded batches. Booked as unallocated stock until the next delivery settles it.',
                    $variant->sku,
                    Quantity::format($allocation->shortfall),
                    $unit,
                    $location->name,
                ),
            ];
        }

        return $reasons;
    }

    /** The already recorded sale with this uuid, or null. A uuid used by another till or shop is refused. */
    private function existingSale(Shop $shop, CheckoutData $data): ?Sale
    {
        $existing = Sale::query()->withoutGlobalScope('shop')->where('uuid', $data->uuid)->first();

        if ($existing === null) {
            return null;
        }

        if ((int) $existing->shop_id !== (int) $shop->getKey() || (int) $existing->device_id !== (int) $data->device->getKey()) {
            throw new PosException('This sale id belongs to another till.');
        }

        return $existing->load(['items', 'payments']);
    }

    /** Cancel a whole sale and put all its stock back. Owner only by default. */
    public function void(Sale $sale, User $user, string $reason): Sale
    {
        if (! $user->can('sales.void')) {
            throw new PosException('You do not have permission to void sales.');
        }

        if (blank($reason)) {
            throw new PosException('A reason is required to void a sale.');
        }

        return DB::transaction(function () use ($sale, $user, $reason): Sale {
            /** @var Sale $sale */
            $sale = Sale::query()->whereKey($sale->getKey())->lockForUpdate()->firstOrFail();

            if ($sale->status !== SaleStatus::Completed) {
                throw new PosException("Only completed sales without returns can be voided (this sale is {$sale->status->value}).");
            }

            $sale->load(['items.variant.product', 'items.location', 'items.batches']);

            foreach ($sale->items->sortBy([['location_id', 'asc'], ['product_variant_id', 'asc']]) as $item) {
                if (! $item->variant->product->track_stock) {
                    continue;
                }

                // Exactly what the sale took, back in base units to the line's location,
                // at the line's cost per base unit: into the very batches it took.
                if ($item->variant->product->tracksBatches()) {
                    if ($item->batches->isEmpty()) {
                        $this->stock->restoreWithoutBatch($item->variant, $item->location, StockMovementType::SaleVoid, (float) $item->base_quantity, $user, $sale, $item->unitCostPerBase(), note: $reason);
                    }

                    foreach ($item->batches as $piece) {
                        if ($piece->remaining() > 0) {
                            $this->stock->restore($piece, StockMovementType::SaleVoid, $piece->remaining(), $user, $sale, $item->unitCostPerBase(), note: $reason);
                        }
                    }

                    continue;
                }

                $this->stock->record(
                    $item->variant,
                    $item->location,
                    StockMovementType::SaleVoid,
                    (float) $item->base_quantity,
                    $user,
                    reference: $sale,
                    unitCost: $item->unitCostPerBase(),
                    note: $reason,
                    allowPartUnits: true,
                );
            }

            $sale->update([
                'status' => SaleStatus::Voided,
                'voided_by' => $user->getKey(),
                'voided_at' => now(),
                'void_reason' => $reason,
            ]);

            return $sale;
        });
    }

    /**
     * @return list<array<string, mixed>> reasons an offline sale needs the owner's review
     */
    private function guard(Shop $shop, CheckoutData $data): array
    {
        if ($data->lines === []) {
            throw new PosException('The cart is empty.');
        }

        if ($data->payments === []) {
            throw new PosException('Add at least one payment.');
        }

        if ((int) $data->device->shop_id !== (int) $shop->getKey() || ! ($data->device->is_active ?? true)) {
            throw new PosException('This till is not registered or has been disabled.');
        }

        if ($data->saleDiscount < 0) {
            throw new PosException('Discount cannot be negative.');
        }

        if ((int) $data->cashier->getAttribute('shop_id') !== (int) $shop->getKey()) {
            throw new PosException('You are not allowed to make sales.');
        }

        $mayCheckout = ($data->cashier->getAttribute('is_active') ?? true) && $data->cashier->can('pos.checkout');

        if ($mayCheckout) {
            return [];
        }

        if (! $data->isOfflineSync) {
            throw new PosException('You are not allowed to make sales.');
        }

        // The sale already happened at the till; the owner decides what to do about it.
        return [[
            'type' => 'cashier',
            'message' => "{$data->cashier->name} is disabled or no longer allowed to make sales.",
        ]];
    }

    /**
     * A typed price (docs/PHARMACY.md §4.3) is only for a product that allows
     * it and holds no stock (the consultation fee), only for whoever may
     * (pos.price_override), and above zero. Online anything else is refused;
     * offline the till's price is kept and the line listed for the owner.
     *
     * @param  list<CartLine>  $lines
     * @param  Collection<int, ProductVariant>  $variants
     * @return list<array<string, mixed>> offline review reasons
     */
    private function checkPriceOverrides(Shop $shop, array $lines, Collection $variants, User $cashier, bool $isOfflineSync, BillType $billType): array
    {
        $review = [];

        foreach ($lines as $line) {
            $typed = $this->typedPrice($line, $isOfflineSync);
            /** @var ProductVariant $variant */
            $variant = $variants->get($line->variantId);

            // An OPD bill's medicine lines are priced by the Medicines amount, whatever the till sent.
            if ($typed === null || ($billType === BillType::Opd && $variant->product->track_stock && $isOfflineSync)) {
                continue;
            }

            [$refusal, $flag] = match (true) {
                ! $variant->product->allowsPriceOverride() => [
                    "{$variant->sku}'s price can't be changed at the till.",
                    "{$variant->sku} was sold at a typed price of {$shop->formatMoney($typed)}, but its price can't be changed at the till.",
                ],
                ! $cashier->can('pos.price_override') => [
                    'You are not allowed to change prices.',
                    "{$cashier->name} typed {$variant->sku}'s price ({$shop->formatMoney($typed)}) but is not allowed to change prices.",
                ],
                $typed <= 0 => [
                    "The price of {$variant->sku} must be more than zero.",
                    "{$variant->sku} was sold at a typed price of {$shop->formatMoney($typed)}.",
                ],
                default => [null, null],
            };

            if ($refusal === null) {
                continue;
            }

            if (! $isOfflineSync) {
                throw new PosException($refusal);
            }

            $review[] = ['type' => 'price_override', 'sku' => $variant->sku, 'message' => $flag];
        }

        return $review;
    }

    /**
     * An OPD bill (docs/PHARMACY.md §9, branch 8): rung up on an OPD till by
     * whoever may (pos.opd_bill), without discounts, with a Medicines amount
     * when medicines were dispensed and medicines when there is an amount.
     * Online the first problem refuses the bill; offline each is flagged.
     * A quote takes a missing amount as nothing typed yet.
     *
     * @param  list<CartLine>  $lines
     * @param  Collection<int, ProductVariant>  $variants
     * @return list<array<string, mixed>> offline review reasons
     */
    private function checkOpdBill(array $lines, Collection $variants, int $saleDiscount, User $cashier, ?Device $device, BillType $billType, ?int $medicinesAmount, bool $isOfflineSync, bool $isQuote): array
    {
        if ($billType !== BillType::Opd) {
            return [];
        }

        $hasMedicines = collect($lines)->contains(fn (CartLine $line): bool => (bool) $variants->get($line->variantId)?->product->track_stock);
        $discounted = $saleDiscount > 0 || collect($lines)->contains(fn (CartLine $line): bool => $line->lineDiscount > 0);

        $problems = array_values(array_filter([
            $device !== null && ! $device->opd_billing ? ['This till does not take OPD bills.', "Till {$device->code} does not take OPD bills."] : null,
            $cashier->can('pos.opd_bill') ? null : ['You are not allowed to ring up OPD bills.', "{$cashier->name} is not allowed to ring up OPD bills."],
            $discounted ? ['OPD bills take no discounts.', 'This OPD bill was given a discount; OPD bills take no discounts.'] : null,
            $medicinesAmount !== null && $medicinesAmount < 0 ? ['The Medicines amount cannot be negative.', 'The Medicines amount was below zero; it was recorded as nothing.'] : null,
            $hasMedicines && $medicinesAmount === null && ! $isQuote ? ['Enter the Medicines amount.', 'Medicines were dispensed on this OPD bill without a Medicines amount; they were recorded at nothing.'] : null,
            ! $hasMedicines && ($medicinesAmount ?? 0) > 0 ? ['Add the medicines dispensed.', 'This OPD bill charged a Medicines amount without any medicines on it.'] : null,
        ]));

        if ($problems === []) {
            return [];
        }

        if (! $isOfflineSync) {
            throw new PosException($problems[0][0]);
        }

        return array_map(fn (array $problem): array => ['type' => 'opd', 'message' => $problem[1]], $problems);
    }

    /**
     * Prescription lines (docs/PHARMACY.md §4.10): a prescription-only or
     * controlled medicine needs a prescriber when the shop says so
     * (rx_prescriber `required`), and with the controlled-drug register on, a
     * controlled one needs the patient's name and should not be sold
     * offline. Online it is refused; offline it is flagged.
     *
     * @param  list<array<string, mixed>>  $priced
     * @return list<array<string, mixed>> offline review reasons
     */
    private function checkPrescriptions(Shop $shop, CheckoutData $data, array $priced): array
    {
        $prescriberRequired = $shop->setting('rx_prescriber') === 'required';
        $register = (bool) $shop->setting('controlled_register');
        $review = [];

        foreach ($priced as $line) {
            $schedule = DrugSchedule::tryFrom((string) $line['drug_schedule']);

            if (! in_array($schedule, [DrugSchedule::PrescriptionOnly, DrugSchedule::Controlled], true)) {
                continue;
            }

            $sku = $line['sku'];

            if ($prescriberRequired && $line['prescriber'] === null) {
                if (! $data->isOfflineSync) {
                    throw new PosException("{$sku} is prescription only: enter the prescriber.");
                }

                $review[] = ['type' => 'rx_missing', 'sku' => $sku, 'message' => "{$sku} is prescription only and was sold without a prescriber."];
            }

            if (! $register || $schedule !== DrugSchedule::Controlled) {
                continue;
            }

            if ($data->isOfflineSync) {
                $review[] = ['type' => 'controlled_offline', 'sku' => $sku, 'message' => "{$sku} is a controlled drug and was sold offline. Check the controlled-drug register."];
            } elseif (blank($data->customerName)) {
                throw new PosException("Enter the patient's name for {$sku} (controlled drug).");
            }
        }

        return $review;
    }

    /**
     * One audit entry per line sold at a typed price (docs/PHARMACY.md §4.3),
     * once the sale has its invoice number.
     *
     * @param  Collection<int, SaleItem>  $items  keyed like $data->lines
     */
    private function auditPriceOverrides(Sale $sale, CheckoutData $data, Collection $items): void
    {
        foreach ($data->lines as $i => $line) {
            /** @var SaleItem $item */
            $item = $items[$i];

            if ($item->is_opd_medicine || $this->typedPrice($line, $data->isOfflineSync) === null) {
                continue;
            }

            app(AuditObserver::class)->record(
                'price_overridden',
                $sale,
                ['unit_price' => $item->list_price],
                ['unit_price' => $item->unit_price, 'sku' => $item->sku, 'invoice_no' => $sale->invoice_no],
                (int) $data->cashier->getKey(),
            );
        }
    }

    /**
     * An offline till's clock (docs/PHARMACY.md §7.5): out by more than the
     * shop's tolerance, the sale is kept as the till recorded it and flagged
     * (`clock`), and reports date it by the server's clock: sold_at corrected
     * by the measured offset, or the time it reached the server when the till
     * sent no clock. A sale can't have happened after it reached the server:
     * when the corrected time is still more than the tolerance after that
     * (the till's clock was put right between the sale and the upload), it is
     * flagged too and dated when it arrived. A time a TIMESTAMP column can't
     * hold (a clock in 2040, or reset to 1970) is never stored: the sale is
     * dated when it arrived and the message keeps the till's time. Online
     * sales are on the server's clock.
     *
     * @param  list<array<string, mixed>>  $review
     * @return array{0: int|null, 1: CarbonImmutable|null, 2: CarbonImmutable|null} [clock_offset_seconds, reported_at (null: sold_at), sold_at (null: as the till sent it)]
     */
    private function tillClock(Shop $shop, CheckoutData $data, ?\DateTimeInterface $syncedAt, array &$review): array
    {
        if (! $data->isOfflineSync || $data->soldAt === null) {
            return [null, null, null];
        }

        $tolerance = max(0, (int) $shop->setting('till_clock_tolerance_minutes', 10)) * 60;
        $soldAt = CarbonImmutable::instance($data->soldAt);
        $arrived = CarbonImmutable::instance($syncedAt ?? now());
        $offset = $data->clockOffsetSeconds;

        if (! self::storableTime($soldAt)) {
            $review[] = [
                'type' => 'clock',
                'offset_seconds' => $offset ?? ($soldAt->getTimestamp() - $arrived->getTimestamp()),
                'message' => sprintf("The till recorded the sale at %s, a time that can't be stored. It is dated when it reached the server.", $soldAt->format('Y-m-d H:i')),
            ];

            return [$offset, $arrived, $arrived];
        }

        $corrected = $offset === null ? $soldAt : $soldAt->subSeconds($offset);
        $afterArrival = $corrected->getTimestamp() - $arrived->getTimestamp();
        $offsetOut = $offset !== null && abs($offset) > $tolerance;

        if (! $offsetOut && $afterArrival <= $tolerance) {
            return [$offset, null, null];
        }

        $minutes = function (int $seconds): string {
            $minutes = max(1, (int) round(abs($seconds) / 60));

            return $minutes.' '.str('minute')->plural($minutes);
        };

        $review[] = [
            'type' => 'clock',
            'offset_seconds' => $offsetOut ? $offset : $afterArrival,
            'message' => match (true) {
                $offsetOut => sprintf("The till's clock was %s %s. Reports date the sale by the server's clock.", $minutes((int) $offset), $offset > 0 ? 'ahead' : 'behind'),
                $offset === null => sprintf("The sale time was %s ahead of the server's clock. Reports date it by when it reached the server.", $minutes($afterArrival)),
                default => sprintf("The sale time was %s after the sale reached the server: the till's clock changed before it sent the sale. Reports date it by when it reached the server.", $minutes($afterArrival)),
            },
        ];

        $reportedAt = $offsetOut && $afterArrival <= $tolerance && self::storableTime($corrected) ? $corrected : $arrived;

        return [$offset, $reportedAt, null];
    }

    /**
     * A time a TIMESTAMP column holds (MySQL: 1970-01-01 00:00:01 to
     * 2038-01-19 03:14:07 UTC), with a day to spare for the connection's
     * timezone.
     */
    private static function storableTime(\DateTimeInterface $time): bool
    {
        return $time->getTimestamp() >= 86_400 && $time->getTimestamp() <= 2_147_483_647 - 86_400;
    }

    /**
     * @param  list<CartLine>  $lines
     * @return Collection<int, ProductVariant> keyed by id
     */
    private function loadVariants(array $lines, PriceList $priceList, bool $isOfflineSync): Collection
    {
        $ids = array_values(array_unique(array_map(fn ($line) => $line->variantId, $lines)));

        $variants = ProductVariant::query()
            ->when($isOfflineSync, fn ($q) => $q->withTrashed())
            ->with([
                'product.drugDetail',
                'prices' => fn ($q) => $q->where('price_list_id', $priceList->getKey()),
                'units.prices' => fn ($q) => $q->where('price_list_id', $priceList->getKey())->where('min_quantity', 1),
                'units.locationRules',
            ])
            ->whereKey($ids)
            ->get()
            ->keyBy('id');

        foreach ($ids as $id) {
            $variant = $variants->get($id);

            if ($variant === null) {
                throw new PosException("Item #{$id} was not found.");
            }

            // Offline sales already happened, so accept items deactivated or deleted since.
            if (! $isOfflineSync && (! $variant->is_active || ! $variant->product->is_active)) {
                throw new PosException("{$variant->sku} is no longer for sale.");
            }

            // Created while receiving by someone who can't manage products: never in a till's
            // search or catalog, so only an online request can name it. (Offline, never refused.)
            if (! $isOfflineSync && $variant->product->needs_review) {
                throw new PosException("{$variant->sku} is waiting for an admin to review it and can't be sold yet.");
            }
        }

        return $variants;
    }

    /**
     * Works out every line and the sale totals, all in integer minor units.
     * Each line is priced in the unit it is sold in (box, strip, tablet: each
     * has its own price) and also carries its quantity in base units. The
     * tax rate applies to standard products only; exempt lines carry no tax.
     *
     * A typed price (a service that allows it, docs/PHARMACY.md §4.3) replaces
     * the list price, which stays on the line as list_price. On an OPD bill
     * the medicine lines (every line that holds stock) are not priced one by
     * one: the Medicines amount is spread over them, so their nets add up to
     * exactly the amount. Online, by their list gross (price list price x
     * quantity), an unpriced line by its cost (unit cost x quantity), equally
     * when nothing weighs anything; the shares never reach the till (quote(),
     * ReceiptService::sale()), so a cost can't be worked out from them.
     * Replayed offline, by the prices the till sent (unpriced: nothing, the
     * till has no cost), so the shares, tax and total are the till's.
     *
     * @param  list<CartLine>  $lines
     * @param  Collection<int, ProductVariant>  $variants
     * @param  array{rate: float, inclusive: bool}  $taxRule
     * @param  array<int, Location>|null  $locations  keyed like $lines; null for a quote without a till (no location, no stock)
     * @param  array<int, string>  $baseCosts  keyed like $lines; empty = the item's blended cost
     * @return array{subtotal:int, discount_total:int, tax_total:int, grand_total:int, lines:list<array<string, mixed>>}
     */
    private function calculate(
        array $lines,
        int $saleDiscount,
        Collection $variants,
        PriceList $priceList,
        array $taxRule,
        bool $isOfflineSync,
        ?array $locations = null,
        array $baseCosts = [],
        BillType $billType = BillType::Standard,
        int $medicinesAmount = 0,
    ): array {
        $priced = [];
        $grosses = [];
        $nets = [];
        $weights = [];

        foreach ($lines as $i => $cartLine) {
            $quantity = Quantity::normalize($cartLine->quantity);

            if ($quantity <= 0) {
                throw new PosException('Quantities must be greater than zero.');
            }

            $variant = $variants->get($cartLine->variantId);
            $unit = $this->unitFor($variant, $cartLine->unitId, $isOfflineSync, $locations[$i] ?? null);
            $baseQuantity = $unit->toBase($quantity);

            // Whole units of the unit sold (1 strip, never 0.5 box). An offline sale
            // already happened: it is flagged instead (partUnits()).
            if (! $isOfflineSync) {
                $this->stock->assertWholeUnits($variant, $quantity, $unit->name);
            }

            // Per sold unit, like quantity and unit_price: a box costs 30 tablets' average
            // at the line's location.
            $unitCost = UnitCost::times($baseCosts[$i] ?? (int) $variant->cost_price, (float) $unit->factor);
            $listPrice = $unit->priceOn($priceList);
            $isOpdMedicine = $billType === BillType::Opd && $variant->product->track_stock;
            $typedPrice = $isOpdMedicine ? null : $this->typedPrice($cartLine, $isOfflineSync);

            if ($isOpdMedicine) {
                // Online weighed by its list gross, else by its cost (never sent to the till: see
                // quote()). Offline, by the price the till weighed it with (0 when it had none), so
                // the split, the tax and the total are the ones the till charged. Its gross is its
                // share (below).
                $unitPrice = $listPrice ?? 0;
                $weights[$i] = match (true) {
                    $isOfflineSync => Money::multiply($cartLine->tillUnitPrice ?? $unitPrice, $quantity),
                    $listPrice !== null => Money::multiply($listPrice, $quantity),
                    default => Money::multiply($unitCost, $quantity),
                };
            } else {
                $unitPrice = $typedPrice
                    ?? ($isOfflineSync ? $cartLine->tillUnitPrice : null)
                    ?? $listPrice
                    ?? throw new PosException($unit->is_base
                        ? "{$variant->sku} has no price on the {$priceList->name} price list."
                        : "{$variant->sku} has no price for a {$unit->name} on the {$priceList->name} price list.");
            }

            $gross = $isOpdMedicine ? 0 : Money::multiply($unitPrice, $quantity);
            // An OPD bill takes no discounts (refused online, flagged offline): none on a medicine line.
            $lineDiscount = $isOpdMedicine ? 0 : $cartLine->lineDiscount;

            if ($lineDiscount < 0 || $lineDiscount > $gross) {
                throw new PosException("Invalid discount on {$variant->sku}.");
            }

            $nets[$i] = $gross - $lineDiscount;
            $priced[$i] = [
                'product_variant_id' => $variant->getKey(),
                ...($locations === null ? [] : ['location_id' => $locations[$i]->getKey()]),
                'variant_unit_id' => $unit->getKey(),
                'description' => trim($variant->product->name.' '.($variant->name ?? '')).($unit->is_base ? '' : " ({$unit->name})"),
                'sku' => $variant->sku,
                'unit_name' => $unit->name,
                'unit_factor' => (float) $unit->factor,
                // Printed on receipts and reprints as sold (docs/PHARMACY.md §9, branch 9).
                'base_unit_name' => mb_substr(trim((string) $variant->product->unit), 0, 30) ?: null,
                'strength' => $this->text($variant->product->drugDetail?->strength(), 50),
                'quantity' => $quantity,
                'base_quantity' => $baseQuantity,
                'unit_price' => $unitPrice,
                // The price list's price when the line was charged something else.
                'list_price' => $isOpdMedicine || $typedPrice !== null ? $listPrice : null,
                'is_opd_medicine' => $isOpdMedicine,
                'unit_cost' => $unitCost,
                'line_discount' => $lineDiscount,
                'tax_category' => $variant->product->tax_category->value,
                'drug_schedule' => $variant->product->drugDetail?->schedule->value,
                'prescriber' => $this->text($cartLine->prescriber, 150),
                'prescription_ref' => $this->text($cartLine->prescriptionRef, 100),
            ];
            $grosses[$i] = $gross;
        }

        if ($weights !== []) {
            if (array_sum($weights) <= 0) {
                $weights = array_map(fn (): int => 1, $weights);
            }

            foreach (Money::allocate($medicinesAmount, $weights) as $i => $share) {
                $grosses[$i] = $nets[$i] = $share;
            }
        }

        if ($saleDiscount > array_sum($nets)) {
            throw new PosException('The discount is larger than the sale total.');
        }

        // Spread the whole-sale discount over the lines so returns refund the right amount.
        $shares = Money::allocate($saleDiscount, $nets);

        $subtotal = $taxTotal = $grandTotal = $lineDiscounts = 0;

        foreach ($priced as $i => &$line) {
            $afterDiscount = $nets[$i] - $shares[$i];
            $rate = $line['tax_category'] === TaxCategory::Exempt->value ? 0.0 : $taxRule['rate'];

            if ($rate > 0 && $taxRule['inclusive']) {
                $tax = (int) round($afterDiscount * $rate / (100 + $rate));
                $lineTotal = $afterDiscount;
            } else {
                $tax = (int) round($afterDiscount * $rate / 100);
                $lineTotal = $afterDiscount + $tax;
            }

            $subtotal += $grosses[$i];
            $lineDiscounts += $line['line_discount'];
            $taxTotal += $tax;
            $grandTotal += $lineTotal;

            $line['sale_discount_share'] = $shares[$i];
            $line['tax_amount'] = $tax;
            $line['line_total'] = $lineTotal;
        }
        unset($line);

        return [
            'subtotal' => $subtotal,
            'discount_total' => $lineDiscounts + $saleDiscount,
            'tax_total' => $taxTotal,
            'grand_total' => $grandTotal,
            'lines' => array_values($priced),
        ];
    }

    /** The price typed for a line: online the requested override, offline the till's price when it says it was typed. */
    private function typedPrice(CartLine $line, bool $isOfflineSync): ?int
    {
        return $isOfflineSync
            ? ($line->tillPriceOverride ? $line->tillUnitPrice : null)
            : $line->unitPriceOverride;
    }

    /** Free text as stored on a line: trimmed, blank as null, cut to the column's length. */
    private function text(?string $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /**
     * The unit a line is sold in: the one asked for, or the base unit. Online,
     * it must be active and sellable (Q16) at the line's location (a syrup's
     * loose ml at one counter only); a quote without a till checks the unit
     * as sold anywhere. An offline sale already happened, so any unit of the
     * item is accepted (unitsNotSoldHere() lists one not sold there).
     */
    private function unitFor(ProductVariant $variant, ?int $unitId, bool $isOfflineSync, ?Location $location = null): VariantUnit
    {
        /** @var VariantUnit|null $unit */
        $unit = $unitId === null
            ? $variant->units->firstWhere('is_base', true)
            : $variant->units->firstWhere('id', $unitId);

        // An offline sale already happened: an unknown unit is recorded as the base unit (unknownUnits()).
        if ($unit === null && $isOfflineSync && $unitId !== null) {
            $unit = $variant->units->firstWhere('is_base', true);
        }

        if ($unit === null) {
            throw new PosException($unitId === null ? "{$variant->sku} has no base unit." : "That unit is not one of {$variant->sku}'s units.");
        }

        if ($isOfflineSync || ($location === null ? $variant->canSellUnit($unit) : $variant->canSellUnitAt($unit, $location))) {
            return $unit;
        }

        throw new PosException(match (true) {
            ! $unit->is_active => "{$variant->sku} is no longer sold by the {$unit->name}.",
            $location !== null => "{$variant->sku} is not sold by the {$unit->name} at {$location->name}.",
            default => "{$variant->sku} is not sold by the {$unit->name}.",
        });
    }

    private function checkDiscountLimit(Shop $shop, User $cashier, int $subtotal, int $discount): void
    {
        if ($discount === 0 || $subtotal === 0 || $cashier->can('pos.discount.unlimited')) {
            return;
        }

        $percent = $discount / $subtotal * 100;
        $limit = (float) $shop->setting('max_cashier_discount_percent', 0);

        if ($percent > $limit + 0.0001) {
            throw PosException::discountTooHigh($percent, $limit);
        }
    }

    /**
     * Offline sales are not refused for a payment problem (the money has already
     * changed hands); the problem is returned for the owner's review instead.
     *
     * A bank transfer needs its reference when the shop says so
     * (bank_transfer_reference_required).
     *
     * @param  list<PaymentInput>  $payments
     * @return array{0:int, 1:int, 2:list<array<string, mixed>>} [paid total, change due, review reasons]
     */
    private function checkPayments(array $payments, int $grandTotal, bool $isOfflineSync, ?Shop $shop = null): array
    {
        $paid = 0;
        $cash = 0;

        foreach ($payments as $payment) {
            if ($payment->amount <= 0) {
                throw new PosException('Payment amounts must be greater than zero.');
            }

            $paid += $payment->amount;
            $cash += $payment->method === PaymentMethod::Cash ? $payment->amount : 0;
        }

        $problems = [];

        if ($paid < $grandTotal) {
            $problems[] = 'Payment is short by '.Money::format($grandTotal - $paid).'.';
        }

        $change = max(0, $paid - $grandTotal);

        // Change can only be given from cash, never from a card overpayment.
        if ($change > $cash) {
            $problems[] = 'Card and transfer payments cannot be more than the amount due.';
        }

        $referenceRequired = (bool) $shop?->setting('bank_transfer_reference_required');

        if ($referenceRequired && collect($payments)->contains(fn (PaymentInput $payment): bool => $payment->method === PaymentMethod::BankTransfer && blank($payment->reference))) {
            $problems[] = $isOfflineSync ? 'A bank transfer was taken without a reference.' : 'Enter the bank transfer reference.';
        }

        if ($problems !== [] && ! $isOfflineSync) {
            throw new PosException($problems[0]);
        }

        return [$paid, $change, array_map(fn (string $message): array => ['type' => 'payment', 'message' => $message], $problems)];
    }

    /**
     * Called inside the checkout transaction; the lock stops the shift closing mid-sale.
     * An offline sale belongs to the shift that was open on the till when it was sold.
     *
     * @param  list<array<string, mixed>>  $review
     */
    private function openShiftFor(Shop $shop, CheckoutData $data, array &$review): ?Shift
    {
        $offlineSoldAt = $data->isOfflineSync ? $data->soldAt : null;

        $shift = Shift::query()
            ->where('device_id', $data->device->getKey())
            ->when(
                $offlineSoldAt === null,
                fn ($q) => $q->whereNull('closed_at'),
                fn ($q) => $q->where('opened_at', '<=', $offlineSoldAt)
                    ->where(fn ($open) => $open->whereNull('closed_at')->orWhere('closed_at', '>=', $offlineSoldAt)),
            )
            ->latest('opened_at')
            ->lockForUpdate()
            ->first();

        if ($shift === null && $shop->setting('require_open_shift') && ! $data->isOfflineSync) {
            throw new PosException('Open a shift before making sales.');
        }

        if ($shift?->closed_at !== null) {
            $review[] = ['type' => 'shift', 'message' => 'This sale reached the server after its shift was closed, so the shift\'s cash count does not include it.'];
        }

        return $shift;
    }

    /**
     * The sale's invoice number. Online: the till's next number. Offline: the
     * number the till printed (from a block it reserved). If that number cannot
     * be used, the sale is renumbered and flagged rather than lost.
     * Called inside the checkout transaction.
     *
     * @param  list<array<string, mixed>>  $review
     */
    private function invoiceNumberFor(Shop $shop, CheckoutData $data, array &$review): string
    {
        $padding = (int) $shop->setting('invoice_padding', 6);

        if ($data->invoiceNo === null) {
            return $data->device->reserveInvoiceNumber($padding);
        }

        if (! $data->isOfflineSync) {
            $data->device->advanceInvoiceCounterPast($data->invoiceNo);

            return $data->invoiceNo;
        }

        // Lock the till first so two syncs cannot both claim the same number.
        Device::query()->whereKey($data->device->getKey())->lockForUpdate()->firstOrFail();

        $sequence = $data->device->invoiceSequence($data->invoiceNo);
        $taken = Sale::query()->where('invoice_no', $data->invoiceNo)->exists();

        if ($sequence === null || $taken) {
            $invoiceNo = $data->device->reserveInvoiceNumber($padding);
            $review[] = [
                'type' => 'invoice',
                'till_invoice_no' => $data->invoiceNo,
                'message' => sprintf(
                    'The till printed invoice %s, but that number %s. Recorded as %s.',
                    $data->invoiceNo,
                    $taken ? 'was already used' : "does not belong to till {$data->device->code}",
                    $invoiceNo,
                ),
            ];

            return $invoiceNo;
        }

        $reserved = $data->device->invoiceBlocks()
            ->where('first_number', '<=', $sequence)
            ->where('last_number', '>=', $sequence)
            ->exists();

        if (! $reserved) {
            $review[] = [
                'type' => 'invoice',
                'till_invoice_no' => $data->invoiceNo,
                'message' => "Invoice {$data->invoiceNo} was not from a block of numbers this till reserved.",
            ];
        }

        $data->device->advanceInvoiceCounterPast($data->invoiceNo);

        return $data->invoiceNo;
    }
}
