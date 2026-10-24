<?php

namespace Tests\Feature\Pos;

use App\Enums\AdjustmentReason;
use App\Enums\BillType;
use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Filament\Widgets\TodayStats;
use App\Models\Device;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\StockBatch;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Data\BatchInput;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use App\Services\Data\TransferLine;
use App\Services\Data\WriteOffLine;
use App\Services\ReportService;
use App\Services\ReturnService;
use App\Services\StockService;
use App\Services\StockTransferService;
use App\Services\StockWriteOffService;
use App\Support\ReportPeriod;
use Database\Seeders\DemoClinicCountersSeeder;
use Database\Seeders\DemoClinicPurchasingSeeder;
use Database\Seeders\DemoClinicStockControlSeeder;
use Database\Seeders\DemoClinicStockSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * "Gross profit today LKR -7,002.50" with "Sales today LKR 600.00" on the
 * demo clinic (seeded as at :8001, plus one OPD bill), investigated
 * 2026-10-22: genuine. The OPD bill charged a Medicines amount of 100.00
 * for two vials of Insulatard (3,800.00 each at cost) and 1 ml of
 * Chlorphenamine (2.50), with a 500.00 consultation: revenue 600.00, the
 * lines' cost 7,602.50. Profit reads only the sale lines, so opening stock,
 * counts, write-offs and transfers on the same day never move it, and a
 * refund counts once. The tile now says why under the figure.
 */
class DashboardProfitTest extends PharmacyTestCase
{
    private ReportService $reports;

    protected function setUp(): void
    {
        parent::setUp();

        // Everything migrate:fresh --seed gives the clinic outside tests.
        $this->seed([DemoClinicStockSeeder::class, DemoClinicPurchasingSeeder::class, DemoClinicCountersSeeder::class, DemoClinicStockControlSeeder::class]);
        $this->useShop($this->shop);
        $this->reports = app(ReportService::class);
    }

    private function variant(string $product): ProductVariant
    {
        return ProductVariant::query()->whereHas('product', fn ($query) => $query->where('name', $product))->firstOrFail();
    }

    /** The bill behind the figure: consultation 500.00, Medicines amount 100.00. */
    private function opdBill(): Sale
    {
        return app(CheckoutService::class)->checkout(new CheckoutData(
            device: Device::query()->where('code', 'T2')->firstOrFail(),
            cashier: User::query()->where('email', 'opd@clinic.test')->firstOrFail(),
            lines: [
                new CartLine($this->variant('Consultation')->getKey(), 1, unitPriceOverride: 50_000),
                new CartLine($this->variant('Insulatard 100 IU/ml Vial')->getKey(), 2, prescriber: 'Dr. S. Perera'),
                new CartLine($this->variant('Chlorphenamine Syrup 2 mg/5 ml')->getKey(), 1),
            ],
            payments: [new PaymentInput(PaymentMethod::Cash, 60_000)],
            billType: BillType::Opd,
            opdMedicinesAmount: 10_000,
        ));
    }

    /** Revenue (before tax) less cost, straight from today's sale and return lines: the reconciliation. */
    private function fromTheLines(): int
    {
        $today = ReportPeriod::today($this->shop);
        $sold = DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.shop_id', $this->shop->getKey())
            ->where('sales.status', '!=', 'voided')
            ->whereBetween('sales.reported_at', [$today->startUtc(), $today->endUtc()])
            ->selectRaw('SUM(sale_items.line_total - sale_items.tax_amount) as revenue, SUM(sale_items.unit_cost * sale_items.quantity) as cost')
            ->first();
        $returned = DB::table('sale_return_items')
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
            ->join('sale_items', 'sale_items.id', '=', 'sale_return_items.sale_item_id')
            ->where('sale_returns.shop_id', $this->shop->getKey())
            ->selectRaw('SUM(sale_return_items.refund_amount) as refunds, SUM(CASE WHEN sale_return_items.restocked THEN sale_items.unit_cost * sale_return_items.base_quantity / sale_items.unit_factor ELSE 0 END) as cost')
            ->first();

        return (int) round(((float) $sold->revenue - (float) ($returned->refunds ?? 0)) - ((float) $sold->cost - (float) ($returned->cost ?? 0)));
    }

    public function test_the_negative_profit_is_the_opd_medicines_amount_below_their_cost_and_reconciles_with_the_lines(): void
    {
        $today = ReportPeriod::today($this->shop);

        // The demo seed (opening stock, a count of expired stock, purchasing, a stock request) is no profit or loss.
        $this->assertSame(['revenue' => 0, 'cost' => 0, 'profit' => 0, 'margin' => null], $this->reports->profit($today)['total']);

        $sale = $this->opdBill();

        $this->assertSame(60_000, $this->reports->summary($today)['net']);
        $this->assertSame([50_000, 9_996, 4], $sale->items->pluck('line_total')->all());
        $this->assertSame([0, 380_000, 250], $sale->items->pluck('unit_cost')->all());

        $profit = $this->reports->profit($today);
        $this->assertSame(['revenue' => 60_000, 'cost' => 760_250, 'profit' => -700_250, 'margin' => -1167.1], $profit['total']);
        $this->assertSame(['revenue' => 10_000, 'cost' => 760_250, 'profit' => -750_250], $profit['opd_medicines']);
        $this->assertSame(['quantity' => 1.0, 'revenue' => 50_000, 'cost' => 0, 'profit' => 50_000], $profit['services_total']);
        $this->assertSame(-700_250, $this->reports->grossProfit($today));
        $this->assertSame($this->fromTheLines(), $this->reports->grossProfit($today));
    }

