<?php

namespace Tests\Feature\Pos;

use App\Enums\AdjustmentReason;
use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Enums\SaleStatus;
use App\Filament\Pages\Reports\BatchValuationReport;
use App\Filament\Pages\Reports\ControlledDrugRegisterReport;
use App\Filament\Pages\Reports\DailySummaryReport;
use App\Filament\Pages\Reports\ExpiryReport;
use App\Filament\Pages\Reports\MonthlySalesReport;
use App\Filament\Pages\Reports\ProfitReport;
use App\Filament\Pages\Reports\PurchasesReport;
use App\Filament\Pages\Reports\SlowMovingStockReport;
use App\Filament\Pages\Reports\StockAdjustmentsReport;
use App\Filament\Pages\Reports\StockValuationReport;
use App\Filament\Pages\Reports\SupplierAgingReport;
use App\Filament\Pages\Reports\TransfersReport;
use App\Filament\Widgets\BestSellersTable;
use App\Filament\Widgets\CounterSalesStats;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\PaymentInput;
use App\Services\ProductCatalogService;
use App\Services\ReportService;
use App\Services\ReturnService;
use App\Services\StockService;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Livewire\Livewire;

/**
 * The reports branch on the retail demo shop (docs/PHARMACY.md §9, branch
 * 11), pharmacy, multi_location and purchasing off: the existing reports
 * give the numbers they always gave (profit by product against the code it
 * replaced), and the new reports work without any pharmacy
 * field (no schedules, OPD bills, counters, batches or register).
 */
