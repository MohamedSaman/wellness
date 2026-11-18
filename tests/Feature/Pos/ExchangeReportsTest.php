<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Filament\Pages\Reports\DailySummaryReport;
use App\Filament\Pages\Reports\ExchangesReport;
use App\Filament\Pages\ShopSettings;
use App\Filament\Resources\Patients\Pages\ViewPatient;
use App\Filament\Resources\Patients\RelationManagers\ExchangesRelationManager;
use App\Filament\Resources\Patients\RelationManagers\SalesRelationManager;
use App\Models\Doctor;
use App\Models\Exchange;
use App\Models\Location;
use App\Models\Patient;
use App\Models\Sale;
use App\Models\Shift;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\ExchangeData;
use App\Services\Data\NoReceiptLine;
use App\Services\Data\PatientInput;
use App\Services\Data\PaymentInput;
use App\Services\Data\ReturnLine;
use App\Services\ExchangeService;
use App\Services\ReportService;
use App\Services\ShiftService;
use App\Services\ShiftSummaryService;
use App\Support\ReportPeriod;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithReceipts;

/**
 * Returns and exchanges at the till in the reports (docs/PHARMACY.md,
 * branch 19): Exchanges & returns (rows by shop day, every filter), the
 * payment methods (Exchange credit taken = Exchange credit refunded, cash by
 * the difference only), the shift summary's counts (thermal, A4, Close
 * shift), the daily summary's table, the patient's Exchanges and History,
 * and the time limit setting.
 *
 * The day: T1-000001, a box of Panadol (540.00) and 10 Amoxil (350.00) for
 * Nimal Perera, 890.00 cash; EX-000001 10 Amoxil (350.00, side effects) for
 * 10 Panadol tablets (200.00): 150.00 back; EX-000002 5 tablets (90.00,
 * wrong medicine) for 10 Amoxil (350.00): 260.00 paid, 300.00 cash; EX-000003
 * a plain return of 5 tablets (90.00, not effective) by the owner.
 */
class ExchangeReportsTest extends BatchTestCase
{
    use WorksWithCheckout, WorksWithReceipts;

    private Shift $shift;

    private Sale $sale;