    public function test_write_offs_counts_transfers_and_stock_in_on_the_same_day_are_not_cost_of_sales(): void
    {
        $today = ReportPeriod::today($this->shop);
        $this->opdBill();

        // Write off the expired Panadol the demo found on the shelf at the doctor's counter.
        $expired = StockBatch::query()->where('batch_no', 'PN2208')->where('quantity', '>', 0)->firstOrFail();
        app(StockWriteOffService::class)->writeOff([new WriteOffLine((int) $expired->getKey())], AdjustmentReason::Expired, $this->owner);
        // Move Panadol to the doctor's counter, count Chlorphenamine short, and take in more insulin.
        app(StockTransferService::class)->create($this->general, $this->doctors, [new TransferLine((int) $this->variant('Panadol 500 mg Tablets')->getKey(), 20)], $this->owner);
        $syrup = $this->variant('Paracetamol Syrup 120 mg/5 ml');
        app(StockService::class)->adjustBatchBy($syrup, $this->general, StockBatch::query()->where('batch_no', 'PS3301')->where('location_id', $this->general->getKey())->firstOrFail(), -30, AdjustmentReason::CountCorrection, $this->owner);
        app(StockService::class)->stockIn($this->variant('Insulatard 100 IU/ml Vial'), $this->doctors, 5, $this->owner, 390_000, 'More', batch: new BatchInput('IN7731', now()->addYear()->format('m/Y')));

        $this->assertSame(-700_250, $this->reports->grossProfit($today));
        $this->assertSame($this->fromTheLines(), $this->reports->grossProfit($today));
    }

    public function test_a_refund_counts_once_and_takes_back_its_lines_cost(): void
    {
        $today = ReportPeriod::today($this->shop);
        $sale = $this->opdBill();
        $vials = $sale->items[1];

        // One vial back on the shelf: its share of the Medicines amount refunded, its cost taken back.
        $return = app(ReturnService::class)->process($sale, [$vials->getKey() => 1], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner, restock: true);

        $this->assertSame(4_998, $return->refund_total);
        $this->assertSame(60_000 - 4_998, $this->reports->summary($today)['net']);
        $profit = $this->reports->profit($today)['total'];
        $this->assertSame(60_000 - 4_998, $profit['revenue']);
        $this->assertSame(760_250 - 380_000, $profit['cost']);
        $this->assertSame((60_000 - 4_998) - (760_250 - 380_000), $profit['profit']);
        $this->assertSame($this->fromTheLines(), $profit['profit']);
    }

    public function test_the_tile_explains_the_figure_red_with_the_opd_medicines_below_cost(): void
    {
        $this->opdBill();
        $money = fn (int $minor): string => $this->shop->formatMoney($minor);

        $this->actingAs($this->owner);

        Livewire::test(TodayStats::class)
            ->assertSeeText('Sales today')
            ->assertSeeText($money(60_000))
            ->assertSeeText('Gross profit today')
            ->assertSeeText($money(-700_250))
            ->assertSeeText('Revenue '.$money(60_000).' − cost '.$money(760_250))
            ->assertSeeText('Margin -1,167.1%')
            ->assertSeeText('OPD medicines charged '.$money(10_000).', cost '.$money(760_250))
            ->assertSeeHtml('fi-color-danger')
            ->assertSee('profit-report?range=today', escape: false);
    }

    public function test_without_cost_view_the_tile_shows_the_margin_but_never_the_cost(): void
    {
        $this->opdBill();
        $viewer = User::factory()->create(['shop_id' => $this->shop->getKey()]);
        $viewer->givePermissionTo(['admin.access', 'reports.view', 'reports.profit']);

        $this->actingAs($viewer);

        Livewire::test(TodayStats::class)
            ->assertSeeText($this->shop->formatMoney(-700_250))
            ->assertSeeText('Revenue '.$this->shop->formatMoney(60_000))
            ->assertSeeText('Margin -1,167.1%')
            ->assertDontSeeText($this->shop->formatMoney(760_250))
            ->assertDontSeeText('OPD medicines charged');
    }

    public function test_a_profitable_day_stays_green(): void
    {
        app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->till,
            cashier: $this->cashier,
            lines: [new CartLine($this->variant('Panadol 500 mg Tablets')->getKey(), 10)],
            payments: [new PaymentInput(PaymentMethod::Cash, 15_000)],
        ));

        $profit = $this->reports->grossProfit(ReportPeriod::today($this->shop));
        $this->assertGreaterThan(0, $profit);
        $this->assertSame($this->fromTheLines(), $profit);

        $this->actingAs($this->owner);

        Livewire::test(TodayStats::class)
            ->assertSeeText($this->shop->formatMoney($profit))
            ->assertDontSeeHtml('fi-color-danger')
            ->assertDontSeeText('OPD medicines charged');
    }
}
