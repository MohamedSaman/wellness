<?php

namespace App\Services;

use App\Enums\AdjustmentReason;
use App\Enums\BillType;
use App\Enums\DrugSchedule;
use App\Enums\GoodsReceiptStatus;
use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Enums\SupplierReturnReason;
use App\Models\AuditLog;
use App\Models\GoodsReceipt;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Shift;
use App\Models\StockBatch;
use App\Models\StockCount;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockWriteOff;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\VariantUnit;
use App\Services\Reports\SalesFact;
use App\Support\CurrentShop;
use App\Support\Quantity;
use App\Support\ReportPeriod;
use App\Support\UnitCost;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sales, stock and profit figures for the dashboard and the admin reports.
 *
 * Every figure is an SQL aggregate; individual sales are never loaded into PHP.
 * The rules, applied everywhere so the reports agree with each other:
 *   - voided sales are left out entirely;
 *   - refunds count on the day they were given (returned_at), so a period's
 *     net sales = sales made in it - refunds given in it;
 *   - days are the shop's days (ReportPeriod), not UTC days;
 *   - a sale counts at its reported_at: the sale time, or the server's time
 *     when an offline till's clock was out (docs/PHARMACY.md §7.5);
 *   - money is in minor units. Cost and profit are only read by the
 *     profit methods, which callers guard with reports.profit.
 */