class RetailReportsTest extends AdminTestCase
{
    private ReportService $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reports = app(ReportService::class);
        $this->shop->update(['timezone' => 'Asia/Colombo']);
        $this->travelTo(CarbonImmutable::parse('2026-09-16 04:30:00', 'UTC'));
    }

    /** The fixture: plugs and oil in cash and card, a discount, a void, a return restocked and one not. */
    private function aDay(): void
    {
        $checkout = app(CheckoutService::class);
        $plug = $this->variant('CPR8EA-9')->getKey();
        $oil = $this->variant('MOTUL')->getKey();

        $sale = $checkout->checkout($this->cart([new CartLine($plug, 3), new CartLine($oil, 2)], [new PaymentInput(PaymentMethod::Cash, 1_200_000)], saleDiscount: 10_000));
        $checkout->checkout($this->cart([new CartLine($oil, 1, lineDiscount: 5_000)], [new PaymentInput(PaymentMethod::Card, 315_000)]));
        $checkout->void($checkout->checkout($this->cart([new CartLine($plug, 1)])), $this->owner, 'Mistake');
        app(ReturnService::class)->process($sale, [$sale->items[0]->getKey() => 1], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);
        app(ReturnService::class)->process($sale, [$sale->items[1]->getKey() => 1], ReturnReason::FaultyDamaged, PaymentMethod::Cash, $this->owner, restock: false);
        app(StockService::class)->adjustTo($this->variant('MOTUL'), $this->location, 10, AdjustmentReason::Damaged, $this->owner);
    }

    /**
     * Profit by product and gross profit as ReportService gave them before
     * the reports branch (copied from it, commit 9bd7705): each line's stored
     * cost, a restocked return taking its own back, rounded per product (and
     * once over everything for the gross profit).
     *
     * @return array{rows: list<array<string, mixed>>, gross: int}
     */
    private function profitAsBefore(ReportPeriod $period): array
    {
        $sales = fn (): Builder => Sale::query()->toBase()
            ->where('sales.status', '!=', SaleStatus::Voided->value)
            ->where('sales.reported_at', '>=', $period->startUtc())
            ->where('sales.reported_at', '<', $period->endUtc());
        $returnLines = fn (): Builder => SaleReturn::query()->toBase()
            ->where('sale_returns.returned_at', '>=', $period->startUtc())
            ->where('sale_returns.returned_at', '<', $period->endUtc())
            ->join('sale_return_items', 'sale_return_items.sale_return_id', '=', 'sale_returns.id')
            ->join('sale_items', 'sale_items.id', '=', 'sale_return_items.sale_item_id');

        $profitRows = function (bool $perProduct) use ($sales, $returnLines): Collection {
            $group = fn (Builder $query): Builder => $perProduct
                ? $query->join('product_variants', 'product_variants.id', '=', 'sale_items.product_variant_id')
                    ->join('products', 'products.id', '=', 'product_variants.product_id')
                    ->selectRaw('products.id as product_id, products.name as product')
                    ->groupBy('products.id', 'products.name')
                : $query->selectRaw('0 as product_id, ? as product', ['All products']);

            $sold = $group($sales()->join('sale_items', 'sale_items.sale_id', '=', 'sales.id'))
                ->selectRaw('SUM(sale_items.base_quantity) as quantity, SUM(sale_items.line_total - sale_items.tax_amount) as revenue, SUM(sale_items.unit_cost * sale_items.quantity) as cost')
                ->get();

            $returned = $group($returnLines())
                ->selectRaw('SUM(sale_return_items.base_quantity) as quantity')
                ->selectRaw('SUM(CASE WHEN sale_items.line_total = 0 THEN 0 ELSE sale_return_items.refund_amount * 1.0 * (sale_items.line_total - sale_items.tax_amount) / sale_items.line_total END) as revenue')
                ->selectRaw('SUM(CASE WHEN sale_return_items.restocked = ? THEN sale_items.unit_cost * sale_return_items.quantity ELSE 0 END) as cost', [true])
                ->get()
                ->keyBy('product_id');

            return $sold
                ->concat($returned->reject(fn ($row) => $sold->contains('product_id', $row->product_id))->map(fn ($row) => (object) [
                    'product_id' => $row->product_id, 'product' => $row->product, 'quantity' => 0, 'revenue' => 0, 'cost' => 0,
                ]))
                ->filter(fn ($row) => $row->quantity !== null)
                ->map(function ($row) use ($returned): array {
                    $back = $returned->get($row->product_id);
                    $revenue = (int) round((float) $row->revenue - (float) ($back->revenue ?? 0));
                    $cost = (int) round((float) $row->cost - (float) ($back->cost ?? 0));

                    return [
                        'product' => (string) $row->product,
                        'quantity' => round((float) $row->quantity - (float) ($back->quantity ?? 0), 3),
                        'revenue' => $revenue,
                        'cost' => $cost,
                        'profit' => $revenue - $cost,
                        'margin' => $revenue === 0 ? null : round(($revenue - $cost) / $revenue * 100, 1),
                    ];
                })
                ->values();
        };

        return [
            'rows' => array_values($profitRows(true)->sortByDesc('profit')->values()->all()),
            'gross' => (int) $profitRows(false)->sum('profit'),
        ];
    }

    public function test_profit_by_product_gives_exactly_the_numbers_it_gave_before_this_branch(): void
    {
        // Tax included at 18%, so refunds take back revenue with fractions; a service with a cost of its own.
        $this->shop->update(['tax_rate' => 18, 'prices_include_tax' => true]);
        $fitting = app(ProductCatalogService::class)->create(['name' => 'Fitting labour', 'unit' => 'job', 'track_stock' => false], [['price' => 75_000, 'cost' => 20_000]], user: $this->owner)->variants()->firstOrFail();
        $this->aDay();

        $checkout = app(CheckoutService::class);
        $plug = $this->variant('CPR8EA-9')->getKey();
        $sale = $checkout->checkout($this->cart([new CartLine($plug, 7), new CartLine($fitting->getKey(), 2)], [new PaymentInput(PaymentMethod::Cash, 2_000_000)], saleDiscount: 3_333));
        app(ReturnService::class)->process($sale, [$sale->items[0]->getKey() => 3], ReturnReason::ChangedMind, PaymentMethod::Card, $this->owner);
        app(ReturnService::class)->process($sale, [$sale->items[1]->getKey() => 1], ReturnReason::ChangedMind, PaymentMethod::Card, $this->owner);

        // A sale the day before and its return today: each counts on its own day.
        $this->travelTo(CarbonImmutable::parse('2026-09-17 04:30:00', 'UTC'));
        $next = $checkout->checkout($this->cart([new CartLine($this->variant('MOTUL')->getKey(), 3)], [new PaymentInput(PaymentMethod::Cash, 1_000_000)]));
        app(ReturnService::class)->process($next, [$next->items[0]->getKey() => 1], ReturnReason::FaultyDamaged, PaymentMethod::Cash, $this->owner, restock: false);
        $this->travelTo(CarbonImmutable::parse('2026-09-18 04:30:00', 'UTC'));
        app(ReturnService::class)->process($sale, [$sale->items[0]->getKey() => 1], ReturnReason::ChangedMind, PaymentMethod::Card, $this->owner);

        foreach ([['2026-09-16', '2026-09-16'], ['2026-09-17', '2026-09-17'], ['2026-09-18', '2026-09-18'], ['2026-09-01', '2026-09-30']] as [$from, $to]) {
            $period = ReportPeriod::between($this->shop->fresh(), $from, $to);
            $before = $this->profitAsBefore($period);

            $this->assertNotSame([], $before['rows']);
            $this->assertSame($before['rows'], $this->reports->profitByProduct($period), "{$from} – {$to}");

            // The 18th has a refund and no sale: the old gross profit hid the refund (0, its empty
            // sales row masking the return row). It is now what Profit by product adds up to.
            $this->assertSame($from === '2026-09-18' ? (int) collect($before['rows'])->sum('profit') : $before['gross'], $this->reports->grossProfit($period), "{$from} – {$to}");
        }

        $refundDay = ReportPeriod::between($this->shop->fresh(), '2026-09-18', '2026-09-18');
        $this->assertSame(0, $this->profitAsBefore($refundDay)['gross']);
        $this->assertLessThan(0, $this->reports->grossProfit($refundDay));

        // The service keeps its own cost, as before.
        $month = collect($this->reports->profitByProduct(ReportPeriod::between($this->shop->fresh(), '2026-09-01', '2026-09-30')))->keyBy('product');
        $this->assertSame(20_000, $month['Fitting labour']['cost']);
    }

    public function test_the_sales_reports_reconcile_with_one_location_and_no_pharmacy_fields(): void
    {
        $this->aDay();
        $period = ReportPeriod::today($this->shop->fresh());
        $net = $this->reports->summary($period)['net'];

        $counters = $this->reports->counters($period);
        $this->assertSame(['Main shop'], array_column($counters, 'location'));
        $this->assertSame([$net, $net, 0, 0, 0.0], [$counters[0]['net'], $counters[0]['goods'], $counters[0]['services'], $counters[0]['opd_bills'], $counters[0]['consultations']]);
        $this->assertSame([1, 145_000], [$counters[0]['voids'], $counters[0]['voids_total']]);
        $this->assertSame($net, collect($this->reports->paymentMethods($period))->sum('net'));
        $this->assertSame($net, collect($this->reports->salesByItem($period))->sum('net'));
        $this->assertSame($net, collect($this->reports->salesByGroup($period, 'location'))->sum('net'));
        $this->assertSame(['Not a medicine'], array_column($this->reports->salesByGroup($period, 'schedule'), 'name'));
        $this->assertSame(['opd_bills' => 0, 'consultations' => 0.0, 'income' => 0, 'refunds' => 0, 'net' => 0, 'opd_medicines' => 0], $this->reports->consultations($period));
        $this->assertSame(['Main shop'], array_column($this->reports->stockValuation(true, by: 'location'), 'location'));
        $this->assertSame($this->reports->stockValueAtCost(), collect($this->reports->stockValuation(true, by: 'item'))->sum('cost_value'));
    }

    public function test_the_new_reports_open_for_retail_without_pharmacy_sections(): void
    {
        $this->aDay();
        $this->actingAs($this->owner);

        foreach ([DailySummaryReport::class, MonthlySalesReport::class, ProfitReport::class, StockValuationReport::class, SlowMovingStockReport::class, StockAdjustmentsReport::class] as $report) {
            $this->get($report::getUrl())->assertSuccessful();
        }

        Livewire::test(DailySummaryReport::class)
            ->assertSee('By counter')
            ->assertSee('Services and goods')
            ->assertDontSee('Consultation fees')
            ->assertDontSee('OPD bills')
            ->assertDontSee('Counter</label>', false);

        Livewire::test(MonthlySalesReport::class)
            ->assertSee('By item')
            ->assertDontSee('By schedule')
            ->assertDontSee('OPD consultations')
            ->assertDontSee('By location');

        Livewire::test(ProfitReport::class)->assertSee('Goods by category')->assertDontSee('Medicines');

        foreach ([BatchValuationReport::class, ExpiryReport::class, ControlledDrugRegisterReport::class, TransfersReport::class, PurchasesReport::class, SupplierAgingReport::class] as $report) {
            $this->assertFalse($report::canAccess(), $report);
            $this->get($report::getUrl())->assertForbidden();
        }
    }

    public function test_the_dashboard_keeps_its_widgets_and_adds_the_month(): void
    {
        $this->aDay();
        $this->actingAs($this->owner);

        Livewire::test(CounterSalesStats::class)
            ->assertSee('This month')
            ->assertSee('Last month')
            ->assertDontSee('Main shop today')
            ->assertDontSee('Consultations today');

        Livewire::test(BestSellersTable::class)->assertSee('Best sellers this week');
    }
}
