<?php

namespace Tests\Feature\Pos;

use App\Enums\BillType;
use App\Enums\PaymentMethod;
use App\Filament\Widgets\Clinic\ActivityTimeline;
use App\Filament\Widgets\Clinic\ClinicKpis;
use App\Filament\Widgets\Clinic\GreetingHero;
use App\Filament\Widgets\Clinic\LocationPerformance;
use App\Filament\Widgets\Clinic\SalesTrendChart;
use App\Filament\Widgets\Clinic\StockAlerts;
use App\Filament\Widgets\Clinic\StockValueDonut;
use App\Filament\Widgets\Clinic\TodaysInsights;
use App\Filament\Widgets\Clinic\TodaysOpdBills;
use App\Models\Category;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Services\CheckoutService;
use App\Services\ClinicDashboardService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use App\Services\Data\ReceiptLine;
use App\Services\Data\TransferLine;
use App\Services\ReportService;
use App\Services\StockTransferService;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithPurchasing;

/**
 * The clinic dashboard's figures (feat/clinic-theme-dashboard), on the demo
 * clinic, Sunday 18 October 2026 at 10:00 in Colombo. Each comes from the
 * reports by their rules (voided bills left out, the shop's own days), so
 * where a report has the figure the test compares with it. Expiry alerts are
 * the selling counters' (not Quarantine's), within 30 days.
 *
 * The day: General Pharmacy sold 5 tablets (10,000) last Sunday, 2 (4,000)
 * yesterday and 5 (10,000) today; the doctor's rang up an OPD bill
 * (consultation 150,000 + medicines 80,000) and a second one, voided.
 */
class ClinicDashboardTest extends BatchTestCase
{
    use WorksWithCheckout;
    use WorksWithPurchasing;

    private ClinicDashboardService $figures;

    private ProductVariant $tabs;

    private ProductVariant $syrup;

    private Sale $genSale;

    private Sale $opdBill;

    private Sale $voidedBill;

    private Location $quarantine;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->figures = app(ClinicDashboardService::class);
        $this->quarantine = Location::query()->where('code', 'QUAR')->sole();

        // Stock arrives on Saturday 10 October.
        $this->travelTo(CarbonImmutable::parse('2026-10-10 04:30:00', 'UTC'));
        $this->tabs = $this->medicine();
        $this->syrup = $this->syrup();
        $amoxil = $this->medicine('Amoxil 250 mg Capsules');
        $ors = $this->medicine('ORS Sachets');
        $this->categorise($this->tabs, 'Analgesics');
        $this->categorise($this->syrup, 'Syrups');
        $this->categorise($amoxil, 'Antibiotics');

        $this->receive($this->tabs, 'G1', '03/2027', 200);
        $this->receive($this->tabs, 'D1', '03/2027', 100, $this->doctors);
        $this->receive($this->syrup, 'S1', '03/2027', 600, $this->doctors, 300);
        $this->receive($amoxil, 'E5', '2026-10-23', 10);  // 5 days left on the 18th
        $this->receive($amoxil, 'E20', '2026-11-07', 10); // 20 days left
        $this->foundExpired($amoxil, 'X1', '2026-10-01', 4);
        $this->foundExpired($amoxil, 'Q1', '2026-09-01', 6, $this->quarantine); // already quarantined: not an alert
        $ors->update(['reorder_level' => 5]); // none in stock: out of stock at General Pharmacy

        // Last Sunday and yesterday at General Pharmacy.
        $this->travelTo(CarbonImmutable::parse('2026-10-11 05:00:00', 'UTC'));
        $this->sell([new CartLine($this->tabs->getKey(), 5)]);
        $this->travelTo(CarbonImmutable::parse('2026-10-17 05:00:00', 'UTC'));
        $this->sell([new CartLine($this->tabs->getKey(), 2)]);

