<?php

namespace Tests\Feature\Pos;

use App\Enums\CashMovementCategory;
use App\Enums\CashMovementType;
use App\Filament\Pages\Reports\DailySummaryReport;
use App\Models\Doctor;
use App\Models\Shift;
use App\Services\CashMovementService;
use App\Services\ReceiptService;
use App\Services\ReportService;
use App\Services\ShiftService;
use App\Services\ShiftSummaryService;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithReceipts;

/**
 * Cash movements on the shift summary and the daily summary (docs/
 * PHARMACY.md, branch 18): paid out, doctor payments and paid in in the
 * drawer's figures, the paid outs and ins listed, and a section of their own
 * for the doctor payments, one row per doctor; on the receipt printer at 48,
 * 32 and 36 columns, on A4, in the Close shift dialog's figures, and on the
 * daily summary by shop day.
 */
class CashMovementSummaryTest extends BatchTestCase
{
    use WorksWithCheckout, WorksWithReceipts;

    private CashMovementService $movements;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stockTheClinic();
        $this->movements = app(CashMovementService::class);
    }

    /**
     * Paid out 10.00 (refund difference) and 250.00 (an
     * expense, voided: not counted); a 1,000.00 float top-up; Dr. Perera
     * paid 5,000.00 and 1,500.00, Dr. Silva 3,000.00. Float 10,000.00, so
     * expected 10,000 - 10 + 1,000 - 9,500 = 1,490.00; counted 1,490.00.
     */
    private function aShift(bool $close = true): Shift
    {
        $shift = app(ShiftService::class)->open($this->till, $this->cashier, 1_000_000);
        $perera = Doctor::query()->create(['name' => 'Dr. Perera']);
        $silva = Doctor::query()->create(['name' => 'Dr. Silva']);

        $this->movements->create($this->till, $this->cashier, CashMovementType::PaidOut, 1_000, CashMovementCategory::RefundDifference, note: 'Pre-system return');
        $expense = $this->movements->create($this->till, $this->cashier, CashMovementType::PaidOut, 25_000, CashMovementCategory::Expense);
        $this->movements->void($expense, $this->owner, 'Not paid after all');
        $this->movements->create($this->till, $this->cashier, CashMovementType::PaidIn, 100_000, CashMovementCategory::FloatTopUp);
        $this->movements->create($this->till, $this->cashier, CashMovementType::DoctorPayment, 500_000, doctor: $perera, note: 'Morning session');
        $this->movements->create($this->till, $this->cashier, CashMovementType::DoctorPayment, 300_000, doctor: $silva);
        $this->movements->create($this->till, $this->cashier, CashMovementType::DoctorPayment, 150_000, doctor: $perera);

        if (! $close) {
            return $shift;
        }

        $this->travel(8)->hours();

        return app(ShiftService::class)->close($shift, $this->cashier, 149_000);
    }

    public function test_the_receipt_printer_summary_has_the_movements_and_the_doctor_payments_at_every_width(): void
    {
        $shift = $this->aShift();
        $this->assertSame(149_000, $shift->expected_cash);
        $payload = app(ShiftSummaryService::class)->thermalPayload($shift->fresh());

        foreach ([48 => 80, 32 => 58] as $columns => $paper) {
            $printed = $this->printed('shift', $payload, $paper);

            $this->assertMatchesRegularExpression('/^Cash refunds\s+0\.00$/m', $printed);
            $this->assertMatchesRegularExpression('/^Paid out\s+-10\.00$/m', $printed);
            $this->assertMatchesRegularExpression('/^Doctor payments\s+-9,500\.00$/m', $printed);
            $this->assertMatchesRegularExpression('/^Paid in\s+1,000\.00$/m', $printed);
            $this->assertMatchesRegularExpression('/^EXPECTED CASH LKR\s+1,490\.00$/m', $printed);

            // The paid outs and ins one by one, the voided one apart.
            $this->assertStringContainsString('Paid out and paid in', $printed);
            $this->assertMatchesRegularExpression('/^10:00 Paid out\s+-10\.00$/m', $printed);
            $this->assertStringContainsString('CM-000001 Refund difference: Pre-system return', (string) preg_replace('/\s+/', ' ', $printed));
            $this->assertMatchesRegularExpression('/^10:00 Paid in\s+1,000\.00$/m', $printed);
            $this->assertStringNotContainsString('-250.00', $printed);
            $this->assertStringContainsString('Voided (1) not counted.', $printed);

            // Doctor payments: a section of its own, one row per doctor.
            $section = substr($printed, (int) strpos($printed, "\nDoctor payments\n"));
            $this->assertMatchesRegularExpression('/^Dr\. Perera \(2\)\s+6,500\.00$/m', $section);
            $this->assertMatchesRegularExpression('/^Dr\. Silva\s+3,000\.00$/m', $section);
            $this->assertMatchesRegularExpression('/^Total\s+9,500\.00$/m', $section);

            foreach (explode("\n", $printed) as $line) {
                $this->assertLessThanOrEqual($columns, strlen($line), "{$columns} columns: {$line}");
            }
        }

        // Large print (36 columns), as the browser prints it.
        $lines = app(ReceiptService::class)->thermal('shift', $payload)['lines'];
        $this->assertContains('Doctor payments', array_column($lines, 'text'));
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(36, strlen($line['text']));
        }
    }

    public function test_a_shift_with_no_movements_says_so(): void
    {
        $shift = app(ShiftService::class)->open($this->till, $this->cashier, 0);
        $printed = $this->printed('shift', app(ShiftSummaryService::class)->thermalPayload($shift));

        $this->assertStringContainsString('Nothing paid out or in.', $printed);
        $this->assertStringContainsString('No doctor paid in this shift.', $printed);
        $this->assertMatchesRegularExpression('/^Doctor payments\s+0\.00$/m', $printed);
    }

    public function test_the_a4_summary_has_the_movements_and_the_doctor_payments(): void
    {
        $shift = $this->aShift();

        $html = $this->actingAs($this->cashier)
            ->get(route('pos.shift.summary', ['shift' => $shift->uuid, 'format' => 'a4']))
            ->assertOk()
            ->assertSeeInOrder(['Cash refunds', 'Paid out', 'Doctor payments', 'Paid in', 'Expected in the drawer'])
            ->assertSee('data-test="shift-movements"', false)
            ->assertSee('Pre-system return')
            ->assertSee('Voided cash movements (1) are not counted.')
            ->getContent();

        $doctors = substr((string) $html, (int) strpos((string) $html, 'data-test="shift-doctor-payments"'));
        $this->assertStringContainsString('Dr. Perera (2)', $doctors);
        $this->assertStringContainsString('6,500.00', $doctors);
        $this->assertStringContainsString('Dr. Silva', $doctors);
        $this->assertStringContainsString('9,500.00', $doctors);
    }

    public function test_the_close_shift_figures_carry_the_movements(): void
    {
        $this->aShift(close: false);

        $this->actingAs($this->cashier)
            ->getJson(route('pos.shift.expected', ['device' => $this->till->uuid]))
            ->assertOk()
            ->assertJsonPath('expected_cash', 149_000)
            ->assertJsonPath('summary.cash.paid_out', 1_000)
            ->assertJsonPath('summary.cash.doctor_payments', 950_000)
            ->assertJsonPath('summary.cash.paid_in', 100_000)
            ->assertJsonPath('summary.doctor_payments.0.doctor', 'Dr. Perera')
            ->assertJsonPath('summary.doctor_payments.0.amount', 650_000);
    }

    public function test_the_daily_summary_has_the_cash_columns_and_each_doctors_payments_by_shop_day(): void
    {
        $this->aShift(close: false);

        // Paid at 23:50 the day before (shop time): not today's.
        $this->travelTo(CarbonImmutable::parse('2026-10-17 18:20:00', 'UTC'));
        $opd = $this->docTill();
        $docCashier = $this->cashierAt($this->doctors);
        $yesterday = app(ShiftService::class)->open($opd, $docCashier, 0);
        $this->movements->create($opd, $docCashier, CashMovementType::DoctorPayment, 200_000, doctor: Doctor::query()->where('name', 'Dr. Silva')->sole());
        $this->travelTo(CarbonImmutable::parse('2026-10-18 04:30:00', 'UTC'));

        $reports = app(ReportService::class);
        $today = ReportPeriod::today($this->shop->fresh());
        $this->assertSame([
            ['doctor_id' => Doctor::query()->where('name', 'Dr. Perera')->value('id'), 'doctor' => 'Dr. Perera', 'payments' => 2, 'amount' => 650_000],
            ['doctor_id' => Doctor::query()->where('name', 'Dr. Silva')->value('id'), 'doctor' => 'Dr. Silva', 'payments' => 1, 'amount' => 300_000],
        ], $reports->doctorPaymentsByDoctor($today));
        $this->assertSame([], $reports->doctorPaymentsByDoctor($today, (int) $this->doctors->getKey()));
        $this->assertSame(200_000, $reports->doctorPaymentsByDoctor(ReportPeriod::between($this->shop->fresh(), '2026-10-17', '2026-10-17'))[0]['amount']);

        $cash = collect($reports->shiftCash($today))->firstWhere('till', 'T1');
        $this->assertSame([1_000, 950_000, 100_000, 149_000], [$cash['paid_out'], $cash['doctor_payments'], $cash['paid_in'], $cash['expected_cash']]);
        $this->assertNotNull($yesterday);

        $this->actingAs($this->owner);
        Livewire::test(DailySummaryReport::class)
            ->assertSee('Paid out')
            ->assertSee('Doctor payments')
            ->assertSee('Expected = float + cash sales − cash refunds − paid out − doctor payments + paid in.')
            ->assertSee('Dr. Perera')
            ->assertSee('6,500.00');
    }
}
