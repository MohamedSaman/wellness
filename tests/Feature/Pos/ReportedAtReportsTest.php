<?php

namespace Tests\Feature\Pos;

use App\Enums\BillType;
use App\Enums\PaymentMethod;
use App\Filament\Widgets\NetSalesChart;
use App\Filament\Widgets\TodayStats;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use App\Services\OfflineSaleSyncService;
use App\Services\ReceiptService;
use App\Services\ReportService;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * Reports date a sale by sales.reported_at (docs/PHARMACY.md §7.5): the sale
 * time, or the server's time when an offline till's clock was out. The
 * receipt keeps what the till printed (sold_at). The clock is fixed at
 * 2026-10-18 10:00 in Colombo (BatchTestCase).
 */
class ReportedAtReportsTest extends BatchTestCase
{
    use WorksWithCheckout;

    private ReportService $reports;

    private ProductVariant $panadol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reports = app(ReportService::class);
        $this->panadol = $this->medicine();
        $this->receive($this->panadol, 'P1', '03/2027', 50);
        $this->receive($this->panadol, 'D1', '03/2027', 50, $this->doctors);
    }

    private function day(string $from, ?string $to = null): ReportPeriod
    {
        return ReportPeriod::between($this->shop->fresh(), $from, $to ?? $from);
    }

    private function colombo(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time, 'Asia/Colombo');
    }

    /**
     * Two tablets sold offline at 00:20 on the 18th by the till's clock, which
     * was 30 minutes ahead: on the server's clock it was 23:50 on the 17th.
     */
    private function saleFromAFastTill(): Sale
    {
        $sale = $this->sell([new CartLine($this->panadol->getKey(), 2)], offline: true, soldAt: $this->colombo('2026-10-18 00:20')->utc());
        $sale->forceFill([
            'reported_at' => $this->colombo('2026-10-17 23:50')->utc(),
            'clock_offset_seconds' => 1_800,
        ])->save();

        return $sale;
    }

    /** @return array{datasets: list<array{data: list<float>}>, labels: list<string>} */
    private function chartData(NetSalesChart $chart): array
    {
        return (fn (): array => $this->getData())->call($chart);
    }

    public function test_a_sale_counts_on_the_day_of_its_reported_time_in_every_report(): void
    {
        $sale = $this->saleFromAFastTill();
        $this->sell([new CartLine($this->panadol->getKey(), 1)]); // 10:00 on the 18th, online

        $seventeenth = $this->reports->summary($this->day('2026-10-17'));
        $this->assertSame(1, $seventeenth['sales_count']);
        $this->assertSame(4_000, $seventeenth['gross']);
        $eighteenth = $this->reports->summary($this->day('2026-10-18'));
        $this->assertSame(1, $eighteenth['sales_count']);
        $this->assertSame(2_000, $eighteenth['gross']);

        $days = collect($this->reports->salesByDay($this->day('2026-10-17', '2026-10-18')))->keyBy('date');
        $this->assertSame([1, 4_000], [$days['2026-10-17']['sales_count'], $days['2026-10-17']['net']]);
        $this->assertSame([1, 2_000], [$days['2026-10-18']['sales_count'], $days['2026-10-18']['net']]);

        $this->assertSame(1, $this->reports->salesByCashier($this->day('2026-10-17'))[0]['sales_count']);
        $this->assertSame(4_000, $this->reports->paymentMethods($this->day('2026-10-17'))[0]['net']);
        $this->assertSame(2.0, $this->reports->bestSellers($this->day('2026-10-17'))[0]['quantity']);
        $this->assertSame(4_000, $this->reports->salesByGroup($this->day('2026-10-17'), 'category')[0]['net']);
        $this->assertSame(4_000 - 2 * 1_000, $this->reports->grossProfit($this->day('2026-10-17')));
        $this->assertSame(2_000 - 1_000, $this->reports->grossProfit($this->day('2026-10-18')));

        // The receipt still prints the till's time.
        $this->assertSame('2026-10-18 00:20', app(ReceiptService::class)->sale($sale)['soldAt']);
    }

    public function test_the_dashboard_counts_a_sale_on_its_reported_day(): void
    {
        $this->saleFromAFastTill();
        $this->sell([new CartLine($this->panadol->getKey(), 1)]);

        $this->actingAs($this->owner);

        // Today is the 18th: only the online sale, not the one the fast till printed as the 18th.
        Livewire::test(TodayStats::class)
            ->assertSeeText($this->shop->formatMoney(2_000))
            ->assertDontSeeText($this->shop->formatMoney(6_000));

        $data = $this->chartData(Livewire::test(NetSalesChart::class)->instance());
        $this->assertSame(['17 Oct', '18 Oct'], array_slice($data['labels'], 28));
        $this->assertSame([40.0, 20.0], array_slice($data['datasets'][0]['data'], 28));
    }

    public function test_a_clock_corrected_offline_upload_is_reported_on_the_servers_day(): void
    {
        $sync = app(OfflineSaleSyncService::class);
        $sync->reserveInvoiceBlock($this->till);
        $uuid = (string) Str::uuid();

        // Uploaded at 10:00 server time by a till whose clock says 10:30.
        $sync->sync($this->till, [[
            'uuid' => $uuid,
            'invoice_no' => 'T1-000001',
            'sold_at' => $this->colombo('2026-10-18 00:10')->toIso8601String(),
            'cashier_id' => $this->cashier->getKey(),
            'lines' => [['variant_id' => $this->panadol->getKey(), 'quantity' => 2, 'unit_price' => 2_000]],
            'payments' => [['method' => 'cash', 'amount' => 4_000]],
            'grand_total' => 4_000,
        ]], now()->addMinutes(30)->toIso8601String());

        $sale = Sale::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame('2026-10-17 23:40', $sale->reported_at?->timezone('Asia/Colombo')->format('Y-m-d H:i'));

        $this->assertSame(4_000, $this->reports->summary($this->day('2026-10-17'))['gross']);
        $this->assertSame(0, $this->reports->summary($this->day('2026-10-18'))['sales_count']);
    }

    /**
     * Profit reads each line's line_total and unit_cost, whatever day the sale
     * is reported on: an OPD bill at Doctor's Medicines moved to the day
     * before keeps its revenue (the medicines at their shares) and its cost.
     */
    public function test_moving_a_sale_to_its_reported_day_leaves_its_profit_unchanged(): void
    {
        $consultation = $this->consultation();
        $this->sell([new CartLine($this->panadol->getKey(), 5)]); // General Pharmacy: 10,000 - 5,000
        $opd = app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->docTill(),
            cashier: $this->cashierAt($this->doctors),
            lines: [new CartLine($consultation->getKey(), 1), new CartLine($this->panadol->getKey(), 10)],
            payments: [new PaymentInput(PaymentMethod::Cash, 200_000)],
        ));
        // As an OPD bill stores it (contract §5.6): the tablets' share of a Medicines amount of 150.
        $opd->items[1]->forceFill(['list_price' => 2_000, 'is_opd_medicine' => true, 'line_total' => 15_000])->save();
        $opd->forceFill(['bill_type' => BillType::Opd, 'opd_medicines_amount' => 15_000])->save();

        $before = collect($this->reports->profitByProduct($this->day('2026-10-17', '2026-10-18')))->keyBy('product');

        $opd->forceFill(['reported_at' => $this->colombo('2026-10-17 23:00')->utc()])->save();
        $after = collect($this->reports->profitByProduct($this->day('2026-10-17', '2026-10-18')))->keyBy('product');

        $this->assertSame($before->all(), $after->all());
        $this->assertSame(10_000 + 15_000, $after['Panadol 500 mg Tablets']['revenue']);
        $this->assertSame(15 * 1_000, $after['Panadol 500 mg Tablets']['cost']);
        $this->assertSame(150_000, $after['Consultation']['revenue']);

        $this->assertSame(150_000 + 15_000 - 10_000, $this->reports->grossProfit($this->day('2026-10-17')));
        $this->assertSame(10_000 - 5_000, $this->reports->grossProfit($this->day('2026-10-18')));
    }
}