        // Today: stock received 08:00, a transfer 08:30, a sale 09:00, two OPD bills 09:30 and 09:45, one voided 09:50.
        $this->travelTo(CarbonImmutable::parse('2026-10-18 02:30:00', 'UTC'));
        $this->postReceipt($this->supplier(), [new ReceiptLine((int) $this->tabs->getKey(), 10, unitCost: 1_000, batchNo: 'G2', expiry: '03/2027')]);
        $this->travelTo(CarbonImmutable::parse('2026-10-18 03:00:00', 'UTC'));
        app(StockTransferService::class)->create($this->general, $this->doctors, [new TransferLine($this->tabs->getKey(), 5)], $this->owner, 'Doctor ran short');
        $this->travelTo(CarbonImmutable::parse('2026-10-18 03:30:00', 'UTC'));
        $this->genSale = $this->sell([new CartLine($this->tabs->getKey(), 5)]);
        $this->travelTo(CarbonImmutable::parse('2026-10-18 04:00:00', 'UTC'));
        $this->opdBill = $this->opdBill(150_000, 80_000);
        $this->travelTo(CarbonImmutable::parse('2026-10-18 04:15:00', 'UTC'));
        $this->voidedBill = $this->opdBill(100_000, 20_000);
        $this->travelTo(CarbonImmutable::parse('2026-10-18 04:20:00', 'UTC'));
        app(CheckoutService::class)->void($this->voidedBill, $this->owner, 'Wrong patient');
        $this->travelTo(CarbonImmutable::parse('2026-10-18 04:30:00', 'UTC'));

