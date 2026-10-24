<?php

namespace App\Services;

use App\Enums\GoodsReceiptStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\StockRequestStatus;
use App\Exceptions\PosException;
use App\Models\GoodsReceiptItem;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\VariantUnit;
use App\Services\Data\PurchaseLine;
use App\Services\Data\RequestLine;
use App\Support\CurrentShop;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Reorder suggestions (docs/PHARMACY.md §9, branch 10), per location, for
 * items at or below their reorder level (ReportService::lowStock(): the
 * location's own reorder level, else the item's):
 *
 *   target    = the location's par level, else twice the reorder level
 *   incoming  = still to arrive there: open purchase orders' outstanding
 *               quantities at the location, and open stock requests'
 *               quantities still to send to it
 *   suggested = target - max(on hand - expired, 0) - incoming, never
 *               below zero; whole units for whole-units items (expired
 *               batches are on hand but can't be sold or sent)
 *
 * all in base units. Two ways to act on them: a stock request up to par for
 * a location supplied by another (the OPD asking General Pharmacy;
 * StockRequestService), and draft purchase orders for the location that
 * buys (General Pharmacy), one per supplier, each item going to the
 * supplier of its last goods received note, in the pack it was last
 * received in (rounded up to whole packs). Items never received from a
 * supplier are left out and listed. Read only, except purchaseOrders().
 */
class ReorderService
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly PurchaseOrderService $orders,
        private readonly ExpiryService $expiry,
        private readonly CurrentShop $currentShop,
    ) {}

    /**
     * @return list<array{variant_id: int, sku: string, product: string, variant: string|null, unit: string, on_hand: float, expired: float, reorder_level: float, par_level: float|null, target: float, incoming: float, suggested: float, bin_location: string|null}>
     */
    public function suggestions(Location $location): array
    {
        $rows = $this->reports->lowStock(locationId: (int) $location->getKey());

        if ($rows === []) {
            return [];
        }

        $variantIds = array_column($rows, 'variant_id');
        $incoming = $this->incoming($location, $variantIds);
        // Expired batches are on hand but can't be sold: they don't count towards the target.
        $expired = $this->expiry->query(ExpiryService::EXPIRED, (int) $location->getKey())
            ->whereIn('stock_batches.product_variant_id', $variantIds)
            ->reorder()
            ->groupBy('stock_batches.product_variant_id')
            ->selectRaw('stock_batches.product_variant_id as variant_id, SUM(stock_batches.quantity) as quantity')
            ->toBase()
            ->pluck('quantity', 'variant_id');
        $variants = ProductVariant::query()->with('product')->whereKey($variantIds)->get()->keyBy('id');
        $suggestions = [];

        foreach ($rows as $row) {
            /** @var ProductVariant $variant */
            $variant = $variants->get($row['variant_id']);
            $target = $row['par_level'] ?? $row['reorder_level'] * 2;
            $coming = $incoming[$row['variant_id']] ?? 0.0;
            $unusable = Quantity::normalize((float) ($expired[$row['variant_id']] ?? 0));
            $suggested = Quantity::normalize(max(0.0, $target - max(0.0, $row['on_hand'] - $unusable) - $coming));

            if ($variant->product->whole_units_only) {
                $suggested = (float) ceil($suggested);
            }

            $suggestions[] = [
                'variant_id' => $row['variant_id'],
                'sku' => $row['sku'],
                'product' => $row['product'],
                'variant' => $row['variant'],
                'unit' => (string) $variant->product->unit,
                'on_hand' => $row['on_hand'],
                'expired' => $unusable,
                'reorder_level' => $row['reorder_level'],
                'par_level' => $row['par_level'],
                'target' => Quantity::normalize($target),
                'incoming' => $coming,
                'suggested' => $suggested,
                'bin_location' => $row['bin_location'],
            ];
        }

        return $suggestions;
    }

    /**
     * A stock request's lines for a location, up to par, in base units:
     * every suggestion above zero.
     *
     * @return list<RequestLine>
     */
    public function requestLines(Location $location): array
    {
        return array_values(array_map(
            fn (array $row): RequestLine => new RequestLine($row['variant_id'], $row['suggested']),
            array_filter($this->suggestions($location), fn (array $row): bool => $row['suggested'] > 0),
        ));
    }

    /**
     * Each suggested item's last supplier and purchase pack, from its latest
     * posted goods received note (not a reversal): the supplier, the unit it
     * came in when that unit is still bought, and what one of it cost.
     *
     * @param  list<int>  $variantIds
     * @return array<int, array{supplier_id: int, unit_id: int|null, factor: float, unit_cost: int}>
     */
    public function lastPurchases(array $variantIds): array
    {
        $lines = GoodsReceiptItem::query()
            ->join('goods_receipts', 'goods_receipts.id', '=', 'goods_receipt_items.goods_receipt_id')
            ->where('goods_receipts.status', GoodsReceiptStatus::Posted->value)
            ->whereNull('goods_receipts.reversal_of_id')
            ->whereIn('goods_receipt_items.product_variant_id', $variantIds)
            ->orderByDesc('goods_receipts.received_on')
            ->orderByDesc('goods_receipt_items.id')
            ->get(['goods_receipt_items.product_variant_id', 'goods_receipt_items.variant_unit_id', 'goods_receipt_items.unit_factor', 'goods_receipt_items.unit_cost', 'goods_receipts.supplier_id']);

        $units = VariantUnit::query()->whereIn('product_variant_id', $variantIds)->get()->keyBy('id');
        $last = [];

        foreach ($lines as $line) {
            $variantId = (int) $line->product_variant_id;

            if (isset($last[$variantId])) {
                continue;
            }

            /** @var VariantUnit|null $unit */
            $unit = $units->get((int) $line->variant_unit_id);
            $bought = $unit !== null && $unit->is_active && $unit->is_purchasable;

            $last[$variantId] = [
                'supplier_id' => (int) $line->getAttribute('supplier_id'),
                'unit_id' => $bought && ! $unit->is_base ? (int) $unit->getKey() : null,
                'factor' => $bought ? (float) $unit->factor : 1.0,
                'unit_cost' => $bought ? (int) $line->unit_cost : (int) round((int) $line->unit_cost / max(1.0, (float) $line->unit_factor)),
            ];
        }

        return $last;
    }

    /**
     * Draft purchase orders for a location's suggestions, one per supplier
     * (the item's last supplier), each line in the pack it was last received
     * in, rounded up to whole packs, at that pack's last cost. Drafts are
     * edited and sent as usual (PurchaseOrderService). Needs purchasing.
     *
     * @return array{orders: list<PurchaseOrder>, skipped: list<array{sku: string, reason: string}>}
     */
    public function purchaseOrders(Location $location, User $user): array
    {
        if (! $this->currentShop->getOrFail()->hasModule('purchasing')) {
            throw new PosException('Purchase orders need the purchasing module.');
        }

        $suggestions = array_values(array_filter($this->suggestions($location), fn (array $row): bool => $row['suggested'] > 0));

        if ($suggestions === []) {
            throw new PosException("Nothing to order for {$location->name}: no item below its reorder level needs more than is already coming.");
        }

        $last = $this->lastPurchases(array_column($suggestions, 'variant_id'));
        $suppliers = Supplier::query()->whereKey(array_unique(array_column($last, 'supplier_id')))->get()->keyBy('id');
        $bySupplier = [];
        $skipped = [];

        foreach ($suggestions as $row) {
            $purchase = $last[$row['variant_id']] ?? null;
            /** @var Supplier|null $supplier */
            $supplier = $purchase === null ? null : $suppliers->get($purchase['supplier_id']);

            if ($purchase === null || $supplier === null) {
                $skipped[] = ['sku' => $row['sku'], 'reason' => 'never received from a supplier'];

                continue;
            }

            if (! $supplier->is_active) {
                $skipped[] = ['sku' => $row['sku'], 'reason' => "{$supplier->name} is not an active supplier"];

                continue;
            }

            $packs = (float) ceil(Quantity::normalize($row['suggested'] / $purchase['factor']));
            $bySupplier[(int) $supplier->getKey()][] = new PurchaseLine($row['variant_id'], $packs, $purchase['unit_id'], $purchase['unit_cost']);
        }

        ksort($bySupplier);
        $note = 'Suggested from low stock at '.$location->name.' on '.now($this->currentShop->getOrFail()->timezone ?: 'UTC')->format('d/m/Y').'.';

        $orders = DB::transaction(fn (): array => array_map(
            fn (int $supplierId, array $lines): PurchaseOrder => $this->orders->create($suppliers->get($supplierId), $location, $lines, $user, notes: $note),
            array_keys($bySupplier),
            $bySupplier,
        ));

        return ['orders' => $orders, 'skipped' => $skipped];
    }

    /**
     * Base units still to arrive at a location, per item: open purchase
     * orders (draft, sent, partly received) at it, and open stock requests
     * from it.
     *
     * @param  list<int>  $variantIds
     * @return array<int, float>
     */
    public function incoming(Location $location, array $variantIds): array
    {
        $ordered = DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_orders.shop_id', $location->shop_id)
            ->where('purchase_orders.location_id', $location->getKey())
            ->whereIn('purchase_orders.status', [PurchaseOrderStatus::Draft->value, PurchaseOrderStatus::Sent->value, PurchaseOrderStatus::PartiallyReceived->value])
            ->whereIn('purchase_order_items.product_variant_id', $variantIds)
            ->groupBy('purchase_order_items.product_variant_id')
            ->selectRaw('purchase_order_items.product_variant_id as variant_id, SUM(CASE WHEN purchase_order_items.base_quantity > purchase_order_items.received_base_quantity THEN purchase_order_items.base_quantity - purchase_order_items.received_base_quantity ELSE 0 END) as quantity')
            ->pluck('quantity', 'variant_id');

        $requested = DB::table('stock_request_items')
            ->join('stock_requests', 'stock_requests.id', '=', 'stock_request_items.stock_request_id')
            ->where('stock_requests.shop_id', $location->shop_id)
            ->where('stock_requests.to_location_id', $location->getKey())
            ->whereIn('stock_requests.status', StockRequestStatus::openValues())
            ->whereIn('stock_request_items.product_variant_id', $variantIds)
            ->groupBy('stock_request_items.product_variant_id')
            ->selectRaw('stock_request_items.product_variant_id as variant_id, SUM(stock_request_items.base_quantity - stock_request_items.sent_base_quantity) as quantity')
            ->pluck('quantity', 'variant_id');

        $incoming = [];

        foreach ($variantIds as $variantId) {
            $incoming[$variantId] = Quantity::normalize((float) ($ordered[$variantId] ?? 0) + (float) ($requested[$variantId] ?? 0));
        }

        return $incoming;
    }
}
