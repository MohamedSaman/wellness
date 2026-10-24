<?php

namespace Tests\Feature\Pos;

use App\Enums\AdjustmentReason;
use App\Enums\BillType;
use App\Enums\DrugSchedule;
use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Reports\BatchValuationReport;
use App\Filament\Pages\Reports\ControlledDrugRegisterReport;
use App\Filament\Pages\Reports\DailySummaryReport;
use App\Filament\Pages\Reports\ExpiryReport;
use App\Filament\Pages\Reports\MonthlySalesReport;
use App\Filament\Pages\Reports\ProfitReport;
use App\Filament\Pages\Reports\PurchasesReport;
use App\Filament\Pages\Reports\ReportPage;
use App\Filament\Pages\Reports\ReportRegistry;
use App\Filament\Pages\Reports\SalesByDayReport;
use App\Filament\Pages\Reports\StockValuationReport;
use App\Filament\Pages\Reports\SummaryReportPage;
use App\Filament\Pages\Reports\SupplierAgingReport;
use App\Filament\Widgets\BestSellersTable;
use App\Filament\Widgets\CounterSalesStats;
use App\Models\Device;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use App\Services\Data\ReceiptLine;
use App\Services\Data\TransferLine;
use App\Services\ReportService;
use App\Services\ReturnService;
use App\Services\ShiftService;
use App\Services\StockService;
use App\Services\StockTransferService;
use App\Support\ReportPeriod;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithPurchasing;

/**
 * The report pages on the demo clinic (docs/PHARMACY.md §9, branch 11):
 * every report opens and exports to Excel, PDF and its print page with the
 * figures on screen; date presets; who may see what (reports.view, the
 * owner; reports.profit for profit; a cashier none of it); the owner's
 * dashboard. The clock is at 2026-10-18 10:00 in Colombo.
 */
class AdminPharmacyReportsTest extends BatchTestCase
{
    use WorksWithCheckout;
    use WorksWithPurchasing;

    private ProductVariant $panadol;

    private Device $doc;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->setShopSetting('controlled_register', true);