        $this->actingAs($this->owner);
        Livewire::withoutLazyLoading();
    }

    private function categorise(ProductVariant $variant, string $category): void
    {
        $variant->product()->firstOrFail()->update(['category_id' => Category::query()->create(['name' => $category, 'slug' => strtolower($category)])->getKey()]);
    }

    private function opdBill(int $consultation, int $medicines): Sale
    {
        return app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->docTill(),
            cashier: $this->cashierAt($this->doctors),
            lines: [
                new CartLine($this->consultation($consultation)->getKey(), 1),
                new CartLine($this->syrup->getKey(), 40),
                new CartLine($this->tabs->getKey(), 10),
            ],
            payments: [new PaymentInput(PaymentMethod::Cash, 1_000_000)],
            billType: BillType::Opd,
            opdMedicinesAmount: $medicines,
        ));
    }

    public function test_todays_figures_are_the_reports_with_voided_bills_left_out(): void
    {
        $today = $this->figures->today();
        $reports = app(ReportService::class);

        $this->assertSame(10_000 + 230_000, $today['sales']);
        $this->assertSame($reports->summary(ReportPeriod::today($this->shop))['net'], $today['sales']);
        $this->assertSame(2, $today['sales_count']);
        $this->assertSame(4_000, $today['yesterday']);
        $this->assertSame(10_000, $today['last_week']);
        $this->assertSame('Sunday', $today['last_week_day']);
        $this->assertSame([1, 150_000], [$today['opd_bills'], $today['consultation_fees']]);
        // ORS at General Pharmacy; Amoxil E5 and E20 within 30 days, X1 expired there (Q1 is in Quarantine).
        $this->assertSame([1, 1, 0], [$today['low_stock'], $today['out_of_stock'], $today['negative_stock']]);
        $this->assertSame([2, 1], [$today['expiring'], $today['expired']]);
        $this->assertSame(0, $today['requests']);
        $this->assertSame(5900.0, ClinicDashboardService::change($today['sales'], $today['yesterday']));
        $this->assertNull(ClinicDashboardService::change(100, 0));
    }

    public function test_the_kpi_cards_show_sales_against_yesterday_opd_bills_low_stock_and_expiry_in_red(): void
    {
        Livewire::test(ClinicKpis::class)
            ->assertOk()
            ->assertSeeInOrder(["Today's sales", 'LKR 2,400.00', '5,900.0% up on yesterday'])
            ->assertSeeInOrder(['OPD bills today', '1', 'Consultation fees LKR 1,500.00'])
            ->assertSeeInOrder(['Low-stock items', '1', '1 out of stock'])
            ->assertSeeInOrder(['Expiring ≤ 30 days', '2', '1 already expired'])
            ->assertSeeHtml('pos-kpi-alert');

        $this->assertSame('60s', (fn (): ?string => $this->getPollingInterval())->call(Livewire::test(ClinicKpis::class)->instance()));
    }

    public function test_location_performance_is_this_months_split_by_counter_and_the_top_categories(): void
    {
        $performance = $this->figures->locationPerformance();

        // General Pharmacy 10,000 + 4,000 + 10,000; the doctor's the OPD bill. Quarantine sells nothing.
        $this->assertSame(['GEN', 'DOC'], array_column($performance['locations'], 'code'));
        $this->assertSame([24_000, 230_000], array_column($performance['locations'], 'net'));
        $this->assertSame([9.4, 90.6], array_column($performance['locations'], 'share'));
        $this->assertSame(254_000, $performance['total']);

        $byCategory = collect(app(ReportService::class)->salesByGroup(ReportPeriod::thisMonth($this->shop), 'category'))->where('net', '>', 0)->sortByDesc('net')->values();
        $this->assertSame($byCategory->take(4)->pluck('name')->all(), array_column($performance['categories'], 'name'));
        $this->assertContains('Analgesics', array_column($performance['categories'], 'name'));
        $this->assertEqualsWithDelta(100.0, array_sum(array_column($performance['categories'], 'share')), 0.2);

        Livewire::test(LocationPerformance::class)->assertOk()->assertSeeInOrder(['GEN', 'General Pharmacy', '9.4%', 'DOC', '90.6%', 'Top categories']);
    }

    public function test_the_sales_trend_is_this_weeks_daily_net_monday_to_sunday(): void
    {
        $week = $this->figures->weekTrend();

        $this->assertSame(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], array_column($week, 'label'));
        $this->assertSame('2026-10-12', $week[0]['date']);
        $this->assertSame([0, 0, 0, 0, 0, 4_000, 240_000], array_column($week, 'net'));

        $chart = Livewire::test(SalesTrendChart::class)->assertOk()->instance();
        $data = (fn (): array => $this->getData())->call($chart);
        $this->assertSame([0.0, 0.0, 0.0, 0.0, 0.0, 40.0, 2400.0], $data['datasets'][0]['data']);
        $this->assertSame('LKR 2,440.00 so far this week', $chart->getDescription());
        // No filter and no polling; the line in Clinic Teal's primary.
        $this->assertNull((fn (): ?array => $this->getFilters())->call($chart));
        $this->assertNull((fn (): ?string => $this->getPollingInterval())->call($chart));
        $this->assertSame('#0f766e', SalesTrendChart::lineColor());
        $options = (string) (fn (): mixed => $this->getOptions())->call($chart);
        $this->assertStringNotContainsString('"', $options);
        $this->assertStringContainsString('createLinearGradient', $options);
    }

    public function test_days_still_to_come_have_no_point_on_the_trend(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 04:30:00', 'UTC')); // Wednesday

        $this->assertSame([0, 0, 0, null, null, null, null], array_column($this->figures->weekTrend(), 'net'));
    }

    public function test_stock_value_is_each_selling_counters_stock_at_average_cost(): void
    {
        $value = $this->figures->stockValue();
        $reports = app(ReportService::class);

        $this->assertSame(['GEN', 'DOC'], array_column($value['locations'], 'code'));
        $this->assertSame([$reports->stockValueAtCost((int) $this->general->getKey()), $reports->stockValueAtCost((int) $this->doctors->getKey())], array_column($value['locations'], 'value'));
        $this->assertSame(array_sum(array_column($value['locations'], 'value')), $value['total']);
        $this->assertGreaterThan(0, $value['total']);
        // Panadol, the syrup and Amoxil are in stock at the counters; Quarantine's Amoxil does not count.
        $this->assertSame(3, $value['skus']);

        // The largest share in the middle of the ring.
        $largest = collect($value['locations'])->sortByDesc('value')->first();
        Livewire::test(StockValueDonut::class)->assertOk()->assertSee('Items in stock')->assertSeeInOrder([number_format($largest['share'], 0).'%', $largest['code']]);
    }

    public function test_stock_alerts_put_expired_and_out_of_stock_first_then_by_expiry(): void
    {
        $alerts = $this->figures->stockAlerts();

        $this->assertSame(4, $alerts['total']);
        $this->assertSame(
            [
                ['expired', 'Amoxil 250 mg Capsules', 'High'],
                ['out_of_stock', 'ORS Sachets', 'High'],
                ['expiring', 'Amoxil 250 mg Capsules', 'High'],
                ['expiring', 'Amoxil 250 mg Capsules', 'Medium'],
            ],
            array_map(fn (array $alert): array => [$alert['kind'], $alert['item'], $alert['priority']], $alerts['alerts']),
        );
        $this->assertSame('Batch X1 · expired 01/10/2026', $alerts['alerts'][0]['detail']);
        $this->assertSame('Batch E5 · expires in 5 days', $alerts['alerts'][2]['detail']);
        $this->assertSame('General Pharmacy', $alerts['alerts'][3]['location']);

        Livewire::test(StockAlerts::class)->assertOk()->assertSeeInOrder(['Stock alerts', 'Amoxil 250 mg Capsules', 'High', 'ORS Sachets', 'Out of stock', 'Medium']);
    }

    public function test_todays_opd_bills_list_the_fee_the_medicines_and_completed_or_voided(): void
    {
        $this->assertSame(150_000, (int) TodaysOpdBills::query()->whereKey($this->opdBill->getKey())->value('consultation_fee'));

        Livewire::test(TodaysOpdBills::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$this->voidedBill, $this->opdBill], inOrder: true)
            ->assertCanNotSeeTableRecords([$this->genSale])
            ->assertTableColumnStateSet('consultation_fee', 150_000, $this->opdBill)
            ->assertTableColumnStateSet('opd_medicines_amount', 80_000, $this->opdBill)
            ->assertTableColumnFormattedStateSet('reported_at', '09:30', $this->opdBill)
            ->assertTableColumnFormattedStateSet('status', 'Completed', $this->opdBill)
            ->assertTableColumnFormattedStateSet('status', 'Voided', $this->voidedBill)
            ->assertTableColumnFormattedStateSet('consultation_fee', 'LKR 1,500.00', $this->opdBill);
    }

    public function test_the_activity_is_the_last_six_events_newest_first_with_shop_times(): void
    {
        $activity = $this->figures->activity();

        $this->assertSame(
            [
                ['void', 'Voided '.$this->voidedBill->invoice_no, '09:50'],
                ['sale', 'OPD bill '.$this->voidedBill->invoice_no, '09:45'],
                ['sale', 'OPD bill '.$this->opdBill->invoice_no, '09:30'],
                ['sale', 'Sale '.$this->genSale->invoice_no, '09:00'],
                ['transfer', 'Transfer TR-000001', '08:30'],
                ['received', 'Stock received GRN-000001', '08:00'],
            ],
            array_map(fn (array $event): array => [$event['kind'], $event['title'], $event['time']], $activity),
        );
        $this->assertSame('General Pharmacy → Doctor\'s Medicines', $activity[4]['detail']);
        $this->assertSame('Wrong patient', $activity[0]['detail']);

        Livewire::test(ActivityTimeline::class)->assertOk()->assertSeeInOrder(['Voided', '09:50', 'Stock received GRN-000001', '08:00']);
    }

    public function test_insights_are_plain_rules_most_urgent_first(): void
    {
        // Labelled plainly: rules, not "AI".
        $insights = Livewire::test(TodaysInsights::class)->assertOk()->assertSee("Today's insights", escape: false)->assertDontSeeText('AI insights')->instance()->insights();
        $value = ClinicDashboardService::EXPIRY_DAYS;

        $this->assertSame([
            ['tone' => 'danger', 'text' => '1 batch past expiry still at a selling counter: move it to Quarantine.'],
            ['tone' => 'warning', 'text' => "2 batches expire within {$value} days (".$this->shop->formatMoney($this->figures->expiringValue()).' at cost).'],
            ['tone' => 'warning', 'text' => '1 item at or below the reorder level.'],
            ['tone' => 'primary', 'text' => 'Sales are 2,300.0% up on last Sunday so far.'],
        ], $insights);
        $this->assertSame(20_000, $this->figures->expiringValue()); // 10 + 10 capsules at 1,000
    }

    public function test_the_greeting_is_by_the_shops_clock_with_todays_summary(): void
    {
        Livewire::test(GreetingHero::class)
            ->assertOk()
            ->assertSee('Good morning')
            ->assertSee("Here's how the clinic stands, Clinic.", escape: false)
            ->assertSee('2 sales today · 1 OPD bill · 1 item low on stock')
            ->assertSee('Open POS')
            ->assertSee('Expiring stock');
    }

    public function test_the_figures_are_kept_for_a_minute_per_shop_day(): void
    {
        $before = $this->figures->today()['sales'];

        $this->sell([new CartLine($this->tabs->getKey(), 1)]);
        $this->assertSame($before, $this->figures->today()['sales']);

        $this->travel(61)->seconds();
        $this->assertSame($before + 2_000, $this->figures->today()['sales']);
    }
}