    private Location $quarantine;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stockTheClinic();
        $this->shift = app(ShiftService::class)->open($this->till, $this->cashier, 500_000);
        $this->quarantine = Location::query()->where('code', 'QUAR')->firstOrFail();
        $this->doctor = Doctor::query()->create(['name' => 'Dr. S. Perera']);
        $this->sale = app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->till,
            cashier: $this->cashier,
            lines: [
                new CartLine($this->panadol->getKey(), 1, unitId: $this->unitNamed($this->panadol, 'Box of 30')),
                new CartLine($this->amoxil->getKey(), 10, prescriber: 'Dr. S. Perera'),
            ],
            payments: [new PaymentInput(PaymentMethod::Cash, 89_000)],
            patient: new PatientInput((string) Str::uuid(), 'Nimal Perera', 408),
        ));

        $box = (int) $this->sale->items->values()[0]->getKey();
        $amoxil = (int) $this->sale->items->values()[1]->getKey();
        $q = (int) $this->quarantine->getKey();
        $exchanges = app(ExchangeService::class);

        $exchanges->create(new ExchangeData($this->till, $this->cashier, (string) Str::uuid(), $this->sale, [new ReturnLine($amoxil, 10, toLocationId: $q)], [], ReturnReason::SideEffects, null, (int) $this->doctor->getKey(), [new CartLine($this->panadol->getKey(), 10)]));
        $exchanges->create(new ExchangeData($this->till, $this->cashier, (string) Str::uuid(), $this->sale, [new ReturnLine($box, 5, true, toLocationId: (int) $this->general->getKey())], [], ReturnReason::WrongMedicine, null, null, [new CartLine($this->amoxil->getKey(), 10, prescriber: 'Dr. S. Perera')], payments: [new PaymentInput(PaymentMethod::Cash, 30_000)]));
        $exchanges->create(new ExchangeData($this->till, $this->owner, (string) Str::uuid(), $this->sale, [new ReturnLine($box, 5, true, toLocationId: $q)], [], ReturnReason::NotEffective, null, null));
    }

    private function today(): ReportPeriod
    {
        return ReportPeriod::today($this->shop);
    }

    public function test_payment_methods_show_exchange_credit_taken_and_refunded_and_cash_by_the_difference(): void
    {
        $rows = collect(app(ReportService::class)->paymentMethods($this->today()))->keyBy('key');

        $this->assertSame(['cash', 'exchange'], $rows->keys()->all());
        $this->assertSame(['Exchange credit', 2, 29_000, 29_000, 0], [$rows['exchange']['method'], $rows['exchange']['payments'], $rows['exchange']['taken'], $rows['exchange']['refunds'], $rows['exchange']['net']]);
        // Cash: 890.00 + 300.00 − 40.00 change taken; 150.00 + 90.00 refunded.
        $this->assertSame([115_000, 24_000, 91_000], [$rows['cash']['taken'], $rows['cash']['refunds'], $rows['cash']['net']]);
        $this->assertSame(500_000 + 91_000, app(ShiftService::class)->expectedCash($this->shift));
    }

    public function test_the_exchanges_report_lists_each_one_with_every_filter(): void
    {
        $reports = app(ReportService::class);
        $rows = $reports->exchangeRows($this->today());

        $this->assertSame(['EX-000003', 'EX-000002', 'EX-000001'], array_column($rows, 'number'));
        $byNumber = collect($rows)->keyBy('number');
        $this->assertSame(['Exchange', 'T1-000001', 'T1-000002', 35_000, 20_000, 0, 15_000, 'Side effects', 'Dr. S. Perera', 'Nimal Perera'], [
            $byNumber['EX-000001']['kind'], $byNumber['EX-000001']['original'], $byNumber['EX-000001']['new_bill'], $byNumber['EX-000001']['credit'],
            $byNumber['EX-000001']['new_total'], $byNumber['EX-000001']['patient_paid'], $byNumber['EX-000001']['refunded'], $byNumber['EX-000001']['reason'],
            $byNumber['EX-000001']['doctor'], $byNumber['EX-000001']['patient'],
        ]);
        $this->assertStringContainsString('→ To Quarantine / Expired', (string) $byNumber['EX-000001']['returned']);
        $this->assertStringContainsString('Panadol Tablets', (string) $byNumber['EX-000001']['new_items']);
        $this->assertStringContainsString('→ Back on sale: General Pharmacy', (string) $byNumber['EX-000002']['returned']);
        $this->assertSame(['Return', null, 9_000], [$byNumber['EX-000003']['kind'], $byNumber['EX-000003']['new_bill'], $byNumber['EX-000003']['refunded']]);

        $this->assertSame(['EX-000002'], array_column($reports->exchangeRows($this->today(), filters: ['reason' => 'wrong_medicine']), 'number'));
        $this->assertSame(['EX-000003'], array_column($reports->exchangeRows($this->today(), filters: ['user_id' => $this->owner->getKey()]), 'number'));
        $this->assertCount(3, $reports->exchangeRows($this->today(), filters: ['patient_id' => $this->sale->patient_id, 'device_id' => $this->till->getKey()]));
        $this->assertCount(0, $reports->exchangeRows($this->today(), (int) $this->doctors->getKey()));
        $this->assertCount(0, $reports->exchangeRows(ReportPeriod::yesterday($this->shop)));

        $this->actingAs($this->owner);
        $page = Livewire::withQueryParams(['range' => 'today'])->test(ExchangesReport::class)
            ->assertSee('EX-000001')
            ->assertSee('Side effects');
        $total = $page->instance()->document()->tables[0]->total;
        $this->assertSame([35_000 + 9_000 + 9_000, 26_000, 24_000], [$total['credit'], $total['patient_paid'], $total['refunded']]);

        $this->get(route('reports.print', ['report' => ExchangesReport::getSlug(), 'range' => 'today', 'reason' => 'not_effective']))
            ->assertOk()
            ->assertSee('EX-000003')
            ->assertDontSee('EX-000001');
        $this->actingAs($this->cashier)->get(ExchangesReport::getUrl())->assertForbidden();
    }

    public function test_the_shift_summaries_count_exchanges_and_returns_at_the_till(): void
    {
        $summary = app(ShiftService::class)->summary($this->shift);
        $this->assertSame([2, 1], [$summary['exchanges'], $summary['till_returns']]);
        $this->assertSame(24_000, $summary['cash']['refunds']);

        foreach ([80, 58] as $paper) {
            $printed = $this->printed('shift', app(ShiftSummaryService::class)->thermalPayload($this->shift), $paper);
            $this->assertMatchesRegularExpression('/^Exchanges at the till\s+2$/m', $printed);
            $this->assertMatchesRegularExpression('/^Returns at the till\s+1$/m', $printed);
            $this->assertMatchesRegularExpression('/^Exchange credit\s+290\.00$/m', $printed);
        }

        $this->actingAs($this->cashier)
            ->get(route('pos.shift.summary', ['shift' => $this->shift->uuid, 'format' => 'a4']))
            ->assertOk()
            ->assertSee('data-test="shift-exchanges"', false)
            ->assertSee('2 / 1');

        $this->actingAs($this->cashier)
            ->getJson(route('pos.shift.expected', ['device' => $this->till->uuid]))
            ->assertJsonPath('summary.exchanges', 2)
            ->assertJsonPath('summary.till_returns', 1)
            ->assertJsonPath('expected_cash', 591_000);
    }

    public function test_the_daily_summary_has_the_returns_and_exchanges_table(): void
    {
        $this->actingAs($this->owner);

        $page = Livewire::withQueryParams(['range' => 'today'])->test(DailySummaryReport::class)
            ->assertSee('Returns and exchanges at the till')
            ->assertSee('Exchange credit');
        $table = collect($page->instance()->document()->tables)->firstWhere('title', 'Returns and exchanges at the till');
        $this->assertNotNull($table);
        $this->assertSame([['exchanges' => 2, 'returns' => 1, 'credit' => 53_000, 'applied' => 29_000, 'patient_paid' => 26_000, 'refunded' => 24_000]], $table->rows);
    }

    public function test_the_patients_page_shows_each_exchange_and_marks_the_new_bills(): void
    {
        $this->actingAs($this->owner);
        $patient = Patient::query()->findOrFail($this->sale->patient_id);

        Livewire::test(ExchangesRelationManager::class, ['ownerRecord' => $patient, 'pageClass' => ViewPatient::class])
            ->assertCanSeeTableRecords(Exchange::query()->get())
            ->assertSee('EX-000001 · Exchange')
            ->assertSee('EX-000003 · Return')
            ->assertSee('T1-000002')
            ->assertSee('Refunded LKR 150.00')
            ->assertSee('Paid LKR 260.00')
            ->assertSee('Side effects')
            ->assertSee('Dr. S. Perera')
            ->assertSee('To Quarantine / Expired');

        Livewire::test(SalesRelationManager::class, ['ownerRecord' => $patient, 'pageClass' => ViewPatient::class])
            ->assertSee('Exchange EX-000001')
            ->assertSee('Exchange EX-000002');
    }

    public function test_an_exchange_without_a_bill_moves_the_drawer_by_the_difference_in_the_cash_movements_report(): void
    {
        app(ExchangeService::class)->create(new ExchangeData($this->till, $this->owner, (string) Str::uuid(), null, [], [new NoReceiptLine($this->panadol->getKey(), 5)], ReturnReason::NotEffective, null, null, [new CartLine($this->amoxil->getKey(), 2, prescriber: 'Dr. S. Perera')]));

        $row = collect(app(ReportService::class)->cashMovementRows($this->today()))->firstWhere('number', 'NR-000001');
        $this->assertSame([10_000, -3_000], [$row['amount'], $row['drawer']]);
        $this->assertSame('No bill NR-000001', app(ReportService::class)->exchangeRows($this->today())[0]['original']);
    }

    public function test_the_owner_sets_the_time_limit(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ShopSettings::class)
            ->assertFormSet(['exchange_max_days' => 14])
            ->assertSee('Returns and exchanges at the till')
            ->fillForm(['exchange_max_days' => 30])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(30, $this->shop->fresh()?->setting('exchange_max_days'));
        $this->assertSame(30, app(ExchangeService::class)->maxDays());
    }
}
