<?php

namespace App\Services;

use App\Models\Device;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Support\CurrentShop;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Storage;

/**
 * What a till keeps in IndexedDB to keep selling offline: every sellable item
 * (same shape as the online search, plus vehicle fitments), the shop settings
 * receipts and pricing need, and the till itself. Selling prices and stock
 * only; cost prices never leave the server.
 *
 * Without `since` the till gets everything. With `since` it gets only items
 * whose variant, product, units, prices (any unit's), selling loose at the
 * till's location, stock or batches there, or fitments changed, and the ids
 * of items that can no longer be sold. Large catalogs come in pages
 * (`after`). Only the till's own location is sent: a till is fixed to its
 * location and syncs everything again when it changes.
 */
class CatalogSyncService
{
    public const PAGE_SIZE = 500;

    /** Changes committed just before a sync can carry an older timestamp, so each cursor overlaps the last sync a little. */
    private const OVERLAP_SECONDS = 60;

    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly ItemSearchService $items,
    ) {}

    /**
     * @return array{
     *     cursor: string,
     *     full: bool,
     *     has_more: bool,
     *     next_after: int|null,
     *     shop: array<string, mixed>,
     *     till: array<string, mixed>,
     *     items: list<array<string, mixed>>,
     *     deleted: list<int>,
     *     vehicles: array<int, mixed>|null,
     * }
     */
    public function changes(Device $device, ?CarbonInterface $since, int $afterId = 0): array
    {
        $shop = $this->currentShop->getOrFail();
        $cursor = now()->subSeconds(self::OVERLAP_SECONDS);
        $priceListId = $shop->defaultPriceList()->getKey();
        $fitment = $shop->hasModule('vehicle_fitment');

        $variants = ProductVariant::query()
            ->withTrashed()
            ->where('product_variants.id', '>', $afterId)
            ->when(
                $since === null,
                fn (Builder $query) => $query->whereNull('deleted_at')->where('is_active', true)
                    ->whereHas('product', fn (Builder $product) => $product->where('is_active', true)->where('needs_review', false)),
                fn (Builder $query) => $this->changedSince($query, $since, $device, $priceListId, $fitment),
            )
            ->with([
                ...ItemSearchService::relations($shop, $device),
                'product' => fn ($q) => $q->select(ItemSearchService::PRODUCT_COLUMNS)
                    ->with(ItemSearchService::drugRelations($shop->hasModule('pharmacy')))
                    ->when($fitment, fn ($product) => $product->with('fitments:vehicle_models.id,vehicle_models.vehicle_make_id')),
            ])
            ->orderBy('product_variants.id')
            ->limit(self::PAGE_SIZE + 1)
            ->get();

        $hasMore = $variants->count() > self::PAGE_SIZE;
        $variants = $variants->take(self::PAGE_SIZE);
        $location = $device->location()->first();

        // An item waiting for review is not for sale: a till that has it drops it until it is reviewed.
        [$sellable, $gone] = $variants->partition(
            fn (ProductVariant $variant): bool => ! $variant->trashed() && $variant->is_active && $variant->product->is_active && ! $variant->product->needs_review,
        );
        $this->items->loadBatches($sellable, $device);

        return [
            'cursor' => $cursor->toIso8601String(),
            'full' => $since === null,
            'has_more' => $hasMore,
            'next_after' => $hasMore ? (int) $variants->last()?->getKey() : null,
            'shop' => $this->shopSettings($shop),
            'till' => [
                'uuid' => $device->uuid,
                'code' => $device->code,
                'name' => $device->name,
                // A till is fixed to its location: when this changes, the till syncs everything again.
                'location_id' => (int) $device->location_id,
                'location_code' => $location?->code,
                'location_name' => $location?->name,
                'opd_billing' => (bool) $device->opd_billing,
            ],
            'items' => array_values($sellable->map(fn (ProductVariant $variant): array => $this->present($variant, $device, $fitment))->all()),
            'deleted' => array_values($gone->map(fn (ProductVariant $variant): int => (int) $variant->getKey())->all()),
            'vehicles' => $fitment && $afterId === 0 && $this->vehiclesChanged($since) ? $this->vehicles() : null,
        ];
    }

    /**
     * Everything the till needs to price, number and print a sale without the server.
     *
     * @return array<string, mixed>
     */
    public function shopSettings(Shop $shop): array
    {
        $currency = (string) $shop->currency;

        return [
            'name' => $shop->name,
            'address' => $shop->address,
            'phone' => $shop->phone,
            'taxNumber' => $shop->tax_number,
            'logoUrl' => $shop->logo_path ? Storage::disk('public')->url($shop->logo_path) : null,
            'header' => $shop->receipt_header,
            'footer' => $shop->receipt_footer,
            'currency' => $currency,
            'decimals' => (int) $shop->currency_decimals,
            'timezone' => $shop->timezone,
            'taxEnabled' => (bool) $shop->tax_enabled,
            'taxRate' => (float) $shop->tax_rate,
            'pricesIncludeTax' => (bool) $shop->prices_include_tax,
            'maxCashierDiscountPercent' => (float) $shop->setting('max_cashier_discount_percent', 0),
            'invoicePadding' => (int) $shop->setting('invoice_padding', 6),
            'requireOpenShift' => (bool) $shop->setting('require_open_shift'),
            'quickCash' => config("pos.quick_cash.{$currency}") ?? config('pos.quick_cash.default'),
            // Pharmacy checkout (docs/PHARMACY.md §9, branch 8): the till's offline rules.
            'pharmacy' => $shop->hasModule('pharmacy'),
            'rxPrescriber' => (string) $shop->setting('rx_prescriber', 'prompt'),
            'defaultPrescriber' => $shop->setting('default_prescriber'),
            'controlledRegister' => (bool) $shop->setting('controlled_register'),
            'minDaysToExpiryForSale' => max(0, (int) $shop->setting('min_days_to_expiry_for_sale', 0)),
            'opdReceiptMedicines' => (string) $shop->setting('opd_receipt_medicines', 'names'),
            'opdConsultationVariantId' => $this->nullableInt($shop->setting('opd_consultation_variant_id')),
            'bankTransferReferenceRequired' => (bool) $shop->setting('bank_transfer_reference_required'),
            // OPD bills (2026-10-22): warn below this % of the medicines' list value; 0 = never.
            'opdBelowListWarningPercent' => max(0, min(100, (int) $shop->setting('opd_below_list_warning_percent', 100))),
            // Receipts (branch 9): paper width and what the offline receipt prints under each medicine.
            'receipt' => ReceiptService::printOptions($shop),
        ];
    }

    /**
     * @param  Builder<ProductVariant>  $query
     */
    private function changedSince(Builder $query, CarbonInterface $since, Device $device, int $priceListId, bool $fitment): void
    {
        $query->where(fn (Builder $changed) => $changed
            ->where('product_variants.updated_at', '>=', $since)
            ->orWhere('product_variants.deleted_at', '>=', $since)
            ->orWhereHas('product', fn (Builder $product) => $product->where(
                fn (Builder $q) => $q->where('updated_at', '>=', $since)->orWhere('deleted_at', '>=', $since),
            ))
            ->orWhereExists(fn (QueryBuilder $units) => $units->selectRaw('1')
                ->from('variant_units')
                ->whereColumn('variant_units.product_variant_id', 'product_variants.id')
                ->where('variant_units.updated_at', '>=', $since))
            // Any unit's price on the default list (a box's price as well as the tablet's).
            ->orWhereExists(fn (QueryBuilder $prices) => $prices->selectRaw('1')
                ->from('variant_prices')
                ->whereColumn('variant_prices.product_variant_id', 'product_variants.id')
                ->where('variant_prices.price_list_id', $priceListId)
                ->where('variant_prices.updated_at', '>=', $since))
            // Selling loose at a location: only the till's own location matters to it.
            ->orWhereExists(fn (QueryBuilder $rules) => $rules->selectRaw('1')
                ->from('variant_unit_locations')
                ->join('variant_units', 'variant_units.id', '=', 'variant_unit_locations.variant_unit_id')
                ->whereColumn('variant_units.product_variant_id', 'product_variants.id')
                ->where('variant_unit_locations.location_id', $device->location_id)
                ->where('variant_unit_locations.updated_at', '>=', $since))
            ->orWhereHas('stockLevels', fn (Builder $stock) => $stock->where('location_id', $device->location_id)->where('updated_at', '>=', $since))
            ->orWhereExists(fn (QueryBuilder $batches) => $batches->selectRaw('1')
                ->from('stock_batches')
                ->whereColumn('stock_batches.product_variant_id', 'product_variants.id')
                ->where('stock_batches.location_id', $device->location_id)
                ->where('stock_batches.updated_at', '>=', $since))
            ->when($fitment, fn (Builder $q) => $q->orWhereExists(fn (QueryBuilder $fitments) => $fitments->from('product_fitments')
                ->whereColumn('product_fitments.product_id', 'product_variants.product_id')
                ->where('product_fitments.updated_at', '>=', $since))));
    }

    /**
     * Without vehicle_fitment, items carry no fitments (the key stays, so
     * the till's item shape does not change).
     *
     * @return array<string, mixed>
     */
    private function present(ProductVariant $variant, Device $device, bool $fitment): array
    {
        return [
            ...$this->items->present($variant, $device),
            'product_id' => (int) $variant->product_id,
            'is_active' => true,
            'fitments' => ! $fitment ? [] : $variant->product->fitments->map(fn (VehicleModel $model): array => [
                'make_id' => (int) $model->vehicle_make_id,
                'model_id' => (int) $model->getKey(),
                'year_from' => $this->pivotYear($model, 'year_from'),
                'year_to' => $this->pivotYear($model, 'year_to'),
            ])->values()->all(),
        ];
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /** A fitment year from the product_fitments pivot; null means every year. */
    private function pivotYear(VehicleModel $model, string $column): ?int
    {
        $year = $model->getRelation('pivot')->getAttribute($column);

        return $year === null ? null : (int) $year;
    }

    private function vehiclesChanged(?CarbonInterface $since): bool
    {
        return $since === null
            || VehicleMake::query()->where('updated_at', '>=', $since)->exists()
            || VehicleModel::query()->where('updated_at', '>=', $since)->exists();
    }

    /**
     * Same shape as GET /pos/vehicles.
     *
     * @return array<int, mixed>
     */
    private function vehicles(): array
    {
        return array_values(VehicleMake::query()
            ->with(['models' => fn ($q) => $q->select(['id', 'vehicle_make_id', 'name', 'year_from', 'year_to'])->orderBy('name')])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->toArray());
    }
}
