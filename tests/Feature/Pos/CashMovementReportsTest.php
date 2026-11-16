<?php

namespace Tests\Feature\Pos;

use App\Enums\CashMovementCategory;
use App\Enums\CashMovementType;
use App\Filament\Pages\Reports\CashMovementsReport;
use App\Filament\Pages\Reports\DoctorPaymentsReport;
use App\Filament\Pages\Reports\ShiftHistoryReport;
use App\Filament\Resources\Doctors\Pages\ViewDoctor;
use App\Filament\Resources\Doctors\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Doctors\Widgets\DoctorPaymentStats;
use App\Models\Device;
use App\Models\Doctor;
use App\Models\Shift;
use App\Models\User;
use App\Services\CashMovementService;
use App\Services\Data\NoReceiptLine;
use App\Services\NoReceiptReturnService;
use App\Services\ReportService;
use App\Services\ShiftService;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithReceipts;

/**
 * The reports of branch 18 (docs/PHARMACY.md): Cash movements & returns
 * without receipt, and Doctor payments, on the shop's days, with their
 * filters, totals and exports; a doctor's page (history, period filter,
 * cards); Shift history's Doctor payments column and its link; who may see
 * them.
 */
class CashMovementReportsTest extends BatchTestCase
{
    use WorksWithCheckout, WorksWithReceipts;

    private Doctor $perera;

    private Doctor $silva;

    private Device $opd;

    private User $docCashier;

    private Shift $genShift;

