<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Models\Sale;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\PaymentInput;
use App\Services\ReportService;
use App\Services\ReturnService;
use App\Services\ShiftService;
use App\Services\StockService;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;

class ReportServiceTest extends PosTestCase
{
    private ReportService $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reports = app(ReportService::class);
        $this->shop->update(['timezone' => 'Asia/Colombo']); // UTC+05:30
    }

    /** A sale of $quantity plugs (LKR 1,450.00 each) at a UTC time. */
    private function sellPlugs(string $utc, float $quantity, ?User $cashier = null, array $payments = []): Sale
    {
        $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));

        return app(CheckoutService::class)->checkout($this->cart(
            [new CartLine($this->variant('CPR8EA-9')->getKey(), $quantity)],
            $payments === [] ? null : $payments,
            cashier: $cashier,
        ));
    }

    private function period(string $from, ?string $to = null): ReportPeriod
    {
        return ReportPeriod::between($this->shop->fresh(), $from, $to ?? $from);
    }

    public function test_days_follow_the_shops_timezone_not_utc(): void
    {
        $this->sellPlugs('2026-09-11 18:29:00', 1); // 23:59 on the 11th in Colombo
        $this->sellPlugs('2026-09-11 18:31:00', 2); // 00:01 on the 12th in Colombo

        $this->assertSame(1, $this->reports->summary($this->period('2026-09-11'))['sales_count']);
        $this->assertSame(145_000, $this->reports->summary($this->period('2026-09-11'))['gross']);

        $twelfth = $this->reports->summary($this->period('2026-09-12'));
        $this->assertSame(1, $twelfth['sales_count']);
        $this->assertSame(290_000, $twelfth['gross']);

        $days = collect($this->reports->salesByDay($this->period('2026-09-10', '2026-09-12')))->keyBy('date');
        $this->assertSame(0, $days['2026-09-10']['gross']);
        $this->assertSame(145_000, $days['2026-09-11']['gross']);
        $this->assertSame(290_000, $days['2026-09-12']['gross']);
    }

    public function test_today_is_the_shops_today(): void
    {
        $this->sellPlugs('2026-09-11 18:31:00', 1); // already the 12th in Colombo

        $this->travelTo(CarbonImmutable::parse('2026-09-11 19:00:00', 'UTC'));

        $today = ReportPeriod::today($this->shop->fresh());
        $this->assertSame('2026-09-12', $today->fromDate());
        $this->assertSame(1, $this->reports->summary($today)['sales_count']);
    }

    public function test_voided_sales_are_left_out(): void
    {
        $kept = $this->sellPlugs('2026-09-12 05:00:00', 1);
        $voided = $this->sellPlugs('2026-09-12 06:00:00', 3);
        app(CheckoutService::class)->void($voided, $this->owner, 'Rung up twice');

        $summary = $this->reports->summary($this->period('2026-09-12'));

        $this->assertSame(1, $summary['sales_count']);
        $this->assertSame($kept->grand_total, $summary['gross']);
        $this->assertSame(1, $this->reports->salesByDay($this->period('2026-09-12'))[0]['sales_count']);
        $this->assertSame([], array_filter($this->reports->bestSellers($this->period('2026-09-12')), fn ($row) => $row['quantity'] > 1));
    }

    public function test_refunds_are_subtracted_on_the_day_they_are_given(): void
    {
        $sale = $this->sellPlugs('2026-09-12 05:00:00', 2);

        $this->travelTo(CarbonImmutable::parse('2026-09-13 05:00:00', 'UTC'));
        app(ReturnService::class)->process($sale, [$sale->items->first()->getKey() => 1], ReturnReason::WrongItem, PaymentMethod::Cash, $this->owner);

        $day12 = $this->reports->summary($this->period('2026-09-12'));
        $day13 = $this->reports->summary($this->period('2026-09-13'));
        $both = $this->reports->summary($this->period('2026-09-12', '2026-09-13'));

        $this->assertSame(290_000, $day12['net']);
        $this->assertSame(145_000, $day13['refunds']);
        $this->assertSame(-145_000, $day13['net']);
        $this->assertSame(145_000, $both['net']);
        $this->assertSame(145_000, $both['average']);

        $cashier = $this->reports->salesByCashier($this->period('2026-09-12', '2026-09-13'))[0];
        $this->assertSame(145_000, $cashier['net']);

        $category = collect($this->reports->salesByGroup($this->period('2026-09-12', '2026-09-13'), 'category'))->first();
        $this->assertSame(145_000, $category['net']);
        $this->assertSame(1.0, $category['quantity']);

        $best = $this->reports->bestSellers($this->period('2026-09-12', '2026-09-13'));
        $this->assertSame(1.0, $best[0]['quantity']);
    }

    public function test_reports_agree_with_each_other(): void
    {
        $this->sellPlugs('2026-09-12 05:00:00', 1);
        $this->sellPlugs('2026-09-12 06:00:00', 2, $this->owner);
        $period = $this->period('2026-09-12');

        $net = $this->reports->summary($period)['net'];

        $this->assertSame($net, collect($this->reports->salesByDay($period))->sum('net'));
        $this->assertSame($net, collect($this->reports->salesByCashier($period))->sum('net'));
        $this->assertSame($net, collect($this->reports->salesByGroup($period, 'category'))->sum('net'));
        $this->assertSame($net, collect($this->reports->salesByGroup($period, 'brand'))->sum('net'));
        $this->assertSame($net, collect($this->reports->paymentMethods($period))->sum('net'));
        $this->assertCount(2, $this->reports->salesByCashier($period));
    }

    public function test_cash_is_net_of_change_and_split_payments_are_grouped(): void
    {
        $this->sellPlugs('2026-09-12 05:00:00', 2, payments: [
            new PaymentInput(PaymentMethod::Card, 100_000),
            new PaymentInput(PaymentMethod::Cash, 500_000),
        ]);

        $methods = collect($this->reports->paymentMethods($this->period('2026-09-12')))->keyBy('method');

        $this->assertSame(100_000, $methods['Card']['taken']);
        $this->assertSame(190_000, $methods['Cash']['taken']); // 5,000 tendered - 3,100 change
    }

    public function test_profit_uses_snapshotted_cost_and_reverses_restocked_returns(): void
    {
        $plug = $this->variant('CPR8EA-9');
        $cost = $plug->cost_price;

        $sale = $this->sellPlugs('2026-09-12 05:00:00', 3);
        $period = $this->period('2026-09-12');

        $this->assertSame(3 * (145_000 - $cost), $this->reports->grossProfit($period));

        $row = collect($this->reports->profitByProduct($period))->firstWhere('product', 'NGK Spark Plug');
        $this->assertSame(435_000, $row['revenue']);
        $this->assertSame(3 * $cost, $row['cost']);

        // Returned and back on the shelf: revenue and cost both reverse.
        app(ReturnService::class)->process($sale, [$sale->items->first()->getKey() => 1], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);
        $this->assertSame(2 * (145_000 - $cost), $this->reports->grossProfit($period));

        // Returned damaged: the money goes back but the cost is a loss.
        app(ReturnService::class)->process($sale, [$sale->items->first()->getKey() => 1], ReturnReason::FaultyDamaged, PaymentMethod::Cash, $this->owner, restock: false);
        $this->assertSame((145_000 - $cost) - $cost, $this->reports->grossProfit($period));
    }

    public function test_voids_and_returns_list_their_reasons(): void
    {
        $voided = $this->sellPlugs('2026-09-12 05:00:00', 1);
        app(CheckoutService::class)->void($voided, $this->owner, 'Customer left');
        $sale = $this->sellPlugs('2026-09-12 06:00:00', 1);
        app(ReturnService::class)->process($sale, [$sale->items->first()->getKey() => 1], ReturnReason::WrongItem, PaymentMethod::Cash, $this->owner, note: 'Needed the iridium one');

        $rows = collect($this->reports->voidsAndReturns($this->period('2026-09-12')))->keyBy('type');

        $this->assertSame('Customer left', $rows['Void']['reason']);
        $this->assertSame($voided->invoice_no, $rows['Void']['reference']);
        $this->assertSame('Wrong item — Needed the iridium one', $rows['Return']['reason']);
        $this->assertSame('Restocked', $rows['Return']['stock']);
        $this->assertSame('Restocked', $rows['Void']['stock']);
        $this->assertSame(145_000, $rows['Return']['amount']);
        $this->assertSame('2026-09-12 11:30', $rows['Return']['when']); // shop time
    }

    public function test_shift_history_shows_cash_differences_and_open_shift_cash(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-12 03:00:00', 'UTC'));
        $shifts = app(ShiftService::class);
        $shift = $shifts->open($this->till, $this->cashier, 500_000);
        $this->sellPlugs('2026-09-12 04:00:00', 2);

        $this->assertSame(790_000, $this->reports->cashInOpenShifts());

        $shifts->close($shift, $this->cashier, 780_000);
        $row = $this->reports->shiftHistory($this->period('2026-09-12'))[0];

        $this->assertSame('T1', $row['till']);
        $this->assertSame(790_000, $row['expected_cash']);
        $this->assertSame(-10_000, $row['difference']);
        $this->assertSame(0, $this->reports->cashInOpenShifts());
    }

    public function test_stock_valuation_matches_the_stock_on_hand(): void
    {
        $plug = $this->variant('CPR8EA-9');
        app(StockService::class)->stockIn($plug, $this->location, 10, $this->owner);

        $withCost = $this->reports->stockValuation(true);
        $withoutCost = $this->reports->stockValuation(false);

        $expectedRetail = 0;
        $expectedCost = 0;
        foreach (StockLevel::query()->with('variant.prices')->get() as $level) {
            if ((float) $level->quantity > 0) {
                $expectedRetail += (int) round((float) $level->quantity * ($level->variant->prices->first()->price ?? 0));
                $expectedCost += (int) round((float) $level->quantity * $level->variant->cost_price);
            }
        }

        $this->assertSame($expectedRetail, collect($withCost)->sum('retail_value'));
        $this->assertSame($expectedCost, collect($withCost)->sum('cost_value'));
        $this->assertArrayNotHasKey('cost_value', $withoutCost[0]);
    }

    public function test_low_stock_lists_variants_at_or_below_reorder_level(): void
    {
        $plug = $this->variant('CPR8EA-9');
        $plug->update(['reorder_level' => 50]);

        $rows = $this->reports->lowStock();

        $this->assertContains($plug->sku, array_column($rows, 'sku'));
        $this->assertSame(40.0, collect($rows)->firstWhere('sku', $plug->sku)['on_hand']);
        $this->assertSame($this->reports->lowStockCount(), count($rows));
    }

    public function test_stock_movements_are_listed_in_shop_time_and_cost_only_on_request(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-12 18:45:00', 'UTC')); // 00:15 on the 13th
        app(StockService::class)->stockIn($this->variant('CPR8EA-9'), $this->location, 5, $this->owner, note: 'Delivery');

        $this->assertSame(0, $this->reports->stockMovements($this->period('2026-09-12'), false, 1, 50)->total());

        $page = $this->reports->stockMovements($this->period('2026-09-13'), false, 1, 50);
        $this->assertSame(1, $page->total());
        $this->assertSame('2026-09-13 00:15', $page->items()[0]['when']);
        $this->assertArrayNotHasKey('unit_cost', $page->items()[0]);

        $this->assertArrayHasKey('unit_cost', $this->reports->stockMovements($this->period('2026-09-13'), true, 1, 50)->items()[0]);
    }

    public function test_periods_are_capped_and_ordered(): void
    {
        $period = $this->period('2026-09-12', '2020-01-01');

        $this->assertSame('2026-09-12', $period->toDate());
        $this->assertCount(ReportPeriod::MAX_DAYS, $period->days());
    }
}