class ReportService
{
    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly ShiftService $shifts,
        private readonly ExpiryService $expiry,
    ) {}

    /**
     * Completed or partly returned sales (never voided) reported in the
     * period; with a location, the bills rung up there (the counter: the
     * till's location, sales.location_id). Scoped to the current shop.
     */
    private function sales(ReportPeriod $period, ?int $locationId = null): Builder
    {
        return Sale::query()->toBase()
            ->where('sales.status', '!=', SaleStatus::Voided->value)
            ->where('sales.reported_at', '>=', $period->startUtc())
            ->where('sales.reported_at', '<', $period->endUtc())
            ->when($locationId !== null, fn (Builder $query) => $query->where('sales.location_id', $locationId));
    }

    /** Returns (refunds) given in the period; with a location, of bills rung up there. Scoped to the current shop. */
    private function returns(ReportPeriod $period, ?int $locationId = null): Builder
    {
        return SaleReturn::query()->toBase()
            ->where('sale_returns.returned_at', '>=', $period->startUtc())
            ->where('sale_returns.returned_at', '<', $period->endUtc())
            ->when($locationId !== null, fn (Builder $query) => $query->whereExists(fn (Builder $sale) => $sale->selectRaw('1')
                ->from('sales as return_sale')
                ->whereColumn('return_sale.id', 'sale_returns.sale_id')
                ->where('return_sale.location_id', $locationId)));
    }

    /**
     * Lines of the sales reported in the period; with a location, the lines
     * sold from there (sale_items.location_id: the stock pool, which is
     * what per-location sales and profit add up, docs/PHARMACY.md §5).
     */
    private function saleLines(ReportPeriod $period, ?int $locationId = null): Builder
    {
        return $this->sales($period)
            ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->when($locationId !== null, fn (Builder $query) => $query->where('sale_items.location_id', $locationId));
    }

    /** Return lines given in the period, joined to the sale lines they refund; with a location, lines sold from there. */
    private function returnLines(ReportPeriod $period, ?int $locationId = null): Builder
    {
        return $this->returns($period)
            ->join('sale_return_items', 'sale_return_items.sale_return_id', '=', 'sale_returns.id')
            ->join('sale_items', 'sale_items.id', '=', 'sale_return_items.sale_item_id')
            ->when($locationId !== null, fn (Builder $query) => $query->where('sale_items.location_id', $locationId));
    }

    /**
     * @return array{sales_count: int, gross: int, refunds: int, net: int, average: int}
     */
    public function summary(ReportPeriod $period, ?int $locationId = null): array
    {
        $sales = $this->sales($period, $locationId)->selectRaw('COUNT(*) as sales_count, COALESCE(SUM(grand_total), 0) as gross')->first();
        $refunds = (int) $this->returns($period, $locationId)->sum('refund_total');

        $count = (int) ($sales->sales_count ?? 0);
        $gross = (int) ($sales->gross ?? 0);
        $net = $gross - $refunds;

        return [
            'sales_count' => $count,
            'gross' => $gross,
            'refunds' => $refunds,
            'net' => $net,
            'average' => $count === 0 ? 0 : (int) round($net / $count),
        ];
    }

    /**
     * One row per shop day, including days without sales.
     *
     * @return list<array{date: string, sales_count: int, gross: int, discounts: int, tax: int, refunds: int, net: int}>
     */
    public function salesByDay(ReportPeriod $period, ?int $locationId = null): array
    {
        [$saleDay, $saleBindings] = $period->localDaySql('sales.reported_at');
        [$returnDay, $returnBindings] = $period->localDaySql('sale_returns.returned_at');

        $sales = $this->sales($period, $locationId)
            ->selectRaw($saleDay, $saleBindings)
            ->selectRaw('COUNT(*) as sales_count, SUM(grand_total) as gross, SUM(discount_total) as discounts, SUM(tax_total) as tax')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $refunds = $this->returns($period, $locationId)
            ->selectRaw($returnDay, $returnBindings)
            ->selectRaw('SUM(refund_total) as refunds')
            ->groupBy('day')
            ->pluck('refunds', 'day');

        $rows = [];

        foreach ($period->days() as $day) {
            $row = $sales->get($day);
            $gross = (int) ($row->gross ?? 0);
            $refund = (int) ($refunds[$day] ?? 0);

            $rows[] = [
                'date' => $day,
                'sales_count' => (int) ($row->sales_count ?? 0),
                'gross' => $gross,
                'discounts' => (int) ($row->discounts ?? 0),
                'tax' => (int) ($row->tax ?? 0),
                'refunds' => $refund,
                'net' => $gross - $refund,
            ];
        }

        return $rows;
    }

    /**
     * Consultation fees against medicines per shop day (the dashboard's
     * chart): the lines of services (products that don't track stock: the
     * consultation) and of goods (medicines), each net of the refunds given
     * that day, as counters() splits them; with a location, the bills rung
     * up at that counter. Each day's services + goods = salesByDay()'s net.
     *
     * @return list<array{date: string, services: int, goods: int}>
     */
    public function incomeByDay(ReportPeriod $period, ?int $locationId = null): array
    {
        [$saleDay, $saleBindings] = $period->localDaySql('sales.reported_at');
        [$returnDay, $returnBindings] = $period->localDaySql('sale_returns.returned_at');
        $byProduct = fn (Builder $query): Builder => $query
            ->join('product_variants', 'product_variants.id', '=', 'sale_items.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id');

        $sold = $byProduct($this->sales($period, $locationId)->join('sale_items', 'sale_items.sale_id', '=', 'sales.id'))
            ->selectRaw($saleDay, $saleBindings)
            ->selectRaw('products.track_stock as goods, SUM(sale_items.line_total) as amount')
            ->groupBy('day', 'products.track_stock')
            ->get();

        $refunded = $byProduct($this->returns($period, $locationId)
            ->join('sale_return_items', 'sale_return_items.sale_return_id', '=', 'sale_returns.id')
            ->join('sale_items', 'sale_items.id', '=', 'sale_return_items.sale_item_id'))
            ->selectRaw($returnDay, $returnBindings)
            ->selectRaw('products.track_stock as goods, SUM(sale_return_items.refund_amount) as amount')
            ->groupBy('day', 'products.track_stock')
            ->get();

        $days = array_fill_keys($period->days(), ['services' => 0, 'goods' => 0]);

        foreach ([[$sold, 1], [$refunded, -1]] as [$rows, $sign]) {
            foreach ($rows as $row) {
                if (isset($days[$row->day])) {
                    $days[$row->day][(bool) $row->goods ? 'goods' : 'services'] += $sign * (int) $row->amount;
                }
            }
        }

        return array_map(fn (string $day, array $income): array => ['date' => $day, ...$income], array_keys($days), $days);
    }

    /**
     * Reprints count against the user who reprinted the receipt, on the day they did it.
     *
     * @return list<array{cashier: string, sales_count: int, gross: int, refunds: int, net: int, average: int, reprints: int}>
     */
    public function salesByCashier(ReportPeriod $period, ?int $locationId = null): array
    {
        $sales = $this->sales($period, $locationId)
            ->join('users', 'users.id', '=', 'sales.user_id')
            ->selectRaw('users.id as user_id, users.name as cashier, COUNT(*) as sales_count, SUM(sales.grand_total) as gross')
            ->groupBy('users.id', 'users.name')
            ->get();

        // Refunds count against the cashier who made the original sale.
        $refunds = $this->returns($period, $locationId)
            ->join('sales', 'sales.id', '=', 'sale_returns.sale_id')
            ->join('users', 'users.id', '=', 'sales.user_id')
            ->selectRaw('users.id as user_id, users.name as cashier, SUM(sale_returns.refund_total) as refunds')
            ->groupBy('users.id', 'users.name')
            ->get();

        // Audit logs are not tenant-scoped, so the shop is filtered here.
        $reprints = AuditLog::query()->toBase()
            ->join('users', 'users.id', '=', 'audit_logs.user_id')
            ->where('audit_logs.shop_id', $this->currentShop->id())
            ->where('audit_logs.event', ReceiptService::REPRINT_EVENT)
            ->where('audit_logs.created_at', '>=', $period->startUtc())
            ->where('audit_logs.created_at', '<', $period->endUtc())
            // With a location: reprints of the bills rung up there.
            ->when($locationId !== null, fn (Builder $query) => $query
                ->where('audit_logs.auditable_type', (new Sale)->getMorphClass())
                ->whereExists(fn (Builder $sale) => $sale->selectRaw('1')
                    ->from('sales as reprinted_sale')
                    ->whereColumn('reprinted_sale.id', 'audit_logs.auditable_id')
                    ->where('reprinted_sale.location_id', $locationId)))
            ->selectRaw('users.id as user_id, users.name as cashier, COUNT(*) as reprints')
            ->groupBy('users.id', 'users.name')
            ->get();

        $salesAndRefunds = $this->mergeByKey($sales, $refunds, 'user_id', 'cashier', ['sales_count', 'gross'], ['refunds'])
            ->map(fn (array $row): object => (object) $row);

        return array_values($this->mergeByKey($salesAndRefunds, $reprints, 'user_id', 'cashier', ['sales_count', 'gross', 'refunds'], ['reprints'])
            ->map(fn (array $row): array => [
                'cashier' => (string) $row['cashier'],
                'sales_count' => (int) $row['sales_count'],
                'gross' => (int) $row['gross'],
                'refunds' => (int) $row['refunds'],
                'net' => (int) $row['gross'] - (int) $row['refunds'],
                'average' => (int) $row['sales_count'] === 0 ? 0 : (int) round(((int) $row['gross'] - (int) $row['refunds']) / (int) $row['sales_count']),
                'reprints' => (int) $row['reprints'],
            ])
            ->sortByDesc('net')
            ->values()
            ->all());
    }

    /**
     * Sales grouped by product category, brand, drug schedule (the line's
     * snapshot: OTC, prescription only, controlled; lines that are not
     * medicines on their own row) or location (the stock pool each line was
     * sold from). With a location, only the lines sold from there.
     *
     * @param  'category'|'brand'|'schedule'|'location'  $by
     * @return list<array{name: string, quantity: float, gross: int, refunds: int, net: int}>
     */
    public function salesByGroup(ReportPeriod $period, string $by, ?int $locationId = null): array
    {
        $sold = $this->lineFacts($period);
        $returned = $this->returnFacts($period);

        /** @var list<array{name: string, quantity: float, gross: int, refunds: int, net: int}> */
        return $this->salesRows($by, $sold, $returned, $this->itemsOf($sold, $returned), $locationId);
    }

    /**
     * Every sale line reported in the period, added up once per item, stock
     * pool (the line's location), counter (the bill's location), kind of bill
     * and drug schedule (docs/PHARMACY.md §9, branch 11): one pass over the
     * lines that every sales grouping is built from in PHP (by category,
     * brand, schedule, location, item, consultation against medicines), so
     * a report of several tables reads the lines once. A handful of rows per
     * item, however many bills.
     *
     * @return Collection<int, SalesFact>
     */
    private function lineFacts(ReportPeriod $period): Collection
    {
        return $this->saleLines($period)
            ->selectRaw('sale_items.product_variant_id as variant_id, sale_items.location_id as line_location, sales.location_id as bill_location, sales.bill_type as bill_type')
            ->selectRaw("COALESCE(sale_items.drug_schedule, '') as schedule")
            ->selectRaw('SUM(sale_items.quantity) as quantity, SUM(sale_items.base_quantity) as base_quantity, SUM(sale_items.line_total) as gross, SUM(sale_items.line_total - sale_items.tax_amount) as revenue')
            // Cost: each line's own, as stored when it was sold (unit_cost is per unit sold).
            ->selectRaw('SUM(sale_items.unit_cost * sale_items.quantity) as cost')
            ->groupBy('sale_items.product_variant_id', 'sale_items.location_id', 'sales.location_id', 'sales.bill_type', 'schedule')
            ->get()
            ->map(fn (\stdClass $row): SalesFact => SalesFact::fromRow($row));
    }

    /**
     * The return lines given in the period, added up like lineFacts(): the
     * quantity back (as sold and in base units), the refunds, the refunds'
     * share of revenue before tax, and the cost taken back by what was put
     * back on the shelf.
     *
     * @return Collection<int, SalesFact>
     */
    private function returnFacts(ReportPeriod $period): Collection
    {
        return $this->returnLines($period)
            ->join('sales as returned_sales', 'returned_sales.id', '=', 'sale_returns.sale_id')
            ->selectRaw('sale_items.product_variant_id as variant_id, sale_items.location_id as line_location, returned_sales.location_id as bill_location, returned_sales.bill_type as bill_type')
            ->selectRaw("COALESCE(sale_items.drug_schedule, '') as schedule")
            ->selectRaw('SUM(sale_return_items.quantity) as quantity, SUM(sale_return_items.base_quantity) as base_quantity, SUM(sale_return_items.refund_amount) as gross')
            // The refund's share of revenue before tax (1.0 forces decimal division on every database).
            ->selectRaw('SUM(CASE WHEN sale_items.line_total = 0 THEN 0 ELSE sale_return_items.refund_amount * 1.0 * (sale_items.line_total - sale_items.tax_amount) / sale_items.line_total END) as revenue')
            // What goes back on the shelf takes back the original line's cost, by base units
            // (10 tablets of a box of 30: a third of the box's cost); goods not put back stay a cost.
            ->selectRaw('SUM(CASE WHEN sale_return_items.restocked = ? THEN sale_items.unit_cost * sale_return_items.base_quantity * 1.0 / (CASE WHEN sale_items.unit_factor > 0 THEN sale_items.unit_factor ELSE 1 END) ELSE 0 END) as cost', [true])
            ->groupBy('sale_items.product_variant_id', 'sale_items.location_id', 'returned_sales.location_id', 'returned_sales.bill_type', 'schedule')
            ->get()
            ->map(fn (\stdClass $row): SalesFact => SalesFact::fromRow($row));
    }

    /**
     * What the groupings need to know about the items of some facts (deleted
     * items too: old sale lines point at them).
     *
     * @param  Collection<int, covariant SalesFact|\stdClass>  ...$facts
     * @return array<int, array{sku: string, name: string, unit: string, product_id: int, product: string, category_id: int, category: string, brand_id: int, brand: string, goods: bool}>
     */
    private function itemsOf(Collection ...$facts): array
    {
        $ids = array_values(array_unique(array_merge(...array_map(fn (Collection $rows): array => $rows->pluck('variant_id')->all(), $facts))));
        $items = [];

        foreach (array_chunk($ids, 1000) as $chunk) {
            ProductVariant::withTrashed()->toBase()
                ->join('products', 'products.id', '=', 'product_variants.product_id')
                ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
                ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
                ->whereIn('product_variants.id', $chunk)
                ->get([
                    'product_variants.id', 'product_variants.sku', 'product_variants.name as variant', 'products.id as product_id', 'products.name as product',
                    'products.unit', 'products.track_stock', 'categories.id as category_id', 'categories.name as category', 'brands.id as brand_id', 'brands.name as brand',
                ])
                ->each(function (\stdClass $row) use (&$items): void {
                    $items[(int) $row->id] = [
                        'sku' => (string) $row->sku,
                        'name' => trim($row->product.' '.($row->variant ?? '')),
                        'unit' => (string) $row->unit,
                        'product_id' => (int) $row->product_id,
                        'product' => (string) $row->product,
                        'category_id' => (int) ($row->category_id ?? 0),
                        'category' => (string) ($row->category ?? 'No category'),
                        'brand_id' => (int) ($row->brand_id ?? 0),
                        'brand' => (string) ($row->brand ?? 'No brand'),
                        'goods' => (bool) $row->track_stock,
                    ];
                });
        }

        return $items;
    }

    /** @return array<int, string> every location of the shop, inactive ones too, id => name */
    private function locationNames(): array
    {
        return Location::query()->pluck('name', 'id')->all();
    }

    /**
     * Sold less returned, per group: facts (sold, then returned) added up by
     * the key $key gives each, with the name $name gives it.
     *
     * @param  Collection<int, SalesFact>  $sold
     * @param  Collection<int, SalesFact>  $returned
     * @param  callable(SalesFact): (int|string)  $key
     * @param  callable(SalesFact): string  $name
     * @return array<string, array{key: string, name: string, quantity: float, base_quantity: float, gross: int, refunds: int, revenue: float, returned_revenue: float, returned_quantity: float, cost: float, returned_cost: float}>
     */
    private function groupFacts(Collection $sold, Collection $returned, callable $key, callable $name): array
    {
        $groups = [];
        $blank = fn (string $id, string $label): array => ['key' => $id, 'name' => $label, 'quantity' => 0.0, 'base_quantity' => 0.0, 'gross' => 0, 'refunds' => 0, 'revenue' => 0.0, 'returned_revenue' => 0.0, 'returned_quantity' => 0.0, 'cost' => 0.0, 'returned_cost' => 0.0];

        foreach ($sold as $row) {
            $id = (string) $key($row);
            $groups[$id] ??= $blank($id, $name($row));
            $groups[$id]['quantity'] += $row->quantity;
            $groups[$id]['base_quantity'] += $row->base_quantity;
            $groups[$id]['gross'] += $row->gross;
            $groups[$id]['revenue'] += $row->revenue;
            $groups[$id]['cost'] += $row->cost;
        }

        foreach ($returned as $row) {
            $id = (string) $key($row);
            $groups[$id] ??= $blank($id, $name($row));
            $groups[$id]['returned_quantity'] += $row->quantity;
            $groups[$id]['base_quantity'] -= $row->base_quantity;
            $groups[$id]['refunds'] += $row->gross;
            $groups[$id]['returned_revenue'] += $row->revenue;
            $groups[$id]['returned_cost'] += $row->cost;
        }

        return $groups;
    }

    /**
     * Sales by category, brand, schedule, location or item from the facts,
     * optionally only the lines sold from one location: the rows of
     * salesByGroup() and salesByItem().
     *
     * @param  'category'|'brand'|'schedule'|'location'|'item'  $by
     * @param  Collection<int, SalesFact>  $sold
     * @param  Collection<int, SalesFact>  $returned
     * @param  array<int, array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function salesRows(string $by, Collection $sold, Collection $returned, array $items, ?int $locationId): array
    {
        $onlyHere = fn (Collection $rows): Collection => $locationId === null ? $rows : $rows->where('line_location', $locationId);
        $locations = $by === 'location' ? $this->locationNames() : [];

        [$key, $name] = match ($by) {
            'schedule' => [fn (SalesFact $row): string => $row->schedule, fn (SalesFact $row): string => DrugSchedule::tryFrom($row->schedule)?->label() ?? 'Not a medicine'],
            'location' => [fn (SalesFact $row): int => $row->line_location, fn (SalesFact $row): string => (string) ($locations[$row->line_location] ?? '')],
            'brand' => [fn (SalesFact $row): int => $items[$row->variant_id]['brand_id'] ?? 0, fn (SalesFact $row): string => $items[$row->variant_id]['brand'] ?? 'No brand'],
            'item' => [fn (SalesFact $row): int => $row->variant_id, fn (SalesFact $row): string => $items[$row->variant_id]['name'] ?? ''],
            default => [fn (SalesFact $row): int => $items[$row->variant_id]['category_id'] ?? 0, fn (SalesFact $row): string => $items[$row->variant_id]['category'] ?? 'No category'],
        };

        $groups = $this->groupFacts($onlyHere($sold), $onlyHere($returned), $key, $name);

        if ($by === 'item') {
            $schedules = $onlyHere($sold)->groupBy('variant_id')->map(fn (Collection $rows): string => (string) $rows->max('schedule'));
            $packs = $this->largestPacks(array_map('intval', array_keys($groups)));

            return array_values(collect($groups)
                ->map(fn (array $group): array => [
                    'sku' => (string) ($items[(int) $group['key']]['sku'] ?? ''),
                    'item' => $group['name'],
                    'schedule' => DrugSchedule::tryFrom((string) ($schedules[(int) $group['key']] ?? ''))?->shortLabel(),
                    'quantity' => round($group['base_quantity'], 3),
                    'packs' => self::inPacks(round($group['base_quantity'], 3), $packs[(int) $group['key']] ?? null, (string) ($items[(int) $group['key']]['unit'] ?? '')),
                    'gross' => $group['gross'],
                    'refunds' => $group['refunds'],
                    'net' => $group['gross'] - $group['refunds'],
                ])
                ->sortBy([['net', 'desc'], ['sku', 'asc']])
                ->values()
                ->all());
        }

        return array_values(collect($groups)
            ->map(fn (array $group): array => [
                'name' => $group['name'],
                'quantity' => round($group['base_quantity'], 3),
                'gross' => $group['gross'],
                'refunds' => $group['refunds'],
                'net' => $group['gross'] - $group['refunds'],
            ])
            ->sortByDesc('net')
            ->values()
            ->all());
    }

    /**
     * Monthly sales' tables in one read of the lines (salesByGroup() for
     * each grouping, salesByItem(), consultations()): with a location, the
     * groupings are of the lines sold from there and the consultations of
     * the bills rung up there.
     *
     * @param  list<'category'|'brand'|'schedule'|'location'|'item'>  $groupings
     * @return array{groups: array<string, list<array<string, mixed>>>, consultations: array{opd_bills: int, consultations: float, income: int, refunds: int, net: int, opd_medicines: int}}
     */
    public function salesBreakdown(ReportPeriod $period, array $groupings, ?int $locationId = null): array
    {
        $sold = $this->lineFacts($period);
        $returned = $this->returnFacts($period);
        $items = $this->itemsOf($sold, $returned);
        $groups = [];

        foreach ($groupings as $by) {
            $groups[$by] = $this->salesRows($by, $sold, $returned, $items, $locationId);
        }

        return ['groups' => $groups, 'consultations' => $this->consultationsFrom($period, $sold, $returned, $items, $locationId)];
    }

    /**
     * Money taken per payment method. Cash is net of change given back.
     *
     * @return list<array{key: string, method: string, payments: int, taken: int, refunds: int, net: int}>
     */
    public function paymentMethods(ReportPeriod $period, ?int $locationId = null): array
    {
        $taken = $this->sales($period, $locationId)
            ->join('payments', 'payments.sale_id', '=', 'sales.id')
            ->selectRaw('payments.method as method, COUNT(*) as payments, SUM(payments.amount) as taken')
            ->groupBy('payments.method')
            ->get()
            ->keyBy('method');

        $change = (int) $this->sales($period, $locationId)->sum('change_due');

        $refunds = $this->returns($period, $locationId)
            ->selectRaw('refund_method as method, SUM(refund_total) as refunds')
            ->groupBy('refund_method')
            ->pluck('refunds', 'method');

        $rows = [];

        foreach (PaymentMethod::cases() as $method) {
            $row = $taken->get($method->value);
            $amount = (int) ($row->taken ?? 0) - ($method === PaymentMethod::Cash ? $change : 0);
            $refund = (int) ($refunds[$method->value] ?? 0);

            if ($row === null && $refund === 0) {
                continue;
            }

            $rows[] = [
                'key' => $method->value,
                'method' => ucwords(str_replace('_', ' ', $method->value)),
                'payments' => (int) ($row->payments ?? 0),
                'taken' => $amount,
                'refunds' => $refund,
                'net' => $amount - $refund,
            ];
        }

        return $rows;
    }

    /**
     * Voided sales and returns in the period, newest first, with their reasons
     * and whether the goods went back into stock (a void always restocks; a
     * return of faulty goods may not).
     *
     * @return list<array{when: string, type: string, reference: string, amount: int, reason: string|null, stock: string, by_user: string|null}>
     */
    public function voidsAndReturns(ReportPeriod $period, ?int $locationId = null): array
    {
        $voids = $this->voids($period, $locationId)
            ->leftJoin('users', 'users.id', '=', 'sales.voided_by')
            ->get(['sales.voided_at as at', 'sales.invoice_no as reference', 'sales.grand_total as amount', 'sales.void_reason as reason', 'users.name as by_user'])
            ->map(fn ($row): array => ['type' => 'Void', 'stock' => 'Restocked', ...(array) $row]);

        $returns = $this->returns($period, $locationId)
            ->leftJoin('users', 'users.id', '=', 'sale_returns.user_id')
            ->selectRaw('sale_returns.returned_at as at, sale_returns.return_no as reference, sale_returns.refund_total as amount, sale_returns.reason_code, sale_returns.note, users.name as by_user')
            ->selectRaw('(select max(case when sale_return_items.restocked then 1 else 0 end) from sale_return_items where sale_return_items.sale_return_id = sale_returns.id) as restocked')
            ->get()
            ->map(fn ($row): array => [
                'type' => 'Return',
                'at' => $row->at,
                'reference' => $row->reference,
                'amount' => $row->amount,
                'reason' => implode(' — ', array_filter([ReturnReason::tryFrom((string) $row->reason_code)?->label(), $row->note], fn ($part): bool => filled($part))) ?: null,
                'stock' => (int) $row->restocked === 1 ? 'Restocked' : 'Not restocked',
                'by_user' => $row->by_user,
            ]);

        return array_values($voids->concat($returns)
            ->sortByDesc('at')
            ->map(fn (array $row): array => [
                'when' => (string) $period->local($row['at']),
                'type' => (string) $row['type'],
                'reference' => (string) $row['reference'],
                'amount' => (int) $row['amount'],
                'reason' => $row['reason'] === null ? null : (string) $row['reason'],
                'stock' => (string) $row['stock'],
                'by_user' => $row['by_user'] === null ? null : (string) $row['by_user'],
            ])
            ->values()
            ->all());
    }

    /** Sales voided in the period (by when they were voided); with a location, bills rung up there. */
    private function voids(ReportPeriod $period, ?int $locationId = null): Builder
    {
        return Sale::query()->toBase()
            ->where('sales.status', SaleStatus::Voided->value)
            ->where('sales.voided_at', '>=', $period->startUtc())
            ->where('sales.voided_at', '<', $period->endUtc())
            ->when($locationId !== null, fn (Builder $query) => $query->where('sales.location_id', $locationId));
    }

    /**
     * Shifts opened in the period, newest first; with a location, on the tills there.
     *
     * @return list<array<string, mixed>>
     */
    public function shiftHistory(ReportPeriod $period, ?int $locationId = null): array
    {
        return array_values(Shift::query()->toBase()
            ->join('devices', 'devices.id', '=', 'shifts.device_id')
            ->join('users', 'users.id', '=', 'shifts.user_id')
            ->where('shifts.opened_at', '>=', $period->startUtc())
            ->where('shifts.opened_at', '<', $period->endUtc())
            ->when($locationId !== null, fn (Builder $query) => $query->where('devices.location_id', $locationId))
            ->orderByDesc('shifts.opened_at')
            ->get([
                'devices.code as till', 'users.name as cashier', 'shifts.opened_at', 'shifts.closed_at',
                'shifts.opening_cash', 'shifts.expected_cash', 'shifts.counted_cash', 'shifts.difference',
            ])
            ->map(fn ($row): array => [
                'till' => (string) $row->till,
                'cashier' => (string) $row->cashier,
                'opened_at' => $period->local($row->opened_at),
                'closed_at' => $period->local($row->closed_at) ?? 'Open',
                'opening_cash' => (int) $row->opening_cash,
                'expected_cash' => $row->expected_cash === null ? null : (int) $row->expected_cash,
                'counted_cash' => $row->counted_cash === null ? null : (int) $row->counted_cash,
                'difference' => $row->difference === null ? null : (int) $row->difference,
            ])
            ->all());
    }

    /** Cash that should be in the drawers of all open shifts right now. */
    public function cashInOpenShifts(): int
    {
        // One query per open shift: a shop has a handful of tills, and this keeps
        // the figure identical to what the shift close screen shows.
        return (int) Shift::query()->whereNull('closed_at')->get()
            ->sum(fn (Shift $shift): int => $this->shifts->expectedCash($shift));
    }

    /**
     * Stock levels holding stock: each item at each location (or at one
     * location), never below zero. Stock below zero (sold offline, not yet
     * booked in) has no value: it is a count to correct, so every stock value
     * uses max(on_hand, 0), at the location's own average cost.
     */
    private function sellableLevels(?int $locationId): Builder
    {
        return StockLevel::query()->toBase()
            ->where('stock_levels.quantity', '>', 0)
            ->when($locationId !== null, fn (Builder $query) => $query->where('stock_levels.location_id', $locationId));
    }

    /**
     * Value at cost of all stock on hand: max(on_hand, 0) x that location's
     * average cost, per item per location. CALLERS MUST CHECK cost.view.
     */
    public function stockValueAtCost(?int $locationId = null): int
    {
        $value = $this->sellableLevels($locationId)->sum(DB::raw('stock_levels.quantity * stock_levels.average_cost'));

        return (int) round((float) $value);
    }

    /** Stock-tracked variants with less than zero on hand (at any location, or at the one given). */
    public function negativeStockCount(?int $locationId = null): int
    {
        return ProductVariant::query()->negativeStock($locationId)->count();
    }

    /**
     * Stock on hand by category, location or item, valued at selling price,
     * and when $withCost at average cost and at last cost, each location's
     * stock at that location's costs (weighted average). Stock below zero
     * counts as zero (see sellableLevels).
     *
     * @param  'category'|'location'|'item'  $by
     * @return list<array{category?: string, location?: string, sku?: string, item?: string, quantity: float, retail_value: int, cost_value?: int, last_cost_value?: int}>
     */
    public function stockValuation(bool $withCost, ?int $locationId = null, string $by = 'category'): array
    {
        $priceListId = $this->currentShop->getOrFail()->defaultPriceList()->getKey();

        $query = $this->sellableLevels($locationId)
            ->join('product_variants', 'product_variants.id', '=', 'stock_levels.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            // Retail value at the base unit's price (stock is held in base units).
            ->leftJoin('variant_units as base_unit', fn ($join) => $join
                ->on('base_unit.product_variant_id', '=', 'product_variants.id')
                ->where('base_unit.is_base', '=', true))
            ->leftJoin('variant_prices', fn ($join) => $join
                ->on('variant_prices.variant_unit_id', '=', 'base_unit.id')
                ->where('variant_prices.price_list_id', '=', $priceListId)
                ->where('variant_prices.min_quantity', '=', 1))
            ->selectRaw('SUM(stock_levels.quantity) as quantity, SUM(stock_levels.quantity * COALESCE(variant_prices.price, 0)) as retail_value, SUM(stock_levels.quantity * stock_levels.average_cost) as cost_value, SUM(stock_levels.quantity * COALESCE(stock_levels.last_cost, stock_levels.average_cost)) as last_cost_value');

        $rows = match ($by) {
            'location' => $query->join('locations', 'locations.id', '=', 'stock_levels.location_id')
                ->addSelect('locations.name as location')
                ->groupBy('locations.id', 'locations.name', 'locations.sort_order')
                ->orderBy('locations.sort_order')
                ->orderBy('locations.id')
                ->get(),
            'item' => $query->addSelect('product_variants.sku as sku', 'products.name as product', 'product_variants.name as variant')
                ->groupBy('product_variants.id', 'product_variants.sku', 'products.name', 'product_variants.name')
                ->orderBy('products.name')
                ->orderBy('product_variants.sku')
                ->get(),
            default => $query->leftJoin('categories', 'categories.id', '=', 'products.category_id')
                ->selectRaw('COALESCE(categories.name, ?) as category', ['No category'])
                ->groupBy('category')
                ->orderBy('category')
                ->get(),
        };

        return array_values($rows->map(function ($row) use ($withCost, $by): array {
            $values = match ($by) {
                'location' => ['location' => (string) $row->location],
                'item' => ['sku' => (string) $row->sku, 'item' => trim($row->product.' '.($row->variant ?? ''))],
                default => ['category' => (string) $row->category],
            };

            $values += [
                'quantity' => round((float) $row->quantity, 3),
                'retail_value' => (int) round((float) $row->retail_value),
            ];

            if ($withCost) {
                $values['cost_value'] = (int) round((float) $row->cost_value);
                $values['last_cost_value'] = (int) round((float) $row->last_cost_value);
            }

            return $values;
        })->all());
    }

    /**
     * Stock by batch (docs/PHARMACY.md §9, branch 11): every real batch
     * holding stock, at each location, valued at the batch's own purchase
     * cost (what it cost us: write-offs and supplier credits use it), next
     * to nothing else; the unallocated batch has no cost of its own and is
     * left out (the stock levels hold it). Soonest expiry first.
     * CALLERS MUST CHECK cost.view for the cost and value.
     *
     * @return list<array{location: string, sku: string, item: string, batch: string, expiry: string|null, quantity: float, unit_cost: int, value: int}>
     */
    public function batchValuation(?int $locationId = null): array
    {
        return array_values(StockBatch::query()->toBase()
            ->join('product_variants', 'product_variants.id', '=', 'stock_batches.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->join('locations', 'locations.id', '=', 'stock_batches.location_id')
            ->where('stock_batches.is_unallocated', false)
            ->where('stock_batches.quantity', '>', 0)
            ->when($locationId !== null, fn (Builder $query) => $query->where('stock_batches.location_id', $locationId))
            ->orderBy('locations.sort_order')
            ->orderBy('locations.id')
            ->orderByRaw('stock_batches.expiry_date IS NULL')
            ->orderBy('stock_batches.expiry_date')
            ->orderBy('products.name')
            ->orderBy('stock_batches.id')
            ->get([
                'locations.name as location', 'product_variants.sku', 'products.name as product', 'product_variants.name as variant',
                'stock_batches.batch_no', 'stock_batches.expiry_date', 'stock_batches.quantity', 'stock_batches.unit_cost',
            ])
            ->map(fn ($row): array => [
                'location' => (string) $row->location,
                'sku' => (string) $row->sku,
                'item' => trim($row->product.' '.($row->variant ?? '')),
                'batch' => (string) $row->batch_no,
                'expiry' => $row->expiry_date === null ? null : date('m/Y', (int) strtotime((string) $row->expiry_date)),
                'quantity' => round((float) $row->quantity, 3),
                'unit_cost' => UnitCost::toMinor((string) $row->unit_cost),
                'value' => UnitCost::times((string) $row->unit_cost, (float) $row->quantity),
            ])
            ->all());
    }

    /**
     * The expiry report (docs/PHARMACY.md §9, branch 11): batches holding
     * stock that have expired, or expire within $days days (null: the
     * shop's warning window), by ExpiryService's rules, each valued at its
     * batch cost. Soonest expiry first. CALLERS MUST CHECK cost.view for the value.
     *
     * @return list<array{location: string, sku: string, item: string, batch: string, expiry: string|null, days_left: int|null, quantity: float, value: int}>
     */
    public function expiry(string $list, ?int $days = null, ?int $locationId = null): array
    {
        return array_values($this->expiry->query($list, $locationId, $days)
            ->with(['location', 'variant' => fn ($variant) => $variant->withTrashed()->with('product')])
            ->get()
            ->map(fn (StockBatch $batch): array => [
                'location' => (string) $batch->location?->name,
                'sku' => (string) $batch->variant->sku,
                'item' => trim($batch->variant->product?->name.' '.($batch->variant->name ?? '')),
                'batch' => (string) $batch->batch_no,
                'expiry' => $batch->expiry_date?->format('d/m/Y'),
                'days_left' => $this->expiry->daysLeft($batch),
                'quantity' => round((float) $batch->quantity, 3),
                'value' => $this->expiry->value($batch),
            ])
            ->all());
    }

    /**
     * Slow-moving and dead stock (docs/PHARMACY.md §9, branch 11): each item
     * holding stock at a location, with when it was last sold there, how
     * much sold there in the last $days days, and the stock's value at the
     * location's average cost. Dead stock: not sold there for $days days
     * (or never), and held there for longer than that (stock that arrived
     * within the window is not dead yet). $deadOnly lists only those.
     * Voided sales don't count as sold. Highest value first.
     * CALLERS MUST CHECK cost.view for the value.
     *
     * @return list<array{location: string, sku: string, item: string, on_hand: float, last_sold: string|null, days_since: int|null, sold: float, stocked_since: string|null, dead: bool, value: int}>
     */
    public function slowMoving(int $days, ?int $locationId = null, bool $deadOnly = true): array
    {
        $shop = $this->currentShop->getOrFail();
        $timezone = (string) ($shop->timezone ?: config('app.timezone'));
        $now = CarbonImmutable::now($timezone);
        $cutoff = $now->startOfDay()->subDays(max(1, $days) - 1)->utc()->format('Y-m-d H:i:s');
        $saleMorph = (new Sale)->getMorphClass();

        // A sale movement of this level that is not part of a voided sale.
        $soldHere = fn (Builder $query): Builder => $query->from('stock_movements as sold')
            ->whereColumn('sold.product_variant_id', 'stock_levels.product_variant_id')
            ->whereColumn('sold.location_id', 'stock_levels.location_id')
            ->where('sold.type', StockMovementType::Sale->value)
            ->whereNotExists(fn (Builder $voided) => $voided->selectRaw('1')
                ->from('sales as voided_sale')
                ->whereColumn('voided_sale.id', 'sold.reference_id')
                ->where('sold.reference_type', $saleMorph)
                ->where('voided_sale.status', SaleStatus::Voided->value));

        $rows = $this->sellableLevels($locationId)
            ->join('product_variants', 'product_variants.id', '=', 'stock_levels.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->join('locations', 'locations.id', '=', 'stock_levels.location_id')
            ->where('products.track_stock', true)
            ->select([
                'stock_levels.quantity', 'stock_levels.average_cost', 'locations.name as location',
                'product_variants.sku', 'products.name as product', 'product_variants.name as variant',
            ])
            ->selectSub(fn (Builder $query) => $soldHere($query)->selectRaw('MAX(sold.occurred_at)'), 'last_sold')
            ->selectSub(fn (Builder $query) => $soldHere($query)->where('sold.occurred_at', '>=', $cutoff)->selectRaw('COALESCE(SUM(-sold.quantity), 0)'), 'sold')
            ->selectSub(fn (Builder $query) => $query->from('stock_movements as first_in')
                ->whereColumn('first_in.product_variant_id', 'stock_levels.product_variant_id')
                ->whereColumn('first_in.location_id', 'stock_levels.location_id')
                ->selectRaw('MIN(first_in.occurred_at)'), 'stocked_since')
            ->get();

        return array_values($rows
            ->map(function ($row) use ($cutoff, $now, $timezone): array {
                $lastSold = $row->last_sold === null ? null : CarbonImmutable::parse((string) $row->last_sold, 'UTC')->setTimezone($timezone);
                $since = $row->stocked_since === null ? null : CarbonImmutable::parse((string) $row->stocked_since, 'UTC')->setTimezone($timezone);
                $dead = ($row->last_sold === null || (string) $row->last_sold < $cutoff)
                    && ($row->stocked_since === null || (string) $row->stocked_since < $cutoff);

                return [
                    'location' => (string) $row->location,
                    'sku' => (string) $row->sku,
                    'item' => trim($row->product.' '.($row->variant ?? '')),
                    'on_hand' => round((float) $row->quantity, 3),
                    'last_sold' => $lastSold?->format('Y-m-d'),
                    'days_since' => $lastSold === null ? null : (int) $lastSold->startOfDay()->diffInDays($now->startOfDay()),
                    'sold' => round((float) $row->sold, 3),
                    'stocked_since' => $since?->format('Y-m-d'),
                    'dead' => $dead,
                    'value' => UnitCost::times((string) $row->average_cost, (float) $row->quantity),
                ];
            })
            ->filter(fn (array $row): bool => ! $deadOnly || $row['dead'])
            ->sortBy([['value', 'desc'], ['sku', 'asc']])
            ->values()
            ->all());
    }

    /**
     * Stock moved between locations in the period (docs/PHARMACY.md §9,
     * branch 11: General Pharmacy to Doctor's Medicines), per route and
     * item: transfers, quantity in base units and its value at the source
     * location's average (what the transfer carried). Optionally one
     * source and one destination. CALLERS MUST CHECK cost.view for the value.
     *
     * @return list<array{route: string, sku: string, item: string, transfers: int, quantity: float, packs: string|null, value: int}>
     */
    public function transfers(ReportPeriod $period, ?int $fromId = null, ?int $toId = null): array
    {
        $rows = StockTransfer::query()->toBase()
            ->join('stock_transfer_items', 'stock_transfer_items.stock_transfer_id', '=', 'stock_transfers.id')
            ->join('locations as from_locations', 'from_locations.id', '=', 'stock_transfers.from_location_id')
            ->join('locations as to_locations', 'to_locations.id', '=', 'stock_transfers.to_location_id')
            ->where('stock_transfers.transferred_at', '>=', $period->startUtc())
            ->where('stock_transfers.transferred_at', '<', $period->endUtc())
            ->when($fromId !== null, fn (Builder $query) => $query->where('stock_transfers.from_location_id', $fromId))
            ->when($toId !== null, fn (Builder $query) => $query->where('stock_transfers.to_location_id', $toId))
            ->selectRaw('from_locations.name as from_name, to_locations.name as to_name, stock_transfer_items.product_variant_id as variant_id')
            ->selectRaw('COUNT(DISTINCT stock_transfers.id) as transfers, SUM(stock_transfer_items.base_quantity) as quantity, SUM(stock_transfer_items.base_quantity * stock_transfer_items.unit_cost) as value')
            ->groupBy('from_locations.id', 'from_locations.name', 'to_locations.id', 'to_locations.name', 'stock_transfer_items.product_variant_id')
            ->get();

        $variants = $this->itemsOf($rows->map(fn (\stdClass $row): object => (object) ['variant_id' => (int) $row->variant_id]));
        $packs = $this->largestPacks(array_keys($variants));

        return array_values($rows
            ->map(fn ($row): array => [
                'route' => $row->from_name.' → '.$row->to_name,
                'sku' => $variants[(int) $row->variant_id]['sku'] ?? '',
                'item' => $variants[(int) $row->variant_id]['name'] ?? '',
                'transfers' => (int) $row->transfers,
                'quantity' => round((float) $row->quantity, 3),
                'packs' => self::inPacks(round((float) $row->quantity, 3), $packs[(int) $row->variant_id] ?? null, $variants[(int) $row->variant_id]['unit'] ?? ''),
                'value' => (int) round((float) $row->value),
            ])
            ->sortBy([['route', 'asc'], ['item', 'asc']])
            ->values()
            ->all());
    }

    /**
     * The controlled-drug register (docs/PHARMACY.md §4.10, Q3): every
     * movement of a controlled medicine in the period, per location, oldest
     * first: date, patient, prescriber, medicine, batch, quantity in and
     * out, and the running balance at the location (the medicine's
     * batches added up, the ledger's balance_after) and of the batch.
     * Patient and prescriber come from the sale (customer name, the line's
     * prescriber and Rx reference) for sales and their returns; receipts,
     * transfers and adjustments name their document or reason instead.
     * Each medicine and location opens with its balance brought forward.
     *
     * @return list<array<string, mixed>>
     */
    public function controlledRegister(ReportPeriod $period, ?int $locationId = null, ?int $variantId = null): array
    {
        $controlled = fn (Builder $query): Builder => $query
            ->join('product_variants', 'product_variants.id', '=', 'stock_movements.product_variant_id')
            ->join('drug_details', 'drug_details.product_id', '=', 'product_variants.product_id')
            ->where('drug_details.schedule', DrugSchedule::Controlled->value)
            ->when($locationId !== null, fn (Builder $query) => $query->where('stock_movements.location_id', $locationId))
            ->when($variantId !== null, fn (Builder $query) => $query->where('stock_movements.product_variant_id', $variantId));

        // Balance brought forward: each medicine's last balance at each location before the period.
        $forward = $controlled(StockMovement::query()->toBase())
            ->where('stock_movements.occurred_at', '<', $period->startUtc())
            ->whereIn('stock_movements.id', fn (Builder $last) => $controlled($last->from('stock_movements'))
                ->where('stock_movements.occurred_at', '<', $period->startUtc())
                ->selectRaw('MAX(stock_movements.id)')
                ->groupBy('stock_movements.product_variant_id', 'stock_movements.location_id'))
            ->get(['stock_movements.product_variant_id', 'stock_movements.location_id', 'stock_movements.balance_after'])
            ->keyBy(fn ($row): string => $row->product_variant_id.':'.$row->location_id);

        $saleMorph = (new Sale)->getMorphClass();
        $returnMorph = (new SaleReturn)->getMorphClass();

        $movements = $this->stockMovementsQuery($period, false, ['location_id' => $locationId])
            ->reorder()
            ->join('drug_details', 'drug_details.product_id', '=', 'products.id')
            ->where('drug_details.schedule', DrugSchedule::Controlled->value)
            ->when($variantId !== null, fn (Builder $query) => $query->where('stock_movements.product_variant_id', $variantId))
            ->leftJoin('sale_item_batches as sold_batch', 'sold_batch.stock_movement_id', '=', 'stock_movements.id')
            ->leftJoin('sale_items as sold_line', 'sold_line.id', '=', 'sold_batch.sale_item_id')
            ->addSelect([
                'stock_movements.product_variant_id', 'stock_movements.location_id', 'stock_movements.batch_balance_after',
                'drug_details.strength_value', 'drug_details.strength_unit',
                'ref_sales.customer_name as sale_patient', 'return_sales.customer_name as return_patient',
                'sold_line.prescriber as sale_prescriber', 'sold_line.prescription_ref as sale_rx',
            ])
            ->selectSub(fn (Builder $query) => $query->from('sale_items as returned_line')
                ->join('sale_return_items as returned_item', 'returned_item.sale_item_id', '=', 'returned_line.id')
                ->whereColumn('returned_item.sale_return_id', 'stock_movements.reference_id')
                ->where('stock_movements.reference_type', $returnMorph)
                ->whereColumn('returned_line.product_variant_id', 'stock_movements.product_variant_id')
                ->selectRaw('MAX(returned_line.prescriber)'), 'return_prescriber')
            ->addSelect(['ref_grns.supplier_id'])
            ->orderBy('locations.sort_order')
            ->orderBy('stock_movements.location_id')
            ->orderBy('products.name')
            ->orderBy('stock_movements.product_variant_id')
            ->orderBy('stock_movements.occurred_at')
            ->orderBy('stock_movements.id')
            ->get();

        $suppliers = Supplier::query()->whereIn('id', $movements->pluck('supplier_id')->filter()->unique()->all())->pluck('name', 'id');
        $rows = [];
        $current = null;

        foreach ($movements as $row) {
            $key = $row->product_variant_id.':'.$row->location_id;
            $strength = $row->strength_value === null ? '' : ' '.trim(Quantity::format((float) $row->strength_value).' '.$row->strength_unit);
            $medicine = trim($row->product.' '.($row->variant ?? '')).(str_contains((string) $row->product, trim($strength)) ? '' : $strength);

            if ($key !== $current) {
                $current = $key;
                $rows[] = [
                    'when' => $period->local($period->startUtc()),
                    'location' => (string) $row->location,
                    'medicine' => $medicine,
                    'batch' => null,
                    'document' => 'Balance brought forward',
                    'patient' => null,
                    'prescriber' => null,
                    'in' => null,
                    'out' => null,
                    'balance' => round((float) ($forward->get($key)->balance_after ?? 0), 3),
                    'batch_balance' => null,
                ];
            }

            $quantity = round((float) $row->quantity, 3);
            $present = $this->presentMovement($row, $period);
            $isSale = $row->reference_type === $saleMorph;
            $isReturn = $row->reference_type === $returnMorph;
            $prescriber = $isSale ? $row->sale_prescriber : ($isReturn ? $row->return_prescriber : null);

            $rows[] = [
                'when' => $present['when'],
                'location' => (string) $row->location,
                'medicine' => $medicine,
                'batch' => $present['batch'] ?? null,
                'document' => trim($present['type'].' '.($present['reference'] === 'Manual' || $present['reference'] === '-' ? '' : $present['reference']))
                    .(isset($row->supplier_id, $suppliers[$row->supplier_id]) ? ' · '.$suppliers[$row->supplier_id] : '')
                    .($present['reason'] !== null && ! $isSale ? ' · '.$present['reason'] : ''),
                'patient' => $isSale ? $row->sale_patient : ($isReturn ? $row->return_patient : null),
                'prescriber' => $prescriber === null ? null : trim($prescriber.($isSale && filled($row->sale_rx) ? ' (Rx '.$row->sale_rx.')' : '')),
                'in' => $quantity > 0 ? $quantity : null,
                'out' => $quantity < 0 ? -$quantity : null,
                'balance' => round((float) $row->balance_after, 3),
                'batch_balance' => $row->batch_balance_after === null ? null : round((float) $row->batch_balance_after, 3),
            ];
        }

        return $rows;
    }

    /** Whether the controlled-drug register is switched on for the shop (pharmacy, shop setting controlled_register). */
    public function controlledRegisterEnabled(): bool
    {
        $shop = $this->currentShop->get();

        return $shop !== null && $shop->hasModule('pharmacy') && (bool) $shop->setting('controlled_register', false);
    }

    /**
     * Items at or below their reorder level, one row per item per location
     * (ProductVariant::scopeLowStock() says which locations count), lowest
     * stock first. A location's own reorder level overrides the item's.
     *
     * @return list<array{variant_id: int, location_id: int, location: string, sku: string, product: string, variant: string|null, on_hand: float, reorder_level: float, par_level: float|null, bin_location: string|null}>
     */
    public function lowStock(?int $limit = null, ?int $locationId = null): array
    {
        return array_values($this->lowStockRows($locationId)
            ->select(['product_variants.id', 'product_variants.sku', 'product_variants.name', 'product_variants.bin_location', 'products.name as product_name', 'locations.id as location_id', 'locations.name as location_name'])
            ->selectRaw('COALESCE(stock_levels.quantity, 0) as on_hand')
            ->selectRaw('COALESCE(stock_levels.reorder_level, product_variants.reorder_level) as reorder_at')
            ->addSelect('stock_levels.par_level')
            ->orderBy('on_hand')
            ->orderBy('product_variants.sku')
            ->orderBy('locations.sort_order')
            ->orderBy('locations.id')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get()
            ->map(fn ($row): array => [
                'variant_id' => (int) $row->id,
                'location_id' => (int) $row->location_id,
                'location' => (string) $row->location_name,
                'sku' => (string) $row->sku,
                'product' => (string) $row->product_name,
                'variant' => $row->name === null ? null : (string) $row->name,
                'on_hand' => round((float) $row->on_hand, 3),
                'reorder_level' => round((float) $row->reorder_at, 3),
                'par_level' => $row->par_level === null ? null : round((float) $row->par_level, 3),
                'bin_location' => $row->bin_location === null ? null : (string) $row->bin_location,
            ])
            ->values()
            ->all());
    }

    /** How many rows lowStock() has: item and location pairs at or below their reorder level. */
    public function lowStockCount(?int $locationId = null): int
    {
        return $this->lowStockRows($locationId)->count();
    }

    /** Item and location pairs at or below their reorder level (see ProductVariant::scopeLowStock()). */
    private function lowStockRows(?int $locationId): Builder
    {
        return ProductVariant::query()->toBase()
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('products.track_stock', true)
            ->join('locations', fn ($join) => $join
                ->on('locations.shop_id', '=', 'product_variants.shop_id')
                ->where('locations.is_active', '=', true))
            ->leftJoin('stock_levels', fn ($join) => $join
                ->on('stock_levels.product_variant_id', '=', 'product_variants.id')
                ->on('stock_levels.location_id', '=', 'locations.id'))
            ->tap(fn (Builder $query) => ProductVariant::whereReorderNeeded($query))
            ->when($locationId !== null, fn (Builder $query) => $query->where('locations.id', $locationId));
    }

    /**
     * Stock movements in the period matching the filters, with what they are
     * needed for on screen and in the CSV. Not ordered (see stockMovementsQuery).
     *
     * @param  array{types?: list<string>, product?: string|null, user_id?: int|null, location_id?: int|null}  $filters
     */
    private function movements(ReportPeriod $period, array $filters): Builder
    {
        $types = array_values(array_filter($filters['types'] ?? [], fn ($type): bool => StockMovementType::tryFrom((string) $type) !== null));
        $product = trim((string) ($filters['product'] ?? ''));
        $userId = $filters['user_id'] ?? null;
        $locationId = $filters['location_id'] ?? null;

        return StockMovement::query()->toBase()
            ->join('product_variants', 'product_variants.id', '=', 'stock_movements.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('stock_movements.occurred_at', '>=', $period->startUtc())
            ->where('stock_movements.occurred_at', '<', $period->endUtc())
            ->when($types !== [], fn (Builder $query) => $query->whereIn('stock_movements.type', $types))
            ->when($userId !== null, fn (Builder $query) => $query->where('stock_movements.user_id', $userId))
            ->when($locationId !== null, fn (Builder $query) => $query->where('stock_movements.location_id', $locationId))
            ->when($product !== '', function (Builder $query) use ($product): void {
                $like = '%'.addcslashes($product, '%_\\').'%';

                $query->where(fn (Builder $match) => $match
                    ->where('product_variants.sku', 'like', $like)
                    ->orWhere('product_variants.barcode', $product)
                    ->orWhere('products.name', 'like', $like)
                    ->orWhere('products.part_number', 'like', $like));
            });
    }

    /**
     * Stock movements in the period, newest first, with the document that caused
     * each one (sale invoice, return number, transfer, goods received note,
     * supplier return) and who made it. Unit cost only when $withCost.
     *
     * @param  array{types?: list<string>, product?: string|null, user_id?: int|null, location_id?: int|null}  $filters
     */
    public function stockMovementsQuery(ReportPeriod $period, bool $withCost, array $filters = []): Builder
    {
        $columns = [
            'stock_movements.id', 'stock_movements.occurred_at', 'product_variants.sku', 'products.name as product',
            'product_variants.name as variant', 'stock_movements.type', 'stock_movements.quantity', 'stock_movements.balance_after',
            'stock_movements.reason_code', 'stock_movements.note', 'stock_movements.reference_type', 'users.name as by_user',
            'ref_sales.invoice_no as sale_invoice', 'ref_sales.uuid as sale_uuid',
            'ref_returns.return_no as return_no', 'return_sales.uuid as return_sale_uuid',
            'locations.name as location', 'ref_transfers.transfer_number as transfer_number',
            'ref_grns.grn_number as grn_number', 'ref_supplier_returns.return_number as supplier_return_number',
            'ref_write_offs.write_off_number as write_off_number', 'ref_counts.count_number as count_number',
            'movement_batches.batch_no as batch_no', 'movement_batches.expiry_date as batch_expiry', 'movement_batches.is_unallocated as batch_unallocated',
        ];

        if ($withCost) {
            $columns[] = 'stock_movements.unit_cost';
        }

        return $this->movements($period, $filters)
            ->leftJoin('users', 'users.id', '=', 'stock_movements.user_id')
            ->leftJoin('locations', 'locations.id', '=', 'stock_movements.location_id')
            ->leftJoin('stock_batches as movement_batches', 'movement_batches.id', '=', 'stock_movements.stock_batch_id')
            ->leftJoin('stock_transfers as ref_transfers', fn ($join) => $join
                ->on('ref_transfers.id', '=', 'stock_movements.reference_id')
                ->where('stock_movements.reference_type', '=', (new StockTransfer)->getMorphClass()))
            ->leftJoin('goods_receipts as ref_grns', fn ($join) => $join
                ->on('ref_grns.id', '=', 'stock_movements.reference_id')
                ->where('stock_movements.reference_type', '=', (new GoodsReceipt)->getMorphClass()))
            ->leftJoin('supplier_returns as ref_supplier_returns', fn ($join) => $join
                ->on('ref_supplier_returns.id', '=', 'stock_movements.reference_id')
                ->where('stock_movements.reference_type', '=', (new SupplierReturn)->getMorphClass()))
            ->leftJoin('stock_write_offs as ref_write_offs', fn ($join) => $join
                ->on('ref_write_offs.id', '=', 'stock_movements.reference_id')
                ->where('stock_movements.reference_type', '=', (new StockWriteOff)->getMorphClass()))
            ->leftJoin('stock_counts as ref_counts', fn ($join) => $join
                ->on('ref_counts.id', '=', 'stock_movements.reference_id')
                ->where('stock_movements.reference_type', '=', (new StockCount)->getMorphClass()))
            ->leftJoin('sales as ref_sales', fn ($join) => $join
                ->on('ref_sales.id', '=', 'stock_movements.reference_id')
                ->where('stock_movements.reference_type', '=', (new Sale)->getMorphClass()))
            ->leftJoin('sale_returns as ref_returns', fn ($join) => $join
                ->on('ref_returns.id', '=', 'stock_movements.reference_id')
                ->where('stock_movements.reference_type', '=', (new SaleReturn)->getMorphClass()))
            ->leftJoin('sales as return_sales', 'return_sales.id', '=', 'ref_returns.sale_id')
            ->orderByDesc('stock_movements.occurred_at')
            ->orderByDesc('stock_movements.id')
            ->select($columns);
    }

    /**
     * Every movement of stockMovementsQuery(), newest first, a thousand at a
     * time by keyset (the last row's time and id) on stock_movements alone,
     * then joined to their documents (movementDetails()), so exporting a
     * year of movements reads each row once instead of paging by offset.
     *
     * @param  array{types?: list<string>, product?: string|null, user_id?: int|null, location_id?: int|null}  $filters
     * @return \Generator<int, \stdClass>
     */
    public function stockMovementRows(ReportPeriod $period, bool $withCost, array $filters = []): \Generator
    {
        $last = null;

        do {
            $keys = $this->movements($period, $filters)
                ->when($last !== null, fn (Builder $query) => $query->where(fn (Builder $older) => $older
                    ->where('stock_movements.occurred_at', '<', $last->occurred_at)
                    ->orWhere(fn (Builder $same) => $same->where('stock_movements.occurred_at', $last->occurred_at)->where('stock_movements.id', '<', $last->id))))
                ->orderByDesc('stock_movements.occurred_at')
                ->orderByDesc('stock_movements.id')
                ->limit(1000)
                ->get(['stock_movements.id', 'stock_movements.occurred_at']);

            yield from $this->movementDetails($period, $withCost, array_values(array_map('intval', $keys->pluck('id')->all())));

            $last = $keys->last();
        } while ($keys->count() === 1000);
    }

    /**
     * Net quantity change of the filtered movements, and its value at the
     * recorded unit costs (only when $withCost).
     *
     * @param  array{types?: list<string>, product?: string|null, user_id?: int|null, location_id?: int|null}  $filters
     * @return array{quantity: float, value: int|null, count: int}
     */
    public function stockMovementTotals(ReportPeriod $period, bool $withCost, array $filters = []): array
    {
        $row = $this->movements($period, $filters)
            ->selectRaw('COUNT(*) as movements, COALESCE(SUM(stock_movements.quantity), 0) as quantity, COALESCE(SUM(stock_movements.quantity * COALESCE(stock_movements.unit_cost, 0)), 0) as value')
            ->first();

        return [
            'quantity' => round((float) ($row->quantity ?? 0), 3),
            'value' => $withCost ? (int) round((float) ($row->value ?? 0)) : null,
            'count' => (int) ($row->movements ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    public function presentMovement(\stdClass $row, ReportPeriod $period): array
    {
        $type = StockMovementType::tryFrom((string) $row->type);
        $quantity = round((float) $row->quantity, 3);
        $reason = array_filter([$type?->reasonLabel($row->reason_code), $row->note], fn ($part): bool => filled($part));

        $values = [
            'id' => (int) $row->id,
            'when' => $period->local($row->occurred_at),
            'sku' => $row->sku,
            'product' => trim($row->product.' '.($row->variant ?? '')),
            'type' => $type?->label() ?? (string) $row->type,
            'quantity' => $quantity,
            'balance' => round((float) $row->balance_after, 3),
            'location' => $row->location,
            'reference' => $row->sale_invoice ?? $row->return_no ?? $row->transfer_number ?? $row->grn_number ?? $row->supplier_return_number
                ?? $row->write_off_number ?? $row->count_number ?? ($row->reference_type === null ? 'Manual' : '-'),
            // The sale page shows the sale and its returns.
            'reference_sale' => $row->sale_uuid ?? $row->return_sale_uuid,
            'reason' => $reason === [] ? null : implode(' — ', $reason),
            'by_user' => $row->by_user,
        ];

        // Batch-tracked items: the batch moved ("B1234 · exp 03/2027").
        if (($row->batch_no ?? null) !== null) {
            $values['batch'] = (bool) $row->batch_unallocated
                ? 'Unallocated'
                : $row->batch_no.($row->batch_expiry === null ? '' : ' · exp '.date('m/Y', (int) strtotime((string) $row->batch_expiry)));
        }

        if (property_exists($row, 'unit_cost')) {
            $values['unit_cost'] = $row->unit_cost === null ? null : (int) $row->unit_cost;
            $values['value'] = $row->unit_cost === null ? null : (int) round($quantity * (int) $row->unit_cost);
        }

        return $values;
    }

    /**
     * @param  array{types?: list<string>, product?: string|null, user_id?: int|null, location_id?: int|null}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function stockMovements(ReportPeriod $period, bool $withCost, int $page, int $perPage, array $filters = []): LengthAwarePaginator
    {
        $total = $this->movements($period, $filters)->count();
        $ids = $this->movements($period, $filters)
            ->orderByDesc('stock_movements.occurred_at')
            ->orderByDesc('stock_movements.id')
            ->forPage($page, $perPage)
            ->pluck('stock_movements.id')
            ->all();
        $ids = array_values(array_map('intval', $ids));

        return new LengthAwarePaginator(
            array_map(fn (\stdClass $row): array => $this->presentMovement($row, $period), $this->movementDetails($period, $withCost, $ids)),
            $total,
            $perPage,
            $page,
        );
    }

    /**
     * stockMovementsQuery()'s rows for the movements $ids, in that order. The
     * page (or chunk) is chosen on stock_movements alone, by its index on
     * (shop_id, occurred_at); only its rows are then joined to their
     * documents, so a page of a year of movements reads a page, not the year.
     *
     * @param  list<int>  $ids
     * @return list<\stdClass>
     */
    private function movementDetails(ReportPeriod $period, bool $withCost, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->stockMovementsQuery($period, $withCost)->whereIn('stock_movements.id', $ids)->get()->keyBy('id');

        return array_values(array_filter(array_map(fn (int $id): ?\stdClass => $rows->get($id), $ids)));
    }

    /**
     * Stock adjustments in a period, for the reports branch (docs/PHARMACY.md
     * §9, branch 11: write-offs, count variances): every adjustment_in and
     * adjustment_out by reason and by where it came from (a write-off note,
     * a stock count, or by hand on Stock levels / Batches), with the
     * quantities in and out (base units) and the net value at the ledger's
     * cost per base unit (a write-off's is the batch cost, anything else the
     * location's average). Optionally one location. CALLERS MUST CHECK
     * cost.view before showing the value.
     *
     * @return list<array{reason: string, label: string, source: string, movements: int, quantity_in: float, quantity_out: float, value: int}>
     */
    public function stockAdjustments(ReportPeriod $period, ?int $locationId = null): array
    {
        $writeOff = (new StockWriteOff)->getMorphClass();
        $count = (new StockCount)->getMorphClass();

        return array_values($this->movements($period, [
            'types' => [StockMovementType::AdjustmentIn->value, StockMovementType::AdjustmentOut->value],
            'location_id' => $locationId,
        ])
            ->selectRaw("COALESCE(stock_movements.reason_code, '') as reason")
            ->selectRaw('CASE WHEN stock_movements.reference_type = ? THEN ? WHEN stock_movements.reference_type = ? THEN ? ELSE ? END as source', [$writeOff, 'write_off', $count, 'count', 'manual'])
            ->selectRaw('COUNT(*) as movements')
            ->selectRaw('SUM(CASE WHEN stock_movements.quantity > 0 THEN stock_movements.quantity ELSE 0 END) as quantity_in')
            ->selectRaw('SUM(CASE WHEN stock_movements.quantity < 0 THEN -stock_movements.quantity ELSE 0 END) as quantity_out')
            ->selectRaw('SUM(stock_movements.quantity * COALESCE(stock_movements.unit_cost, 0)) as value')
            ->groupBy('reason', 'source')
            ->orderBy('source')
            ->orderBy('reason')
            ->get()
            ->map(fn (\stdClass $row): array => [
                'reason' => (string) $row->reason,
                'label' => AdjustmentReason::tryFrom((string) $row->reason)?->label() ?? 'No reason',
                'source' => (string) $row->source,
                'movements' => (int) $row->movements,
                'quantity_in' => round((float) $row->quantity_in, 3),
                'quantity_out' => round((float) $row->quantity_out, 3),
                'value' => (int) round((float) $row->value),
            ])
            ->all());
    }

    /**
     * Revenue (before tax), cost and gross profit per product: profit()'s
     * goods by product, then each service (the consultation) with its
     * revenue and no cost. With a location, the lines sold from there.
     * CALLERS MUST CHECK reports.profit.
     *
     * @return list<array{product: string, quantity: float, revenue: int, cost: int, profit: int, margin: float|null}>
     */
    public function profitByProduct(ReportPeriod $period, ?int $locationId = null): array
    {
        $profit = $this->profit($period, 'product', $locationId);

        return array_values(collect($profit['rows'])
            ->concat($profit['services'])
            ->map(fn (array $row): array => [
                'product' => $row['name'],
                'quantity' => $row['quantity'],
                'revenue' => $row['revenue'],
                'cost' => $row['cost'],
                'profit' => $row['profit'],
                'margin' => $row['margin'],
            ])
            ->sortByDesc('profit')
            ->values()
            ->all());
    }

    /** Gross profit for the whole period, services included. CALLERS MUST CHECK reports.profit. */
    public function grossProfit(ReportPeriod $period, ?int $locationId = null): int
    {
        return $this->profit($period, 'product', $locationId)['total']['profit'];
    }

    /**
     * Best sellers by quantity, net of returns given in the period; with a
     * location, from the lines sold there.
     *
     * @return list<array{sku: string, product: string, variant: string|null, quantity: float, net: int}>
     */
    public function bestSellers(ReportPeriod $period, int $limit = 10, ?int $locationId = null): array
    {
        $sold = $this->lineFacts($period);
        $returned = $this->returnFacts($period);
        $onlyHere = fn (Collection $rows): Collection => $locationId === null ? $rows : $rows->where('line_location', $locationId);
        $groups = $this->groupFacts($onlyHere($sold), $onlyHere($returned), fn (SalesFact $row): int => $row->variant_id, fn (SalesFact $row): string => '');
        $variants = ProductVariant::withTrashed()->toBase()
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereIn('product_variants.id', array_map('intval', array_keys($groups)))
            ->get(['product_variants.id', 'product_variants.sku', 'products.name as product', 'product_variants.name as variant'])
            ->keyBy('id');

        return array_values(collect($groups)
            ->map(function (array $group) use ($variants): array {
                $variant = $variants->get((int) $group['key']);

                return [
                    'sku' => (string) ($variant->sku ?? ''),
                    'product' => (string) ($variant->product ?? ''),
                    'variant' => ($variant->variant ?? null) === null ? null : (string) $variant->variant,
                    'quantity' => round($group['base_quantity'], 3),
                    'net' => $group['gross'] - $group['refunds'],
                ];
            })
            ->filter(fn (array $row): bool => $row['quantity'] > 0)
            ->sortBy([['quantity', 'desc'], ['net', 'desc']])
            ->take($limit)
            ->values()
            ->all());
    }

    /**
     * The items (goods: medicines in a pharmacy, never the consultation)
     * with the highest net sales, net of returns given in the period, as
     * salesByItem() adds them up; with a location, the lines sold there.
     *
     * @return list<array{sku: string, item: string, quantity: float, net: int}>
     */
    public function topItemsByRevenue(ReportPeriod $period, int $limit = 10, ?int $locationId = null): array
    {
        $sold = $this->lineFacts($period);
        $returned = $this->returnFacts($period);
        $items = $this->itemsOf($sold, $returned);
        $goodsHere = fn (Collection $rows): Collection => $rows
            ->filter(fn (SalesFact $row): bool => ($items[$row->variant_id]['goods'] ?? true) && ($locationId === null || $row->line_location === $locationId));
        $groups = $this->groupFacts($goodsHere($sold), $goodsHere($returned), fn (SalesFact $row): int => $row->variant_id, fn (SalesFact $row): string => $items[$row->variant_id]['name'] ?? '');

        return array_values(collect($groups)
            ->map(fn (array $group): array => [
                'sku' => (string) ($items[(int) $group['key']]['sku'] ?? ''),
                'item' => $group['name'],
                'quantity' => round($group['base_quantity'], 3),
                'net' => $group['gross'] - $group['refunds'],
            ])
            ->filter(fn (array $row): bool => $row['net'] > 0)
            ->sortBy([['net', 'desc'], ['sku', 'asc']])
            ->take($limit)
            ->values()
            ->all());
    }

    /**
     * The end-of-day figures per counter (docs/PHARMACY.md §9, branch 11):
     * for each selling location (the counter, where bills are rung up:
     * sales.location_id), the bills, sales, refunds given, voids and net
     * sales, and how the sales split into consultation fees (services:
     * products that don't track stock) and medicines (goods), each net of
     * refunds (and before them: *_gross, *_refunds); with a location, only
     * that counter. Refunds count against
     * the counter of the bill they refund, voids on the day they were
     * voided (they are not in the sales: shown for control).
     * Net = sales - refunds, and consultation + medicines = net.
     *
     * @return list<array{location_id: int, location: string, bills: int, gross: int, refunds: int, net: int, voids: int, voids_total: int, services: int, goods: int, services_gross: int, services_refunds: int, goods_gross: int, goods_refunds: int, opd_bills: int, consultations: float}>
     */
    public function counters(ReportPeriod $period, ?int $locationId = null): array
    {
        $bills = $this->sales($period, $locationId)
            ->selectRaw('sales.location_id as location_id, COUNT(*) as bills, SUM(sales.grand_total) as gross')
            ->selectRaw('SUM(CASE WHEN sales.bill_type = ? THEN 1 ELSE 0 END) as opd_bills', [BillType::Opd->value])
            ->groupBy('sales.location_id')
            ->get()
            ->keyBy('location_id');

        $refunds = $this->returns($period, $locationId)
            ->join('sales', 'sales.id', '=', 'sale_returns.sale_id')
            ->selectRaw('sales.location_id as location_id, SUM(sale_returns.refund_total) as refunds')
            ->groupBy('sales.location_id')
            ->pluck('refunds', 'location_id');

        $voids = $this->voids($period, $locationId)
            ->selectRaw('sales.location_id as location_id, COUNT(*) as voids, SUM(sales.grand_total) as voids_total')
            ->groupBy('sales.location_id')
            ->get()
            ->keyBy('location_id');

        $sold = $this->lineFacts($period);
        $returned = $this->returnFacts($period);
        $items = $this->itemsOf($sold, $returned);

        $locations = $this->counterLocations($locationId, [...$bills->keys()->all(), ...$refunds->keys()->all(), ...$voids->keys()->all()]);
        $rows = [];

        foreach ($locations as $id => $name) {
            $income = ['services' => 0, 'goods' => 0, 'services_refunds' => 0, 'goods_refunds' => 0, 'consultations' => 0.0];

            foreach ($sold->where('bill_location', $id) as $row) {
                $goods = $items[$row->variant_id]['goods'] ?? true;
                $income[$goods ? 'goods' : 'services'] += $row->gross;
                $income['consultations'] += $goods ? 0 : $row->quantity;
            }

            foreach ($returned->where('bill_location', $id) as $row) {
                $goods = $items[$row->variant_id]['goods'] ?? true;
                $income[$goods ? 'goods_refunds' : 'services_refunds'] += $row->gross;
                $income['consultations'] -= $goods ? 0 : $row->quantity;
            }

            $gross = (int) ($bills->get($id)->gross ?? 0);
            $refund = (int) ($refunds[$id] ?? 0);

            $rows[] = [
                'location_id' => (int) $id,
                'location' => $name,
                'bills' => (int) ($bills->get($id)->bills ?? 0),
                'gross' => $gross,
                'refunds' => $refund,
                'net' => $gross - $refund,
                'voids' => (int) ($voids->get($id)->voids ?? 0),
                'voids_total' => (int) ($voids->get($id)->voids_total ?? 0),
                'services' => $income['services'] - $income['services_refunds'],
                'goods' => $income['goods'] - $income['goods_refunds'],
                'services_gross' => $income['services'],
                'services_refunds' => $income['services_refunds'],
                'goods_gross' => $income['goods'],
                'goods_refunds' => $income['goods_refunds'],
                'opd_bills' => (int) ($bills->get($id)->opd_bills ?? 0),
                'consultations' => round($income['consultations'], 3),
            ];
        }

        return $rows;
    }

    /**
     * The counters a report lists, in the shop's location order: the one
     * asked for, or every active location that sells plus any other that
     * had sales in the period.
     *
     * @param  list<int|string>  $withActivity
     * @return array<int, string> location id => name
     */
    private function counterLocations(?int $locationId, array $withActivity): array
    {
        return Location::query()
            ->when(
                $locationId !== null,
                fn ($query) => $query->whereKey($locationId),
                fn ($query) => $query->where(fn ($where) => $where
                    ->where(fn ($selling) => $selling->where('is_active', true)->where('is_sellable', true))
                    ->orWhereIn('id', array_map('intval', $withActivity))),
            )
            ->ordered()
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * The shifts of the period (opened in it, or still open from before),
     * each with what its drawer should hold and what was counted: closed
     * shifts as recorded at closing, open ones as they stand now
     * (ShiftService::summary()). With a location, the shifts on its tills.
     *
     * @return list<array{till: string, location: string, cashier: string, opened_at: string|null, closed_at: string, opening_cash: int, cash_sales: int, cash_refunds: int, expected_cash: int, counted_cash: int|null, difference: int|null}>
     */
    public function shiftCash(ReportPeriod $period, ?int $locationId = null): array
    {
        return array_values(Shift::query()
            ->with(['device.location', 'user'])
            ->where('opened_at', '<', $period->endUtc())
            ->where(fn ($query) => $query->whereNull('closed_at')->orWhere('closed_at', '>=', $period->startUtc()))
            ->where(fn ($query) => $query->where('opened_at', '>=', $period->startUtc())->orWhereNull('closed_at'))
            ->when($locationId !== null, fn ($query) => $query->whereHas('device', fn ($device) => $device->where('location_id', $locationId)))
            ->orderBy('opened_at')
            ->get()
            ->map(function (Shift $shift) use ($period): array {
                $summary = $this->shifts->summary($shift);

                return [
                    'till' => (string) $shift->device?->code,
                    'location' => (string) $shift->device?->location?->name,
                    'cashier' => (string) $shift->user?->name,
                    'opened_at' => $period->local($shift->opened_at->utc()->format('Y-m-d H:i:s')),
                    'closed_at' => $shift->closed_at === null ? 'Open' : (string) $period->local($shift->closed_at->utc()->format('Y-m-d H:i:s')),
                    'opening_cash' => $summary['cash']['opening'],
                    'cash_sales' => $summary['cash']['sales'],
                    'cash_refunds' => $summary['cash']['refunds'],
                    'expected_cash' => $summary['cash']['expected'],
                    'counted_cash' => $summary['cash']['counted'],
                    'difference' => $summary['cash']['difference'],
                ];
            })
            ->all());
    }

    /**
     * OPD consultations (docs/PHARMACY.md §9, branch 11): the OPD bills
     * rung up, the consultations charged (the quantity of services sold,
     * one a visit) and the consultation income, net of refunds, with the
     * Medicines amounts of the OPD bills beside it. With a location, the
     * bills rung up there.
     *
     * @return array{opd_bills: int, consultations: float, income: int, refunds: int, net: int, opd_medicines: int}
     */
    public function consultations(ReportPeriod $period, ?int $locationId = null): array
    {
        $sold = $this->lineFacts($period);
        $returned = $this->returnFacts($period);

        return $this->consultationsFrom($period, $sold, $returned, $this->itemsOf($sold, $returned), $locationId);
    }

    /**
     * @param  Collection<int, SalesFact>  $sold
     * @param  Collection<int, SalesFact>  $returned
     * @param  array<int, array<string, mixed>>  $items
     * @return array{opd_bills: int, consultations: float, income: int, refunds: int, net: int, opd_medicines: int}
     */
    private function consultationsFrom(ReportPeriod $period, Collection $sold, Collection $returned, array $items, ?int $locationId): array
    {
        $atCounter = fn (Collection $rows): Collection => $locationId === null ? $rows : $rows->where('bill_location', $locationId);
        $services = fn (Collection $rows): Collection => $atCounter($rows)->filter(fn (SalesFact $row): bool => ! ($items[$row->variant_id]['goods'] ?? true));
        $income = (int) $services($sold)->sum('gross');
        $refunds = (int) $services($returned)->sum('gross');

        return [
            'opd_bills' => $this->sales($period, $locationId)->where('sales.bill_type', BillType::Opd->value)->count(),
            'consultations' => round((float) $services($sold)->sum('quantity') - (float) $services($returned)->sum('quantity'), 3),
            'income' => $income,
            'refunds' => $refunds,
            'net' => $income - $refunds,
            'opd_medicines' => (int) $atCounter($sold)->filter(fn (SalesFact $row): bool => $row->bill_type === BillType::Opd->value && ($items[$row->variant_id]['goods'] ?? false))->sum('gross'),
        ];
    }

    /**
     * Sales per item (docs/PHARMACY.md §9, branch 11): the quantity sold in
     * base units net of returns, the same in the item's largest active pack
     * ("2 × Box of 30 + 15 Tablet"), and sales, refunds and net; with a
     * location, the lines sold from there. Highest net first.
     *
     * @return list<array{sku: string, item: string, schedule: string|null, quantity: float, packs: string|null, gross: int, refunds: int, net: int}>
     */
    public function salesByItem(ReportPeriod $period, ?int $locationId = null): array
    {
        $sold = $this->lineFacts($period);
        $returned = $this->returnFacts($period);

        /** @var list<array{sku: string, item: string, schedule: string|null, quantity: float, packs: string|null, gross: int, refunds: int, net: int}> */
        return $this->salesRows('item', $sold, $returned, $this->itemsOf($sold, $returned), $locationId);
    }

    /**
     * "2 × Box of 30 + 15 Tablet": a quantity in base units in a pack and
     * what is left over; null without a pack (or for less than one).
     *
     * @param  array{name: string, factor: float}|null  $pack
     */
    public static function inPacks(float $baseQuantity, ?array $pack, string $baseUnit): ?string
    {
        if ($pack === null || $pack['factor'] <= 1 || abs($baseQuantity) < $pack['factor']) {
            return null;
        }

        $sign = $baseQuantity < 0 ? '-' : '';
        $packs = (int) floor(abs($baseQuantity) / $pack['factor']);
        $left = round(abs($baseQuantity) - $packs * $pack['factor'], 3);
        $name = VariantUnit::describe($pack['name'], $pack['factor']);

        return $sign.$packs.' × '.$name.($left > 0 ? ' + '.Quantity::format($left).' '.$baseUnit : '');
    }

    /**
     * Each item's largest active pack (a factor above 1).
     *
     * @param  list<int>  $variantIds
     * @return array<int, array{name: string, factor: float}>
     */
    private function largestPacks(array $variantIds): array
    {
        $packs = [];

        foreach (array_chunk($variantIds, 1000) as $chunk) {
            VariantUnit::query()->toBase()
                ->whereIn('product_variant_id', $chunk)
                ->where('is_active', true)
                ->where('is_base', false)
                ->where('factor', '>', 1)
                ->orderBy('factor')
                ->get(['product_variant_id', 'name', 'factor'])
                ->each(function ($unit) use (&$packs): void {
                    $packs[(int) $unit->product_variant_id] = ['name' => (string) $unit->name, 'factor' => (float) $unit->factor];
                });
        }

        return $packs;
    }

    /**
     * Posted goods received notes dated (received_on) in the period, as
     * the purchases reports read them: reversals are negative and count in
     * the period they were made. Optionally one location and supplier.
     */
    private function receipts(ReportPeriod $period, ?int $locationId = null, ?int $supplierId = null): Builder
    {
        // Dates compared as from-inclusive, to-exclusive, as SQLite keeps a time on them.
        return GoodsReceipt::query()->toBase()
            ->where('goods_receipts.status', GoodsReceiptStatus::Posted->value)
            ->where('goods_receipts.received_on', '>=', $period->fromDate())
            ->where('goods_receipts.received_on', '<', $period->end->format('Y-m-d'))
            ->when($locationId !== null, fn (Builder $query) => $query->where('goods_receipts.location_id', $locationId))
            ->when($supplierId !== null, fn (Builder $query) => $query->where('goods_receipts.supplier_id', $supplierId));
    }

    /**
     * Purchases (docs/PHARMACY.md §9, branch 11) by supplier or by month:
     * goods received notes (reversals apart), their value before discounts,
     * the discounts (line and invoice), the tax (VAT, line and invoice,
     * part of the cost), what they total (owed to the supplier), and the
     * value of the free goods (free quantity at the invoice's unit cost),
     * reversing GRNs taking theirs back. Supplier returns are their own
     * report (supplierReturns()).
     *
     * @param  'supplier'|'month'  $by
     * @return list<array{name: string, grns: int, reversals: int, subtotal: int, discounts: int, tax: int, total: int, free_goods: int}>
     */
    public function purchases(ReportPeriod $period, string $by = 'supplier', ?int $locationId = null, ?int $supplierId = null): array
    {
        $group = fn (Builder $query): Builder => $by === 'month'
            ? $query->selectRaw('SUBSTR(goods_receipts.received_on, 1, 7) as group_id, SUBSTR(goods_receipts.received_on, 1, 7) as group_name')->groupBy('group_id', 'group_name')
            : $query->join('suppliers', 'suppliers.id', '=', 'goods_receipts.supplier_id')
                ->selectRaw('suppliers.id as group_id, suppliers.name as group_name')
                ->groupBy('suppliers.id', 'suppliers.name');

        $totals = $group($this->receipts($period, $locationId, $supplierId))
            ->selectRaw('SUM(CASE WHEN goods_receipts.reversal_of_id IS NULL THEN 1 ELSE 0 END) as grns, SUM(CASE WHEN goods_receipts.reversal_of_id IS NULL THEN 0 ELSE 1 END) as reversals')
            ->selectRaw('SUM(goods_receipts.subtotal) as subtotal, SUM(goods_receipts.line_discount_total + goods_receipts.invoice_discount) as discounts')
            ->selectRaw('SUM(goods_receipts.line_tax_total + goods_receipts.invoice_tax) as tax, SUM(goods_receipts.total) as total')
            ->get();

        $free = $group($this->receipts($period, $locationId, $supplierId)
            ->join('goods_receipt_items', 'goods_receipt_items.goods_receipt_id', '=', 'goods_receipts.id'))
            ->selectRaw('SUM(goods_receipt_items.free_quantity * goods_receipt_items.unit_cost) as free_goods')
            ->get()
            ->keyBy('group_id');

        return array_values($totals
            ->map(fn ($row): array => [
                'name' => (string) $row->group_name,
                'grns' => (int) $row->grns,
                'reversals' => (int) $row->reversals,
                'subtotal' => (int) $row->subtotal,
                'discounts' => (int) $row->discounts,
                'tax' => (int) $row->tax,
                'total' => (int) $row->total,
                'free_goods' => (int) round((float) ($free->get($row->group_id)->free_goods ?? 0)),
            ])
            ->sortBy($by === 'month' ? 'name' : [['total', 'desc'], ['name', 'asc']])
            ->values()
            ->all());
    }

    /**
     * The goods received notes behind purchases(), in date order.
     *
     * @return list<array{grn: string, received_on: string, supplier: string, invoice: string|null, location: string, subtotal: int, discounts: int, tax: int, total: int, free_goods: int, note: string|null}>
     */
    public function goodsReceived(ReportPeriod $period, ?int $locationId = null, ?int $supplierId = null): array
    {
        return array_values($this->receipts($period, $locationId, $supplierId)
            ->join('suppliers', 'suppliers.id', '=', 'goods_receipts.supplier_id')
            ->join('locations', 'locations.id', '=', 'goods_receipts.location_id')
            ->leftJoin('goods_receipts as reversed', 'reversed.id', '=', 'goods_receipts.reversal_of_id')
            ->orderBy('goods_receipts.received_on')
            ->orderBy('goods_receipts.id')
            ->select([
                'goods_receipts.grn_number', 'goods_receipts.received_on', 'suppliers.name as supplier', 'goods_receipts.supplier_invoice_no',
                'locations.name as location', 'goods_receipts.subtotal', 'goods_receipts.line_discount_total', 'goods_receipts.invoice_discount',
                'goods_receipts.line_tax_total', 'goods_receipts.invoice_tax', 'goods_receipts.total', 'reversed.grn_number as reverses',
            ])
            ->selectSub(fn (Builder $query) => $query->from('goods_receipt_items')
                ->whereColumn('goods_receipt_items.goods_receipt_id', 'goods_receipts.id')
                ->selectRaw('COALESCE(SUM(goods_receipt_items.free_quantity * goods_receipt_items.unit_cost), 0)'), 'free_goods')
            ->get()
            ->map(fn ($row): array => [
                'grn' => (string) $row->grn_number,
                'received_on' => substr((string) $row->received_on, 0, 10),
                'supplier' => (string) $row->supplier,
                'invoice' => $row->supplier_invoice_no === null ? null : (string) $row->supplier_invoice_no,
                'location' => (string) $row->location,
                'subtotal' => (int) $row->subtotal,
                'discounts' => (int) $row->line_discount_total + (int) $row->invoice_discount,
                'tax' => (int) $row->line_tax_total + (int) $row->invoice_tax,
                'total' => (int) $row->total,
                'free_goods' => (int) round((float) $row->free_goods),
                'note' => $row->reverses === null ? null : 'Reverses '.$row->reverses,
            ])
            ->all());
    }

    /**
     * Supplier returns dated (returned_on) in the period, with the credit
     * expected at batch cost.
     *
     * @return list<array{return_no: string, returned_on: string, supplier: string, location: string, reason: string, credit_note: string|null, total: int}>
     */
    public function supplierReturns(ReportPeriod $period, ?int $locationId = null, ?int $supplierId = null): array
    {
        return array_values(SupplierReturn::query()->toBase()
            ->join('suppliers', 'suppliers.id', '=', 'supplier_returns.supplier_id')
            ->join('locations', 'locations.id', '=', 'supplier_returns.location_id')
            ->where('supplier_returns.returned_on', '>=', $period->fromDate())
            ->where('supplier_returns.returned_on', '<', $period->end->format('Y-m-d'))
            ->when($locationId !== null, fn (Builder $query) => $query->where('supplier_returns.location_id', $locationId))
            ->when($supplierId !== null, fn (Builder $query) => $query->where('supplier_returns.supplier_id', $supplierId))
            ->orderBy('supplier_returns.returned_on')
            ->orderBy('supplier_returns.id')
            ->get(['supplier_returns.return_number', 'supplier_returns.returned_on', 'suppliers.name as supplier', 'locations.name as location', 'supplier_returns.reason', 'supplier_returns.credit_note_no', 'supplier_returns.total'])
            ->map(fn ($row): array => [
                'return_no' => (string) $row->return_number,
                'returned_on' => substr((string) $row->returned_on, 0, 10),
                'supplier' => (string) $row->supplier,
                'location' => (string) $row->location,
                'reason' => SupplierReturnReason::tryFrom((string) $row->reason)?->label() ?? (string) $row->reason,
                'credit_note' => $row->credit_note_no === null ? null : (string) $row->credit_note_no,
                'total' => (int) $row->total,
            ])
            ->all());
    }

    /**
     * The owner's dashboard comparison: net sales this month so far, the
     * same days of last month, and all of last month.
     *
     * @return array{this_month: int, last_month_to_date: int, last_month: int, change: float|null}
     */
    public function monthComparison(): array
    {
        $shop = $this->currentShop->getOrFail();
        $today = CarbonImmutable::now((string) ($shop->timezone ?: config('app.timezone')));
        $lastMonth = ReportPeriod::lastMonth($shop);
        // The same days of last month: up to today's date, or its last day in a shorter month.
        $sameDay = $today->startOfMonth()->subMonth()->addDays(min($today->day, $lastMonth->start->daysInMonth) - 1);

        $thisMonth = $this->summary(ReportPeriod::thisMonth($shop))['net'];
        $toDate = $this->summary(ReportPeriod::between($shop, $lastMonth->fromDate(), $sameDay->format('Y-m-d')))['net'];

        return [
            'this_month' => $thisMonth,
            'last_month_to_date' => $toDate,
            'last_month' => $this->summary($lastMonth)['net'],
            'change' => $toDate === 0 ? null : round(($thisMonth - $toDate) / abs($toDate) * 100, 1),
        ];
    }

    /**
     * Gross profit (docs/PHARMACY.md §9, branch 11), CALLERS MUST CHECK reports.profit.
     *
     * Goods (products that track stock), grouped by product, category or
     * location (the stock pool each line was sold from):
     *   - revenue is each line's line_total less its tax: what the line was
     *     charged after discounts, an OPD medicine line its stored share of
     *     the Medicines amount; returns given in the period take back their
     *     refund's share of it;
     *   - cost is each line's own, stored when it was sold (sale_items
     *     .unit_cost x quantity: the location's average then, per unit
     *     sold), so it never moves with later averages and has no rounding
     *     per tablet; returns put back on the shelf take back the original
     *     line's cost for what came back, goods not put back stay a cost;
     *   - a sale counts on its reported day (reported_at), a return on the
     *     day it was given; voided sales are left out.
     * Services (the consultation fee) are on their own (`services`, per
     * product), with their own line cost (none for the consultation).
     * The medicines of OPD bills are also added up on their own
     * (`opd_medicines`, part of the goods): their Medicines amounts are typed
     * by the doctor and may be below cost.
     * Exactly what Profit by product gave before this branch, per location.
     *
     * @param  'product'|'category'|'location'  $by
     * @return array{rows: list<array{key: string, name: string, quantity: float, revenue: int, cost: int, profit: int, margin: float|null}>, services: list<array{key: string, name: string, quantity: float, revenue: int, cost: int, profit: int, margin: float|null}>, goods: array{revenue: int, cost: int, profit: int, margin: float|null}, services_total: array{quantity: float, revenue: int, cost: int, profit: int}, total: array{revenue: int, cost: int, profit: int, margin: float|null}, opd_medicines: array{revenue: int, cost: int, profit: int}}
     */
    public function profit(ReportPeriod $period, string $by = 'product', ?int $locationId = null): array
    {
        $profit = $this->profitBy($period, [$by], $locationId);

        return [
            'rows' => $profit['groups'][$by] ?? [],
            'services' => $profit['services'],
            'goods' => $profit['goods'],
            'services_total' => $profit['services_total'],
            'total' => $profit['total'],
            'opd_medicines' => $profit['opd_medicines'],
        ];
    }

    /**
     * profit() for several groupings at once, reading the lines once
     * (the Profit report's tables).
     *
     * @param  list<'product'|'category'|'location'>  $groupings
     * @return array{groups: array<string, list<array{key: string, name: string, quantity: float, revenue: int, cost: int, profit: int, margin: float|null}>>, services: list<array{key: string, name: string, quantity: float, revenue: int, cost: int, profit: int, margin: float|null}>, goods: array{revenue: int, cost: int, profit: int, margin: float|null}, services_total: array{quantity: float, revenue: int, cost: int, profit: int}, total: array{revenue: int, cost: int, profit: int, margin: float|null}, opd_medicines: array{revenue: int, cost: int, profit: int}}
     */
    public function profitBy(ReportPeriod $period, array $groupings, ?int $locationId = null): array
    {
        $onlyHere = fn (Collection $rows): Collection => $locationId === null ? $rows : $rows->where('line_location', $locationId);
        $sold = $onlyHere($this->lineFacts($period));
        $returned = $onlyHere($this->returnFacts($period));
        $items = $this->itemsOf($sold, $returned);
        $isGoods = fn (SalesFact $row): bool => $items[$row->variant_id]['goods'] ?? true;
        $locations = $this->locationNames();

        $groups = [];

        foreach ($groupings as $by) {
            [$key, $name] = match ($by) {
                'location' => [fn (SalesFact $row): int => $row->line_location, fn (SalesFact $row): string => (string) ($locations[$row->line_location] ?? '')],
                'category' => [fn (SalesFact $row): int => $items[$row->variant_id]['category_id'] ?? 0, fn (SalesFact $row): string => $items[$row->variant_id]['category'] ?? 'No category'],
                default => [fn (SalesFact $row): int => $items[$row->variant_id]['product_id'] ?? 0, fn (SalesFact $row): string => $items[$row->variant_id]['product'] ?? ''],
            };

            $groups[$by] = $this->profitRows($sold->filter($isGoods), $returned->filter($isGoods), $key, $name);
        }

        $productKey = fn (SalesFact $row): int => $items[$row->variant_id]['product_id'] ?? 0;
        $productName = fn (SalesFact $row): string => $items[$row->variant_id]['product'] ?? '';
        $services = $this->profitRows($sold->reject($isGoods), $returned->reject($isGoods), $productKey, $productName);

        // Totals are worked out over all their lines and rounded once (as the dashboard's
        // gross profit always was), not added up from rows rounded one by one.
        $all = fn (SalesFact $row): int => 0;
        $label = fn (SalesFact $row): string => '';
        $whole = fn (Collection $sold, Collection $returned): array => $this->profitRows($sold, $returned, $all, $label)[0]
            ?? ['revenue' => 0, 'cost' => 0, 'profit' => 0, 'margin' => null];
        $goods = $whole($sold->filter($isGoods), $returned->filter($isGoods));
        $servicesTotal = $whole($sold->reject($isGoods), $returned->reject($isGoods));
        $total = $whole($sold, $returned);
        // The medicines of OPD bills, charged as one Medicines amount the doctor types: what
        // explains a day's profit below cost (the dashboard's gross profit tile).
        $opd = fn (SalesFact $row): bool => $isGoods($row) && $row->bill_type === BillType::Opd->value;
        $opdMedicines = $whole($sold->filter($opd), $returned->filter($opd));

        return [
            'groups' => $groups,
            'services' => array_values(collect($services)->sortByDesc('revenue')->values()->all()),
            'goods' => ['revenue' => $goods['revenue'], 'cost' => $goods['cost'], 'profit' => $goods['profit'], 'margin' => $goods['margin']],
            'services_total' => ['quantity' => round((float) collect($services)->sum('quantity'), 3), 'revenue' => $servicesTotal['revenue'], 'cost' => $servicesTotal['cost'], 'profit' => $servicesTotal['profit']],
            'total' => ['revenue' => $total['revenue'], 'cost' => $total['cost'], 'profit' => $total['profit'], 'margin' => $total['margin']],
            'opd_medicines' => ['revenue' => $opdMedicines['revenue'], 'cost' => $opdMedicines['cost'], 'profit' => $opdMedicines['profit']],
        ];
    }

    /**
     * Revenue and cost (each sold less what returns took back) per group.
     *
     * @param  Collection<int, SalesFact>  $sold
     * @param  Collection<int, SalesFact>  $returned
     * @param  callable(SalesFact): (int|string)  $key
     * @param  callable(SalesFact): string  $name
     * @return list<array{key: string, name: string, quantity: float, revenue: int, cost: int, profit: int, margin: float|null}>
     */
    private function profitRows(Collection $sold, Collection $returned, callable $key, callable $name): array
    {
        return array_values(collect($this->groupFacts($sold, $returned, $key, $name))
            ->map(function (array $group): array {
                $revenue = (int) round($group['revenue'] - $group['returned_revenue']);
                $cost = (int) round($group['cost'] - $group['returned_cost']);

                return [
                    'key' => $group['key'],
                    'name' => $group['name'],
                    'quantity' => round($group['base_quantity'], 3),
                    'revenue' => $revenue,
                    'cost' => $cost,
                    'profit' => $revenue - $cost,
                    'margin' => self::margin($revenue, $revenue - $cost),
                ];
            })
            ->sortByDesc('profit')
            ->values()
            ->all());
    }

    /** Profit as a percentage of revenue, to one place; null without revenue. */
    public static function margin(int $revenue, int $profit): ?float
    {
        return $revenue === 0 ? null : round($profit / $revenue * 100, 1);
    }

    /**
     * Joins two keyed aggregate results (sales and refunds) into one row per key.
     * Each row keeps its key, so the result can be merged again.
     *
     * @param  Collection<int, \stdClass>  $left
     * @param  Collection<int, \stdClass>  $right
     * @param  list<string>  $leftInts
     * @param  list<string>  $rightInts
     * @param  list<string>  $leftFloats
     * @param  list<string>  $rightFloats
     * @return Collection<int, array<string, mixed>>
     */
    private function mergeByKey(Collection $left, Collection $right, string $key, string $label, array $leftInts, array $rightInts, array $leftFloats = [], array $rightFloats = []): Collection
    {
        $rows = [];

        foreach ([[$left, $leftInts, $leftFloats], [$right, $rightInts, $rightFloats]] as [$source, $ints, $floats]) {
            foreach ($source as $item) {
                $id = (string) $item->{$key};
                $rows[$id] ??= [$key => $item->{$key}, $label => $item->{$label}, ...array_fill_keys([...$leftInts, ...$rightInts], 0), ...array_fill_keys([...$leftFloats, ...$rightFloats], 0.0)];

                foreach ($ints as $field) {
                    $rows[$id][$field] += (int) $item->{$field};
                }

                foreach ($floats as $field) {
                    $rows[$id][$field] += (float) $item->{$field};
                }
            }
        }

        /** @var Collection<int, array<string, mixed>> $merged */
        $merged = collect(array_values($rows));

        return $merged;
    }
}
