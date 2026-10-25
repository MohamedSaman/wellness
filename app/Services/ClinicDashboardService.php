<?php

namespace App\Services;

use App\Enums\BillType;
use App\Enums\GoodsReceiptStatus;
use App\Enums\SaleStatus;
use App\Enums\StockCountStatus;
use App\Enums\StockMovementType;
use App\Filament\Support\ShopMoney;
use App\Models\GoodsReceipt;
use App\Models\Location;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\StockBatch;
use App\Models\StockCount;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockWriteOff;
use App\Support\CurrentShop;
use App\Support\Quantity;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * The clinic dashboard's figures (app/Filament/Widgets/Clinic): every one
 * from ReportService or ExpiryService, by their rules (voided sales left
 * out, refunds on the day given, the shop's own days), so the dashboard
 * agrees with the reports. Stock alerts and the location figures cover the
 * selling locations (General Pharmacy and the doctor's), not Quarantine.
 *
 * Each part is kept for a minute per shop and shop day, so polling and
 * several owners don't re-run the aggregates. Cost figures are separate
 * methods: CALLERS MUST CHECK cost.view before calling them.
 */
class ClinicDashboardService
{
    public const CACHE_SECONDS = 60;

    /** The expiry window the dashboard watches, in days (the KPI, insights and alerts). */
    public const EXPIRY_DAYS = 30;

    /** A batch this close to its expiry (or past it) is a high-priority alert. */
    public const HIGH_PRIORITY_DAYS = 7;

    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly ReportService $reports,
        private readonly ExpiryService $expiry,
        private readonly StockRequestService $requests,
    ) {}

    /**
     * Today's headline figures: sales (net of refunds) with yesterday and the
     * same weekday last week, the OPD bills and their consultation fees,
     * stock running low, below zero, expiring or expired, requests waiting.
     *
     * @return array{sales: int, sales_count: int, refunds: int, yesterday: int, last_week: int, last_week_day: string, opd_bills: int, consultation_fees: int, low_stock: int, out_of_stock: int, negative_stock: int, expiring: int, expired: int, requests: int}
     */
    public function today(): array
    {
        return $this->remember('today', function (Shop $shop): array {
            $today = ReportPeriod::today($shop);
            $summary = $this->reports->summary($today);
            $lastWeek = $today->start->subWeek()->format('Y-m-d');
            $consultations = $this->reports->consultations($today);

            return [
                'sales' => $summary['net'],
                'sales_count' => $summary['sales_count'],
                'refunds' => $summary['refunds'],
                'yesterday' => $this->reports->summary(ReportPeriod::yesterday($shop))['net'],
                'last_week' => $this->reports->summary(ReportPeriod::between($shop, $lastWeek, $lastWeek))['net'],
                'last_week_day' => $today->start->format('l'),
                'opd_bills' => $consultations['opd_bills'],
                'consultation_fees' => $consultations['net'],
                'low_stock' => $this->reports->lowStockCount(),
                'out_of_stock' => count(array_filter($this->reports->lowStock(), fn (array $row): bool => $row['on_hand'] <= 0)),
                'negative_stock' => $this->reports->negativeStockCount(),
                'expiring' => $this->batches(ExpiryService::SOON)->count(),
                'expired' => $this->batches(ExpiryService::EXPIRED)->count(),
                'requests' => $this->requests->openCount(),
            ];
        });
    }

    /** What the batches expiring within EXPIRY_DAYS cost. CALLERS MUST CHECK cost.view. */
    public function expiringValue(): int
    {
        return $this->remember('expiring-value', fn (): int => (int) $this->batches(ExpiryService::SOON)
            ->get(['stock_batches.id', 'stock_batches.quantity', 'stock_batches.unit_cost'])
            ->sum(fn (StockBatch $batch): int => $this->expiry->value($batch)));
    }

    /**
     * Change in percent from $before to $now, rounded to one place; null
     * when there is nothing to compare with.
     */
    public static function change(int $now, int $before): ?float
    {
        return $before === 0 ? null : round(($now - $before) / abs($before) * 100, 1);
    }

    /**
     * This month's net sales per selling location (the counter the bill was
     * rung up at, as the daily summary splits them) with each one's share,
     * and the top four categories by net sales with theirs.
     *
     * @return array{locations: list<array{location: string, code: string, net: int, share: float}>, categories: list<array{name: string, net: int, share: float}>, total: int}
     */
    public function locationPerformance(): array
    {
        return $this->remember('location-performance', function (Shop $shop): array {
            $month = ReportPeriod::thisMonth($shop);
            $selling = $this->sellingLocations();
            $counters = array_values(array_filter($this->reports->counters($month), fn (array $row): bool => isset($selling[$row['location_id']])));
            $total = max(0, array_sum(array_column($counters, 'net')));
            $share = fn (int $net, int $of): float => $of > 0 ? round(max(0, $net) / $of * 100, 1) : 0.0;

            $categories = array_values(array_filter($this->reports->salesByGroup($month, 'category'), fn (array $row): bool => $row['net'] > 0));
            usort($categories, fn (array $a, array $b): int => $b['net'] <=> $a['net']);
            $categoryTotal = array_sum(array_column($categories, 'net'));

            return [
                'locations' => array_map(fn (array $row): array => [
                    'location' => $row['location'],
                    'code' => $selling[$row['location_id']],
                    'net' => $row['net'],
                    'share' => $share($row['net'], $total),
                ], $counters),
                'categories' => array_map(fn (array $row): array => [
                    'name' => $row['name'],
                    'net' => $row['net'],
                    'share' => $share($row['net'], $categoryTotal),
                ], array_slice($categories, 0, 4)),
                'total' => $total,
            ];
        });
    }

    /**
     * Net sales on each day of this week, Monday to Sunday; days still to
     * come are null (the chart draws no line there).
     *
     * @return list<array{date: string, label: string, net: int|null}>
     */
    public function weekTrend(): array
    {
        return $this->remember('week-trend', function (Shop $shop): array {
            $today = ReportPeriod::today($shop)->start;
            $monday = $today->startOfWeek(CarbonInterface::MONDAY);
            $week = ReportPeriod::between($shop, $monday->format('Y-m-d'), $monday->addDays(6)->format('Y-m-d'));

            return array_map(fn (array $row): array => [
                'date' => $row['date'],
                'label' => CarbonImmutable::parse($row['date'])->format('D'),
                'net' => $row['date'] > $today->format('Y-m-d') ? null : $row['net'],
            ], $this->reports->salesByDay($week));
        });
    }

    /**
     * Stock on hand at each selling location at weighted-average cost, and
     * how many items are in stock there. CALLERS MUST CHECK cost.view.
     *
     * @return array{locations: list<array{location: string, code: string, value: int, share: float}>, total: int, skus: int}
     */
    public function stockValue(): array
    {
        return $this->remember('stock-value', function (): array {
            $selling = $this->sellingLocations();
            $names = Location::query()->whereIn('id', array_keys($selling))->ordered()->pluck('name', 'id');
            $values = [];

            foreach ($names as $id => $name) {
                $values[] = ['location' => (string) $name, 'code' => $selling[$id], 'value' => $this->reports->stockValueAtCost((int) $id)];
            }

            $total = array_sum(array_column($values, 'value'));

            return [
                'locations' => array_map(fn (array $row): array => [...$row, 'share' => $total > 0 ? round($row['value'] / $total * 100, 1) : 0.0], $values),
                'total' => $total,
                'skus' => StockLevel::query()
                    ->whereIn('location_id', array_keys($selling))
                    ->where('quantity', '>', 0)
                    ->distinct()
                    ->count('product_variant_id'),
            ];
        });
    }

    /**
     * What needs action in stock, most urgent first: batches expired or
     * expiring within EXPIRY_DAYS at a selling location (high within
     * HIGH_PRIORITY_DAYS) and items out of stock (high). At most $limit, with
     * how many there are in all.
     *
     * @return array{alerts: list<array{kind: string, item: string, detail: string, location: string, priority: string}>, total: int}
     */
    public function stockAlerts(int $limit = 6): array
    {
        return $this->remember("stock-alerts-{$limit}", function () use ($limit): array {
            $alerts = [];
            $batches = $this->batches(ExpiryService::EXPIRED)->with(['location', 'variant' => fn ($variant) => $variant->withTrashed()->with('product')])->limit($limit)->get()
                ->concat($this->batches(ExpiryService::SOON)->with(['location', 'variant' => fn ($variant) => $variant->withTrashed()->with('product')])->limit($limit)->get());

            foreach ($batches as $batch) {
                $days = (int) $this->expiry->daysLeft($batch);
                $alerts[] = [
                    'kind' => $days < 0 ? 'expired' : 'expiring',
                    'item' => trim($batch->variant->product?->name.' '.($batch->variant->name ?? '')),
                    'detail' => 'Batch '.$batch->batch_no.' · '.match (true) {
                        $days < 0 => 'expired '.$batch->expiry_date?->format('d/m/Y'),
                        $days === 0 => 'expires today',
                        default => 'expires in '.$days.' '.($days === 1 ? 'day' : 'days'),
                    },
                    'location' => (string) $batch->location?->name,
                    'priority' => $days <= self::HIGH_PRIORITY_DAYS ? 'High' : 'Medium',
                    'order' => $days,
                ];
            }

            $out = array_filter($this->reports->lowStock(), fn (array $row): bool => $row['on_hand'] <= 0);

            foreach (array_slice($out, 0, $limit) as $row) {
                $alerts[] = [
                    'kind' => 'out_of_stock',
                    'item' => trim($row['product'].' '.($row['variant'] ?? '')),
                    'detail' => $row['on_hand'] < 0 ? 'Below zero: '.Quantity::format($row['on_hand']).' on hand' : 'Out of stock',
                    'location' => $row['location'],
                    'priority' => 'High',
                    'order' => 0,
                ];
            }

            // High first; then the soonest expiry; out of stock among the high ones by their order of 0.
            usort($alerts, fn (array $a, array $b): int => [$a['priority'] === 'High' ? 0 : 1, $a['order']] <=> [$b['priority'] === 'High' ? 0 : 1, $b['order']]);

            return [
                'alerts' => array_map(fn (array $alert): array => [
                    'kind' => $alert['kind'],
                    'item' => $alert['item'],
                    'detail' => $alert['detail'],
                    'location' => $alert['location'],
                    'priority' => $alert['priority'],
                ], array_slice($alerts, 0, $limit)),
                'total' => $this->batches(ExpiryService::EXPIRED)->count() + $this->batches(ExpiryService::SOON)->count() + count($out),
            ];
        });
    }

    /**
     * The latest things that happened, newest first: sales and OPD bills,
     * voids, stock received (posted GRNs), transfers, write-offs, posted
     * stock counts and manual adjustments.
     *
     * @return list<array{kind: string, title: string, detail: string, at: string, time: string}>
     */
    public function activity(int $limit = 6): array
    {
        return $this->remember("activity-{$limit}", function (Shop $shop) use ($limit): array {
            $timezone = (string) ($shop->timezone ?: config('app.timezone'));
            $events = [];

            foreach (Sale::query()->with('location')->whereNotNull('reported_at')->latest('reported_at')->latest('id')->limit($limit)->get() as $sale) {
                $events[] = [
                    'kind' => 'sale',
                    'title' => ($sale->bill_type === BillType::Opd ? 'OPD bill ' : 'Sale ').$sale->invoice_no,
                    'detail' => ShopMoney::format((int) $sale->grand_total).' · '.$sale->location?->name,
                    'at' => $sale->reported_at,
                ];
            }

            foreach (Sale::query()->where('status', SaleStatus::Voided->value)->whereNotNull('voided_at')->latest('voided_at')->latest('id')->limit($limit)->get() as $sale) {
                $events[] = ['kind' => 'void', 'title' => 'Voided '.$sale->invoice_no, 'detail' => (string) $sale->void_reason, 'at' => $sale->voided_at];
            }

            foreach (GoodsReceipt::query()->with(['supplier', 'location'])->where('status', GoodsReceiptStatus::Posted->value)->whereNotNull('posted_at')->latest('posted_at')->latest('id')->limit($limit)->get() as $receipt) {
                $events[] = [
                    'kind' => 'received',
                    'title' => 'Stock received '.$receipt->grn_number,
                    'detail' => $receipt->supplier->name.' → '.$receipt->location?->name,
                    'at' => $receipt->posted_at,
                ];
            }

            foreach (StockTransfer::query()->with(['fromLocation', 'toLocation'])->latest('transferred_at')->latest('id')->limit($limit)->get() as $transfer) {
                $events[] = [
                    'kind' => 'transfer',
                    'title' => 'Transfer '.$transfer->transfer_number,
                    'detail' => $transfer->fromLocation?->name.' → '.$transfer->toLocation?->name,
                    'at' => $transfer->transferred_at,
                ];
            }

            foreach (StockWriteOff::query()->with('location')->latest('written_off_at')->latest('id')->limit($limit)->get() as $writeOff) {
                $events[] = [
                    'kind' => 'adjustment',
                    'title' => 'Written off '.$writeOff->write_off_number,
                    'detail' => $writeOff->reason->label().' · '.$writeOff->location?->name,
                    'at' => $writeOff->written_off_at,
                ];
            }

            foreach (StockCount::query()->with('location')->where('status', StockCountStatus::Posted->value)->whereNotNull('posted_at')->latest('posted_at')->latest('id')->limit($limit)->get() as $count) {
                $events[] = ['kind' => 'adjustment', 'title' => 'Stock count '.$count->count_number.' posted', 'detail' => (string) $count->location?->name, 'at' => $count->posted_at];
            }

            // Adjustments made by hand: no document of their own.
            $manual = StockMovement::query()
                ->with(['location', 'variant' => fn ($variant) => $variant->withTrashed()->with('product')])
                ->whereIn('type', [StockMovementType::AdjustmentIn->value, StockMovementType::AdjustmentOut->value])
                ->whereNull('reference_type')
                ->latest('occurred_at')
                ->latest('id')
                ->limit($limit)
                ->get();

            foreach ($manual as $movement) {
                $quantity = (float) $movement->quantity;
                $events[] = [
                    'kind' => 'adjustment',
                    'title' => 'Adjusted '.trim($movement->variant->product?->name.' '.($movement->variant->name ?? '')),
                    'detail' => ($quantity > 0 ? '+' : '').Quantity::format($quantity).' · '.$movement->location?->name.($movement->reasonLabel() ? ' · '.$movement->reasonLabel() : ''),
                    'at' => $movement->occurred_at,
                ];
            }

            $events = array_filter($events, fn (array $event): bool => $event['at'] instanceof CarbonInterface);
            usort($events, fn (array $a, array $b): int => $b['at'] <=> $a['at']);
            $today = CarbonImmutable::now($timezone)->format('Y-m-d');

            return array_map(function (array $event) use ($timezone, $today): array {
                $local = CarbonImmutable::instance($event['at'])->setTimezone($timezone);

                return [
                    'kind' => $event['kind'],
                    'title' => $event['title'],
                    'detail' => $event['detail'],
                    'at' => $local->toIso8601String(),
                    'time' => $local->format('Y-m-d') === $today ? $local->format('H:i') : $local->format('d M, H:i'),
                ];
            }, array_slice($events, 0, $limit));
        });
    }

    /**
     * Batches with stock at a selling location on one expiry list, the soon
     * list within EXPIRY_DAYS; soonest expiry first.
     *
     * @return Builder<StockBatch>
     */
    private function batches(string $list): Builder
    {
        return $this->expiry->onList(StockBatch::query(), $list, self::EXPIRY_DAYS)
            ->whereIn('stock_batches.location_id', array_keys($this->sellingLocations()))
            ->fefo();
    }

    /** @return array<int, string> the active locations that sell (General Pharmacy, the doctor's), id => code */
    private function sellingLocations(): array
    {
        return Location::query()->where('is_active', true)->where('is_sellable', true)->ordered()->pluck('code', 'id')->all();
    }

    /**
     * A part's figures, kept for a minute per shop and shop day.
     *
     * @template T
     *
     * @param  \Closure(Shop): T  $compute
     * @return T
     */
    private function remember(string $part, \Closure $compute): mixed
    {
        $shop = $this->currentShop->getOrFail();
        $day = ReportPeriod::today($shop)->fromDate();

        return Cache::remember("clinic-dashboard:{$shop->getKey()}:{$part}:{$day}", self::CACHE_SECONDS, fn (): mixed => $compute($shop));
    }
}
