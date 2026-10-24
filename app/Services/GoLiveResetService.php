<?php

namespace App\Services;

use App\Models\Device;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Shift;
use App\Models\Shop;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\StockRequest;
use App\Models\StockTransfer;
use App\Models\StockWriteOff;
use App\Models\SupplierAllocation;
use App\Models\SupplierOpeningBalance;
use App\Models\SupplierPayment;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Support\CurrentShop;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Clears the rehearsal out of a shop on go-live morning (pos:go-live-reset,
 * docs/deploy/GO-LIVE.md): the test sales with their items, payments and
 * batches, returns, voids, shifts and their stock movements, and the
 * invoice numbering (every till starts again at 000001, its offline number
 * blocks dropped and its sync token revoked, so it is registered again with
 * a clean slate). With $stock, also everything else that moved stock or
 * money with suppliers: opening stock, goods received, orders, transfers,
 * requests, write-offs, counts, supplier returns, payments, opening
 * balances and payables, so the opening stock and supplier invoices are
 * imported fresh. Products, units, prices, suppliers, users, locations,
 * tills, settings, label and import templates stay.
 *
 * The one place sales and stock movements are ever deleted (rule 6): the
 * stock levels, batches and running balances are rebuilt by
 * StockService::forgetMovements(), and the reset is audited on the shop.
 */