        $this->panadol = $this->medicine();
        $this->doc = $this->docTill();
        $this->doctor = $this->cashierAt($this->doctors);
        $this->receive($this->panadol, 'P1', '03/2027', 100);
        $this->receive($this->panadol, 'D1', '11/2026', 50, $this->doctors);
    }

    /** @return array<string, array{0: class-string<ReportPage>|class-string<SummaryReportPage>}> */
    public static function reports(): array
    {
        return collect(ReportRegistry::PAGES)->mapWithKeys(fn (string $page): array => [class_basename($page) => [$page]])->all();
    }

    /**
     * Bills at both counters, an OPD bill, a refund, a void, a delivery from
     * a supplier, a transfer, an adjustment, a controlled medicine sold.
     */
    private function aBusyDay(): void
    {
        $checkout = app(CheckoutService::class);
        $consultation = $this->consultation(150_000);
        $morphine = $this->medicine('Morphine 10 mg Tablets', packs: [], schedule: DrugSchedule::Controlled);
        $this->receive($morphine, 'M1', '12/2027', 30);
        app(ShiftService::class)->open($this->till, $this->cashier, 100_000);

        $cash = $checkout->checkout(new CheckoutData(device: $this->till, cashier: $this->cashier, lines: [new CartLine($this->panadol->getKey(), 5)], payments: [new PaymentInput(PaymentMethod::Cash, 10_000)]));
        $checkout->checkout(new CheckoutData(device: $this->till, cashier: $this->cashier, lines: [new CartLine($morphine->getKey(), 2, prescriber: 'Dr. S. Perera')], payments: [new PaymentInput(PaymentMethod::Card, 4_000)], customerName: 'A. Silva'));
        $voided = $checkout->checkout(new CheckoutData(device: $this->till, cashier: $this->cashier, lines: [new CartLine($this->panadol->getKey(), 1)], payments: [new PaymentInput(PaymentMethod::Cash, 2_000)]));
        $checkout->void($voided, $this->owner, 'Wrong item');
        $checkout->checkout(new CheckoutData(
            device: $this->doc, cashier: $this->doctor,
            lines: [new CartLine($consultation->getKey(), 1), new CartLine($this->panadol->getKey(), 6)],
            payments: [new PaymentInput(PaymentMethod::BankTransfer, 165_000, 'BOC-9')],
            billType: BillType::Opd, opdMedicinesAmount: 15_000,
        ));
        app(ReturnService::class)->process($cash, [$cash->items[0]->getKey() => 2], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);

        $this->postReceipt($this->supplier(), [new ReceiptLine($this->panadol->getKey(), 2, $this->boxOf($this->panadol), freeQuantity: 1, unitCost: 9_000, batchNo: 'H1', expiry: '06/2027')], invoiceNo: 'HPL-1');
        app(StockTransferService::class)->create($this->general, $this->doctors, [new TransferLine($this->panadol->getKey(), 10)], $this->owner);
        app(StockService::class)->adjustBatchBy($this->panadol, $this->general, $this->batch($this->panadol, 'P1'), -1, AdjustmentReason::Damaged, $this->owner);
    }

    /** The file an export action downloaded, decoded. */
    private function downloaded(Testable $component): string
    {
        return base64_decode((string) data_get($component->effects, 'download.content'));
    }

    /** @param  class-string  $report */
    private function export(string $report, string $action, array $query = []): string
    {
        $component = Livewire::withQueryParams($query)->test($report);
        $component = is_subclass_of($report, SummaryReportPage::class) ? $component->callAction($action) : $component->callTableAction($action);

        return $this->downloaded($component->assertFileDownloaded());
    }

    /**
     * Each sheet of a workbook as rows of cell values.
     *
     * @return array<string, list<list<mixed>>>
     */
    private function sheets(string $xlsx): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $xlsx);
        $reader = new Reader;
        $reader->open($path);
        $sheets = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                // Numbers as floats: Excel keeps 140.0 as 140.
                $sheets[$sheet->getName()][] = array_map(fn (mixed $value): mixed => is_int($value) ? (float) $value : $value, $row->toArray());
            }
        }

        $reader->close();
        unlink($path);

        return $sheets;
    }

    /**
     * The row of a sheet whose first cell is $first.
     *
     * @param  list<list<mixed>>  $rows
     * @return list<mixed>
     */
    private function rowStarting(array $rows, string $first): array
    {
        foreach ($rows as $row) {
            if (($row[0] ?? null) === $first) {
                return $row;
            }
        }

        $this->fail("No row starts with {$first}.");
    }

    /** @param  class-string<ReportPage>|class-string<SummaryReportPage>  $report */
    #[DataProvider('reports')]
    public function test_every_report_opens_and_exports_to_excel_pdf_and_print(string $report): void
    {
        $this->aBusyDay();
        $this->actingAs($this->owner);

        $this->get($report::getUrl())->assertSuccessful()->assertDontSee('SQLSTATE');

        $xlsx = $this->export($report, 'exportXlsx');
        $this->assertStringStartsWith('PK', $xlsx, 'An .xlsx file is a zip.');
        $this->assertNotEmpty($this->sheets($xlsx));

        $pdf = $this->export($report, 'exportPdf');
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('%%EOF', substr($pdf, -64));

        $this->get(route('reports.print', ['report' => $report::getSlug()]))
            ->assertSuccessful()
            ->assertSee($this->shop->name)
            ->assertSee('Amounts in '.$this->shop->currency);
    }

    public function test_the_daily_summary_exports_the_reconciled_figures_in_every_format(): void
    {
        $this->aBusyDay();
        $this->actingAs($this->owner);
        $counters = collect(app(ReportService::class)->counters(ReportPeriod::today($this->shop->fresh())))->keyBy('location');
        $net = $counters->sum('net');
        $this->assertSame(10_000 + 4_000 + 165_000 - 4_000, $net);

        Livewire::test(DailySummaryReport::class)
            ->assertSet('range', 'today')
            ->assertSet('from', '2026-10-18')
            ->assertSeeHtml('data-test="summary-report"')
            ->assertSee('General Pharmacy')
            ->assertSee("Doctor's Medicines")
            ->assertSee('Consultation fees')
            ->assertSee(number_format($net / 100, 2));

        $sheets = $this->sheets($this->export(DailySummaryReport::class, 'exportXlsx'));
        $this->assertSame(['By counter', 'Payments', 'Consultation fees and medici', 'By cashier', 'Cash expected and counted'], array_keys($sheets));

        // Bills, sales, refunds, net, consultation fees, medicines: as numbers in rupees.
        $total = $this->rowStarting($sheets['By counter'], 'Total');
        $this->assertSame([3.0, 1_790.0, 40.0, 1_750.0, 1_500.0, 250.0], array_slice($total, 1, 6));
        $this->assertSame(1_750.0, $this->rowStarting($sheets['Payments'], 'Total')[4]);
        $this->assertSame(1_750.0, $this->rowStarting($sheets['Consultation fees and medici'], 'Total')[3]);
        $this->assertSame(1_750.0, $this->rowStarting($sheets['By cashier'], 'Total')[4]);
        $this->assertSame(['Period', '18/10/2026 (Today)'], array_slice($this->rowStarting($sheets['By counter'], 'Period'), 0, 2));

        // The print page and the PDF are the same document.
        $this->get(route('reports.print', ['report' => DailySummaryReport::getSlug(), 'range' => 'today', 'print' => 1]))
            ->assertSuccessful()
            ->assertSee('Daily summary')
            ->assertSee('1,750.00')
            ->assertSee('window.print()', false);
        $this->assertStringStartsWith('%PDF-', $this->export(DailySummaryReport::class, 'exportPdf'));

        // One counter.
        $this->get(route('reports.print', ['report' => DailySummaryReport::getSlug(), 'range' => 'today', 'location' => (string) $this->doctors->getKey()]))
            ->assertSee('1,650.00')
            ->assertDontSee('General Pharmacy');
    }

    public function test_a_table_report_exports_its_on_screen_columns_and_total_to_excel(): void
    {
        $this->aBusyDay();
        $this->actingAs($this->owner);

        $sheets = $this->sheets($this->export(SalesByDayReport::class, 'exportXlsx', ['range' => 'this_month', 'location' => (string) $this->general->getKey()]));
        $rows = reset($sheets);
        $this->assertSame(['Date', 'Sales', 'Sales total', 'Discounts', 'Tax', 'Refunds', 'Net sales'], $this->rowStarting($rows, 'Date'));
        $this->assertSame([2.0, 140.0, 0.0, 0.0, 40.0, 100.0], array_slice($this->rowStarting($rows, 'Total'), 1));
        $this->assertSame(['Location', 'General Pharmacy'], array_slice($this->rowStarting($rows, 'Location'), 0, 2));

        // The print page shows the columns the screen showed.
        $this->get(route('reports.print', ['report' => SalesByDayReport::getSlug(), 'range' => 'this_month', 'columns' => 'date,net']))
            ->assertSee('Net sales')
            ->assertDontSee('Discounts');
    }

    public function test_the_profit_report_shows_medicines_and_consultation_fees_apart(): void
    {
        $this->aBusyDay();
        $this->actingAs($this->owner);
        $profit = app(ReportService::class)->profit(ReportPeriod::thisMonth($this->shop->fresh()));

        $sheets = $this->sheets($this->export(ProfitReport::class, 'exportXlsx'));
        $summary = $sheets['Summary'];
        $this->assertSame([(float) $profit['goods']['revenue'] / 100, (float) $profit['goods']['cost'] / 100], array_slice($this->rowStarting($summary, 'Medicines'), 2, 2));
        // The consultation carries no cost: its fee is its profit.
        $this->assertSame([1_500.0, 0.0, 1_500.0], array_slice($this->rowStarting($summary, 'Consultation fees'), 2, 3));
        $this->assertSame((float) $profit['total']['profit'] / 100, $this->rowStarting($summary, 'Total')[4]);
        $this->assertSame($profit['total']['margin'], $this->rowStarting($summary, 'Total')[5]);
        $this->assertArrayHasKey('Medicines by location', $sheets);
        $this->assertArrayHasKey('By medicine', $sheets);
    }

    public function test_date_presets_set_the_shops_days(): void
    {
        $this->actingAs($this->owner);

        $cases = [
            'today' => ['2026-10-18', '2026-10-18'],
            'yesterday' => ['2026-10-17', '2026-10-17'],
            'this_week' => ['2026-10-12', '2026-10-18'],
            'this_month' => ['2026-10-01', '2026-10-18'],
            'last_month' => ['2026-09-01', '2026-09-30'],
        ];

        foreach ($cases as $range => [$from, $to]) {
            Livewire::withQueryParams(['range' => $range])->test(MonthlySalesReport::class)->assertSet('from', $from)->assertSet('to', $to);
        }

        Livewire::withQueryParams([])->test(MonthlySalesReport::class)
            ->assertSet('range', 'this_month')
            ->set('range', 'last_month')
            ->assertSet('from', '2026-09-01')
            ->assertSet('to', '2026-09-30')
            ->set('from', '2026-09-05')
            ->assertSet('range', 'custom');

        // Older reports open on the last 30 days, as before.
        Livewire::withQueryParams([])->test(SalesByDayReport::class)->assertSet('from', '2026-09-19')->assertSet('to', '2026-10-18')->assertSet('range', 'custom');
    }

    public function test_cashiers_have_no_reports_exports_or_print_pages(): void
    {
        $this->actingAs($this->cashier);

        foreach (ReportRegistry::PAGES as $report) {
            $this->assertFalse($report::canAccess(), $report);
            $this->get(route('reports.print', ['report' => $report::getSlug()]))->assertForbidden();
        }

        $this->get(DailySummaryReport::getUrl())->assertForbidden();
        $this->get(route('reports.print', ['report' => 'no-such-report']))->assertNotFound();
    }

    public function test_reports_view_opens_reports_and_exports_but_profit_needs_its_own_permission(): void
    {
        $this->aBusyDay();
        $this->cashier->givePermissionTo(['admin.access', 'reports.view']);
        $this->actingAs($this->cashier);

        $this->get(DailySummaryReport::getUrl())->assertSuccessful();
        $this->assertStringStartsWith('PK', $this->export(DailySummaryReport::class, 'exportXlsx'));
        $this->get(route('reports.print', ['report' => DailySummaryReport::getSlug()]))->assertSuccessful();

        $this->get(ProfitReport::getUrl())->assertForbidden();
        $this->get(route('reports.print', ['report' => ProfitReport::getSlug()]))->assertForbidden();

        // Without cost.view, values at cost are left out on screen and in every export.
        Livewire::test(BatchValuationReport::class)->assertTableColumnExists('quantity')->assertTableColumnDoesNotExist('value');
        $this->get(route('reports.print', ['report' => BatchValuationReport::getSlug()]))->assertDontSee('Value at batch cost');
        $this->get(route('reports.print', ['report' => StockValuationReport::getSlug(), 'by' => 'location']))->assertSee('Value at selling price')->assertDontSee('Value at average cost');

        // Supplier aging also needs the payables permission.
        $this->get(SupplierAgingReport::getUrl())->assertForbidden();
        $this->cashier->givePermissionTo('payables.view');
        $this->get(SupplierAgingReport::getUrl())->assertSuccessful();
    }

    public function test_the_controlled_drug_register_is_only_there_while_the_register_is_on(): void
    {
        $this->aBusyDay();
        $this->actingAs($this->owner);

        Livewire::test(ControlledDrugRegisterReport::class)->assertSee('A. Silva')->assertSee('Dr. S. Perera');

        $this->setShopSetting('controlled_register', false);
        $this->assertFalse(ControlledDrugRegisterReport::canAccess());
        $this->get(ControlledDrugRegisterReport::getUrl())->assertForbidden();
    }

    public function test_the_expiry_and_purchases_reports_take_their_filters(): void
    {
        $this->aBusyDay();
        $this->actingAs($this->owner);

        // D1 at Doctor's Medicines expires on 30/11/2026: 43 days.
        Livewire::withQueryParams(['days' => '30'])->test(ExpiryReport::class)->assertDontSee('D1');
        Livewire::withQueryParams(['days' => '60'])->test(ExpiryReport::class)->assertSee('D1')->assertSee('43');

        Livewire::test(PurchasesReport::class)->assertSee('Hemas Pharmaceuticals')->assertSee('HPL-1')->assertSee('180.00'); // 2 boxes at 90.00
        $sheets = $this->sheets($this->export(PurchasesReport::class, 'exportXlsx'));
        $this->assertSame(90.0, $this->rowStarting($sheets['By supplier'], 'Total')[7]); // a free box at 90.00
    }

    public function test_the_owners_dashboard_shows_each_counter_consultations_and_the_month(): void
    {
        $this->aBusyDay();
        $this->actingAs($this->owner);

        $this->get(Dashboard::getUrl())->assertOk()->assertSeeLivewire(CounterSalesStats::class);

        Livewire::test(CounterSalesStats::class)
            ->assertSee('General Pharmacy today')
            ->assertSee("Doctor's Medicines today")
            ->assertSee($this->shop->formatMoney(10_000 + 4_000 - 4_000))
            ->assertSee($this->shop->formatMoney(165_000))
            ->assertSee('Consultations today')
            ->assertSee('This month')
            ->assertSee('Last month');

        Livewire::test(BestSellersTable::class)->assertSee('Top 10 medicines this month')->assertSee('Panadol 500 mg Tablets');

        // The cashier has no dashboard figures.
        $this->actingAs($this->cashier);
        $this->assertFalse(CounterSalesStats::canView());
    }
}