    private Shift $opdShift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stockTheClinic();
        $this->perera = Doctor::query()->create(['name' => 'Dr. Perera']);
        $this->silva = Doctor::query()->create(['name' => 'Dr. Silva']);
        $this->opd = $this->docTill();
        $this->docCashier = $this->cashierAt($this->doctors);
        $this->aWeek();
    }

    /**
     * Last month, 30/09 at 18:00 shop time: Dr. Silva 1,000.00 from T2.
     * Yesterday at 23:50 shop time (17/10): Dr. Perera 2,000.00 from T2.
     * Today (18/10, 10:00): T1 paid Dr. Perera 5,000.00, paid out 10.00
     * (refund difference), paid in 1,000.00, a paid out of 250.00 voided, and
     * refunded a return without a receipt of 20.00; T2 paid Dr. Silva
     * 3,000.00.
     */
    private function aWeek(): void
    {
        $movements = app(CashMovementService::class);
        $shifts = app(ShiftService::class);

        $this->travelTo(CarbonImmutable::parse('2026-09-30 12:30:00', 'UTC'));
        $old = $shifts->open($this->opd, $this->docCashier, 0);
        $movements->create($this->opd, $this->docCashier, CashMovementType::DoctorPayment, 100_000, doctor: $this->silva);
        $shifts->close($old, $this->docCashier, 0);

        $this->travelTo(CarbonImmutable::parse('2026-10-17 18:20:00', 'UTC'));
        $this->opdShift = $shifts->open($this->opd, $this->docCashier, 1_000_000);
        $movements->create($this->opd, $this->docCashier, CashMovementType::DoctorPayment, 200_000, doctor: $this->perera, note: 'Night clinic');

        $this->travelTo(CarbonImmutable::parse('2026-10-18 04:30:00', 'UTC'));
        $this->genShift = $shifts->open($this->till, $this->owner, 1_000_000);
        $movements->create($this->till, $this->owner, CashMovementType::DoctorPayment, 500_000, doctor: $this->perera, note: 'Morning session');
        $movements->create($this->till, $this->owner, CashMovementType::PaidOut, 1_000, CashMovementCategory::RefundDifference);
        $movements->create($this->till, $this->owner, CashMovementType::PaidIn, 100_000, CashMovementCategory::FloatTopUp);
        $voided = $movements->create($this->till, $this->owner, CashMovementType::PaidOut, 25_000, CashMovementCategory::Expense);
        $movements->void($voided, $this->owner, 'Not paid');
        app(NoReceiptReturnService::class)->create($this->till, $this->owner, [new NoReceiptLine($this->panadol->getKey(), 1)], 'Pre-system');
        $movements->create($this->opd, $this->docCashier, CashMovementType::DoctorPayment, 300_000, doctor: $this->silva);
    }

    private function today(): ReportPeriod
    {
        return ReportPeriod::today($this->shop->fresh());
    }

    public function test_doctor_payments_by_day_doctor_till_location_and_payer(): void
    {
        $reports = app(ReportService::class);

        $today = $reports->doctorPaymentRows($this->today());
        $this->assertSame(['Dr. Silva', 'Dr. Perera'], array_column($today, 'doctor'));
        $this->assertSame([300_000, 500_000], array_column($today, 'amount'));
        $this->assertSame(['T2', 'T1'], array_column($today, 'till'));
        $this->assertSame("Doctor's Medicines", $today[0]['location']);
        $this->assertSame('2026-10-18 10:00', $today[1]['when']);
        $this->assertSame('Morning session', $today[1]['note']);

        // Yesterday's 23:50 payment is yesterday's, by the shop's day.
        $yesterday = $reports->doctorPaymentRows(ReportPeriod::yesterday($this->shop->fresh()));
        $this->assertSame([['2026-10-17 23:50', 200_000]], array_map(fn (array $row): array => [$row['when'], $row['amount']], $yesterday));

        $week = ReportPeriod::between($this->shop->fresh(), '2026-10-12', '2026-10-18');
        $this->assertCount(3, $reports->doctorPaymentRows($week));
        $this->assertSame([500_000, 200_000], array_column($reports->doctorPaymentRows($week, filters: ['doctor_id' => $this->perera->getKey()]), 'amount'));
        $this->assertCount(1, $reports->doctorPaymentRows($week, (int) $this->general->getKey()));
        $this->assertCount(2, $reports->doctorPaymentRows($week, filters: ['device_id' => $this->opd->getKey()]));
        $this->assertCount(1, $reports->doctorPaymentRows($week, filters: ['user_id' => $this->owner->getKey()]));

        // Totals per doctor, most paid first.
        $this->assertSame([['Dr. Perera', 2, 700_000], ['Dr. Silva', 1, 300_000]], array_map(
            fn (array $row): array => [$row['doctor'], $row['payments'], $row['amount']],
            $reports->doctorPaymentsByDoctor($week),
        ));
    }

    public function test_the_doctor_payments_report_with_its_totals_and_exports(): void
    {
        $this->actingAs($this->owner);

        $page = Livewire::withQueryParams(['range' => 'this_month'])->test(DoctorPaymentsReport::class)
            ->assertSee('Dr. Perera LKR 7,000.00 (2) · Dr. Silva LKR 3,000.00 (1) · Total LKR 10,000.00')
            ->assertSee('Morning session')
            ->assertSee(ViewDoctor::getUrl(['record' => $this->perera]), false);
        $this->assertSame(1_000_000, $page->instance()->document()->tables[0]->total['amount']);

        $sheets = $this->sheets($this->download(Livewire::withQueryParams(['range' => 'this_month'])->test(DoctorPaymentsReport::class)->callTableAction('exportXlsx')));
        $this->assertCount(2, $sheets);
        $totals = end($sheets);
        $this->assertContains(['Dr. Perera', 2.0, 7_000.0], array_map(fn (array $row): array => array_slice($row, 0, 3), $totals));
        $this->assertContains(['Total', 3.0, 10_000.0], array_map(fn (array $row): array => array_slice($row, 0, 3), $totals));

        $this->assertStringStartsWith('%PDF-', $this->download(Livewire::test(DoctorPaymentsReport::class)->callTableAction('exportPdf')));
        $csv = Livewire::withQueryParams(['range' => 'today', 'doctor' => (string) $this->silva->getKey()])->test(DoctorPaymentsReport::class)->callTableAction('export');
        $this->assertNotNull($csv);

        // One shift, whatever the period (Shift history's link).
        Livewire::withQueryParams(['range' => 'today', 'shift' => $this->opdShift->uuid])->test(DoctorPaymentsReport::class)
            ->assertSee('Night clinic')
            ->assertSee('Dr. Silva LKR 3,000.00 (1)')
            ->assertDontSee('Morning session');
    }

    public function test_the_cash_movements_report_lists_every_kind_with_its_effect_on_the_drawer(): void
    {
        $rows = app(ReportService::class)->cashMovementRows($this->today());

        // Newest first (here all in one second: by number within each kind).
        $this->assertEqualsCanonicalizing(
            ['Doctor payment', 'Return without receipt', 'Paid out', 'Paid in', 'Paid out', 'Doctor payment'],
            array_column($rows, 'type'),
        );
        $this->assertSame(['CM-000007', 'CM-000006', 'CM-000005', 'CM-000004', 'CM-000003'], array_values(array_filter(array_column($rows, 'number'), fn (string $number): bool => str_starts_with($number, 'CM-'))));
        $byNumber = collect($rows)->keyBy('number');
        $this->assertSame([-1_000, 100_000, 0, -500_000, -2_000], [
            $byNumber['CM-000004']['drawer'], $byNumber['CM-000005']['drawer'], $byNumber['CM-000006']['drawer'], $byNumber['CM-000003']['drawer'], $byNumber['NR-000001']['drawer'],
        ]);
        $this->assertSame('Voided by '.$this->owner->name.': Not paid', $byNumber['CM-000006']['status']);
        $this->assertSame('Refund difference', $byNumber['CM-000004']['category']);
        $this->assertSame('Dr. Perera', $byNumber['CM-000003']['category']);
        $this->assertSame('Pre-system', $byNumber['NR-000001']['category']);
        $this->assertStringContainsString('Panadol Tablets → Quarantine / Expired', (string) $byNumber['NR-000001']['items']);

        $reports = app(ReportService::class);
        $this->assertSame(['NR-000001'], array_column($reports->cashMovementRows($this->today(), filters: ['type' => 'no_receipt']), 'number'));
        $this->assertCount(2, $reports->cashMovementRows($this->today(), filters: ['type' => 'paid_out']));
        $this->assertCount(1, $reports->cashMovementRows($this->today(), filters: ['device_id' => $this->opd->getKey()]));
        $this->assertCount(5, $reports->cashMovementRows($this->today(), (int) $this->general->getKey()));
        $this->assertCount(1, $reports->cashMovementRows($this->today(), filters: ['user_id' => $this->docCashier->getKey()]));

        $this->actingAs($this->owner);
        $page = Livewire::withQueryParams(['range' => 'today'])->test(CashMovementsReport::class)
            ->assertSee('Return without receipt')
            ->assertSee('Not paid');
        // Drawer total: -3,000 - 20 - 10 + 1,000 - 5,000 = -7,030.00.
        $this->assertSame(-703_000, $page->instance()->document()->tables[0]->total['drawer']);

        $this->assertCount(1, $this->sheets($this->download(Livewire::withQueryParams(['range' => 'today'])->test(CashMovementsReport::class)->callTableAction('exportXlsx'))));
        $this->get(route('reports.print', ['report' => CashMovementsReport::getSlug(), 'range' => 'today', 'type' => 'no_receipt']))
            ->assertOk()
            ->assertSee('NR-000001')
            ->assertDontSee('CM-000003');
    }

    public function test_the_doctors_page_has_the_history_period_totals_and_cards(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(DoctorPaymentStats::class, ['record' => $this->perera])
            ->assertSee('This month')
            ->assertSee('LKR 7,000.00')
            ->assertSee('Last month')
            ->assertSee('LKR 0.00')
            ->assertSee('18/10/2026 10:00 · T1')
            ->assertSee('All time');
        Livewire::test(DoctorPaymentStats::class, ['record' => $this->silva])
            ->assertSee('LKR 1,000.00')       // last month
            ->assertSee('LKR 4,000.00');      // all time

        Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $this->perera, 'pageClass' => ViewDoctor::class])
            ->assertCanSeeTableRecords($this->perera->payments()->get())
            ->filterTable('period', ['range' => 'today'])
            ->assertSee('Morning session')
            ->assertDontSee('Night clinic')
            ->assertSee('LKR 5,000.00');

        $this->get(ViewDoctor::getUrl(['record' => $this->perera]))->assertOk()->assertSee('Dr. Perera');
    }

    public function test_shift_history_has_the_doctor_payments_column_linked_to_the_shift(): void
    {
        $this->actingAs($this->owner);

        $page = Livewire::withQueryParams(['range' => 'this_month'])->test(ShiftHistoryReport::class)
            ->assertSee('Doctor payments')
            ->assertSee(DoctorPaymentsReport::getUrl(['shift' => $this->genShift->uuid]), false);

        $rows = collect($page->instance()->document()->tables[0]->rows)->keyBy('shift_id');
        $this->assertSame(500_000, $rows[$this->genShift->getKey()]['doctor_payments']);
        $this->assertSame(500_000, $rows[$this->opdShift->getKey()]['doctor_payments']);
    }

    public function test_only_users_with_reports_view_see_the_reports(): void
    {
        $this->actingAs($this->cashier)->get(DoctorPaymentsReport::getUrl())->assertForbidden();
        $this->actingAs($this->cashier)->get(CashMovementsReport::getUrl())->assertForbidden();
        $this->actingAs($this->owner)->get(DoctorPaymentsReport::getUrl())->assertOk();
        $this->actingAs($this->owner)->get(CashMovementsReport::getUrl())->assertOk();
    }

    private function download(Testable $component): string
    {
        return base64_decode((string) data_get($component->assertFileDownloaded()->effects, 'download.content'));
    }

    /** @return array<string, list<list<mixed>>> */
    private function sheets(string $xlsx): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $xlsx);
        $reader = new Reader;
        $reader->open($path);
        $sheets = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = array_map(fn (mixed $value): mixed => is_int($value) ? (float) $value : $value, $row->toArray());
            }
        }

        $reader->close();
        unlink($path);

        return $sheets;
    }
}