class GoLiveResetService
{
    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly StockService $stock,
        private readonly AuditObserver $audit,
    ) {}

    /**
     * What a reset would remove, by table, and the tills that last said they
     * hold offline sales not uploaded yet.
     *
     * @return array{counts: array<string, int>, waiting_tills: list<string>}
     */
    public function preview(Shop $shop, bool $stock): array
    {
        return $this->currentShop->runAs($shop, fn (): array => [
            'counts' => array_filter(array_map(fn (Builder $query): int => $query->count(), $this->targets($shop, $stock))),
            'waiting_tills' => array_values(Device::query()->where('pending_uploads', '>', 0)->orderBy('code')->pluck('code')->map(fn (mixed $code): string => (string) $code)->all()),
        ]);
    }

    /**
     * Removes it all in one transaction. Returns what was removed, by table.
     *
     * @return array<string, int>
     */
    public function reset(Shop $shop, User $owner, bool $stock): array
    {
        if ((int) $owner->shop_id !== (int) $shop->getKey() || ! $owner->hasRole('owner')) {
            throw new \InvalidArgumentException('Only an owner of this shop can reset it for go-live.');
        }

        return $this->currentShop->runAs($shop, fn (): array => DB::transaction(function () use ($shop, $owner, $stock): array {
            $removed = [];
            $movementIds = $stock
                ? []
                : $this->movementsOf([(new Sale)->getMorphClass(), (new SaleReturn)->getMorphClass()]);

            // Links within a table (reversals) first, so whole tables can go.
            if ($stock) {
                foreach (['goods_receipt_items' => 'reversal_of_item_id', 'goods_receipts' => 'reversal_of_id', 'supplier_payments' => 'reversal_of_id', 'supplier_opening_balances' => 'reversal_of_id', 'supplier_allocations' => 'reversal_of_id'] as $table => $column) {
                    DB::table($table)->where('shop_id', $shop->getKey())->whereNotNull($column)->update([$column => null]);
                }
            }

            foreach ($this->targets($shop, $stock) as $table => $query) {
                $removed[$table] = $query->delete();
            }

            if ($stock) {
                $removed['stock_movements'] = DB::table('stock_movements')->where('shop_id', $shop->getKey())->delete();
                $removed['stock_batches'] = DB::table('stock_batches')->where('shop_id', $shop->getKey())->delete();
                DB::table('stock_levels')->where('shop_id', $shop->getKey())->update(['quantity' => 0]);
            } else {
                $removed['stock_movements'] = $this->stock->forgetMovements($movementIds);
            }

            // Every till numbers from 000001 again, and is registered again for offline sales.
            $tills = Device::query()->get();

            foreach ($tills as $till) {
                $till->forceFill(['next_invoice_number' => 1, 'pending_uploads' => null, 'reported_at' => null])->saveQuietly();
                $till->revokeSyncTokens();
            }

            $removed = array_filter($removed);
            $this->audit->record('go_live_reset', $shop, [], ['removed' => $removed, 'stock' => $stock, 'tills' => $tills->pluck('code')->all()], (int) $owner->getKey());

            return $removed;
        }));
    }

    /**
     * The rows to delete, children before their parents.
     *
     * @return array<string, Builder>
     */
    private function targets(Shop $shop, bool $stock): array
    {
        $shopId = $shop->getKey();
        $sales = fn (): Builder => DB::table('sales')->where('shop_id', $shopId)->select('id');
        $returns = fn (): Builder => DB::table('sale_returns')->where('shop_id', $shopId)->select('id');
        $saleItems = fn (): Builder => DB::table('sale_items')->whereIn('sale_id', $sales())->select('id');

        $targets = [
            'sale_return_items' => DB::table('sale_return_items')->whereIn('sale_return_id', $returns()),
            'sale_item_batches' => DB::table('sale_item_batches')->whereIn('sale_item_id', $saleItems()),
            'sale_returns' => DB::table('sale_returns')->where('shop_id', $shopId),
            'payments' => DB::table('payments')->whereIn('sale_id', $sales()),
            'sale_items' => DB::table('sale_items')->whereIn('sale_id', $sales()),
            'sales' => DB::table('sales')->where('shop_id', $shopId),
            'shifts' => DB::table('shifts')->where('shop_id', $shopId),
            'invoice_number_blocks' => DB::table('invoice_number_blocks')->where('shop_id', $shopId),
        ];

        if ($stock) {
            $targets += [
                'supplier_allocations' => DB::table('supplier_allocations')->where('shop_id', $shopId),
                'supplier_credits' => DB::table('supplier_credits')->where('shop_id', $shopId),
                'supplier_payables' => DB::table('supplier_payables')->where('shop_id', $shopId),
                'supplier_payments' => DB::table('supplier_payments')->where('shop_id', $shopId),
                'supplier_opening_balances' => DB::table('supplier_opening_balances')->where('shop_id', $shopId),
                'supplier_return_items' => DB::table('supplier_return_items')->where('shop_id', $shopId),
                'supplier_returns' => DB::table('supplier_returns')->where('shop_id', $shopId),
                'goods_receipt_items' => DB::table('goods_receipt_items')->where('shop_id', $shopId),
                'goods_receipts' => DB::table('goods_receipts')->where('shop_id', $shopId),
                'purchase_order_items' => DB::table('purchase_order_items')->where('shop_id', $shopId),
                'purchase_orders' => DB::table('purchase_orders')->where('shop_id', $shopId),
                'stock_transfer_item_batches' => DB::table('stock_transfer_item_batches')->where('shop_id', $shopId),
                'stock_transfer_items' => DB::table('stock_transfer_items')->where('shop_id', $shopId),
                'stock_transfers' => DB::table('stock_transfers')->where('shop_id', $shopId),
                'stock_request_items' => DB::table('stock_request_items')->where('shop_id', $shopId),
                'stock_requests' => DB::table('stock_requests')->where('shop_id', $shopId),
                'stock_write_off_items' => DB::table('stock_write_off_items')->where('shop_id', $shopId),
                'stock_write_offs' => DB::table('stock_write_offs')->where('shop_id', $shopId),
                'stock_count_lines' => DB::table('stock_count_lines')->where('shop_id', $shopId),
                'stock_counts' => DB::table('stock_counts')->where('shop_id', $shopId),
            ];
        }

        // The audit trail of what is removed (sales, reprints, documents), not of the master data.
        $targets['audit_logs'] = DB::table('audit_logs')->where('shop_id', $shopId)->whereIn('auditable_type', array_map(
            fn (string $model): string => (new $model)->getMorphClass(),
            $stock
                ? [Sale::class, SaleReturn::class, Shift::class, GoodsReceipt::class, PurchaseOrder::class, SupplierReturn::class, SupplierPayment::class, SupplierOpeningBalance::class, SupplierAllocation::class, StockTransfer::class, StockRequest::class, StockWriteOff::class, StockCount::class]
                : [Sale::class, SaleReturn::class, Shift::class],
        ));

        return $targets;
    }

    /**
     * The ids of the stock movements made by these documents.
     *
     * @param  list<string>  $morphClasses
     * @return list<int>
     */
    private function movementsOf(array $morphClasses): array
    {
        return array_values(StockMovement::query()
            ->whereIn('reference_type', $morphClasses)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }
}
