<?php

namespace Tests\Feature\Pos;

use App\Enums\CashMovementType;
use App\Exceptions\PosException;
use App\Filament\Resources\Doctors\Pages\ManageDoctors;
use App\Filament\Resources\Doctors\Pages\ViewDoctor;
use App\Models\AuditLog;
use App\Models\CashMovement;
use App\Models\Doctor;
use App\Models\Shift;
use App\Services\CashMovementService;
use App\Services\DoctorService;
use App\Services\ShiftService;
use Filament\Actions\Testing\TestAction;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithReceipts;

/**
 * Doctors and their payments (docs/PHARMACY.md, branch 18): the owner keeps
 * the list in Admin → Doctors (doctors.manage, audited, deactivated not
 * deleted); any till pays a doctor in cash, a typed amount, with today's OPD
 * figures and payments shown for reference only; the payment prints a
 * DOCTOR PAYMENT slip for the doctor to sign.
 */
class DoctorPaymentTest extends BatchTestCase
{
    use WorksWithCheckout, WorksWithReceipts;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stockTheClinic();
        $this->doctor = app(DoctorService::class)->create(['name' => '  Dr.   S. Perera ', 'phone' => '077 123 4567'], $this->owner);
    }

    public function test_the_owner_keeps_the_list_of_doctors(): void
    {
        $this->assertSame('Dr. S. Perera', $this->doctor->name);

        $this->actingAs($this->owner);
        Livewire::test(ManageDoctors::class)
            ->assertCanSeeTableRecords([$this->doctor])
            ->callAction('create', data: ['name' => 'Dr. N. Silva', 'is_active' => true])
            ->assertHasNoActionErrors()
            ->callAction('create', data: ['name' => 'dr. n. silva', 'is_active' => true])
            ->assertNotified('dr. n. silva is already on the list.');

        $silva = Doctor::query()->where('name', 'Dr. N. Silva')->sole();
        Livewire::test(ManageDoctors::class)
            ->callAction(TestAction::make('edit')->table($silva), data: ['name' => 'Dr. N. Silva', 'is_active' => false])
            ->assertHasNoActionErrors();
        $this->assertFalse($silva->fresh()?->is_active);

        // Deactivated doctors are hidden by default, never deleted.
        Livewire::test(ManageDoctors::class)->assertCanNotSeeTableRecords([$silva]);
        $this->expectException(\LogicException::class);
        $silva->delete();
    }

    public function test_changes_are_audited_and_only_the_owner_manages_doctors(): void
    {
        $this->assertSame(1, AuditLog::query()->where('event', 'created')->where('auditable_type', $this->doctor->getMorphClass())->count());
        app(DoctorService::class)->update($this->doctor, ['name' => 'Dr. S. Perera', 'is_active' => false], $this->owner);
        $this->assertSame(1, AuditLog::query()->where('event', 'updated')->where('auditable_type', $this->doctor->getMorphClass())->count());

        try {
            app(DoctorService::class)->create(['name' => 'Dr. X'], $this->cashier);
            $this->fail('A cashier added a doctor.');
        } catch (PosException $e) {
            $this->assertSame('You are not allowed to manage doctors.', $e->getMessage());
        }

        $this->actingAs($this->cashier)->get(ManageDoctors::getUrl())->assertForbidden();
        $this->actingAs($this->owner)->get(ManageDoctors::getUrl())->assertOk()->assertSee('Add doctor');
        $this->actingAs($this->owner)->get(ViewDoctor::getUrl(['record' => $this->doctor]))->assertOk();
    }

    public function test_the_opd_till_and_the_pharmacy_till_both_pay_doctors(): void
    {
        $opd = $this->docTill();
        $docCashier = $this->cashierAt($this->doctors);
        $shifts = app(ShiftService::class);
        $opdShift = $shifts->open($opd, $docCashier, 0);
        $genShift = $shifts->open($this->till, $this->cashier, 600_000);
        $this->opdBill();                     // one OPD bill today at T2

        $this->actingAs($docCashier)->postJson(route('pos.cash-movements.store'), [
            'device' => $opd->uuid, 'type' => 'doctor_payment', 'amount' => '5,000', 'doctor' => $this->doctor->getKey(), 'note' => 'Morning session',
        ])->assertCreated()->assertJsonPath('movement.doctor', 'Dr. S. Perera');

        $response = $this->actingAs($this->cashier)
            ->getJson(route('pos.cash-movements.index', ['device' => $this->till->uuid]))
            ->assertOk()
            ->assertJsonPath('doctors', [['id' => $this->doctor->getKey(), 'name' => 'Dr. S. Perera']])
            ->assertJsonPath('reference.opd_bills', 1)
            ->assertJsonPath('reference.paid_today.0.amount', 500_000)
            ->assertJsonPath('reference.paid_today.0.till', 'T2')
            ->assertJsonPath('reference.paid_today.0.at', '10:00');
        $this->assertGreaterThan(0, $response->json('reference.consultation_fees'));

        $this->actingAs($this->cashier)->postJson(route('pos.cash-movements.store'), [
            'device' => $this->till->uuid, 'type' => 'doctor_payment', 'amount' => '3000', 'doctor' => $this->doctor->getKey(), 'note' => 'Evening session',
        ])->assertCreated();

        // Each till's drawer pays its own.
        $this->assertSame(-500_000 + $opdShift->opening_cash + $this->cashTakenAt($opdShift), $shifts->expectedCash($opdShift));
        $this->assertSame(600_000 - 300_000, $shifts->expectedCash($genShift));
        $this->assertSame([500_000, 300_000], CashMovement::query()->orderBy('id')->pluck('amount')->all());
    }

    public function test_an_inactive_doctor_is_not_offered_nor_paid(): void
    {
        app(ShiftService::class)->open($this->till, $this->cashier, 0);
        app(DoctorService::class)->update($this->doctor, ['name' => 'Dr. S. Perera', 'is_active' => false], $this->owner);

        $this->actingAs($this->cashier)
            ->getJson(route('pos.cash-movements.index', ['device' => $this->till->uuid]))
            ->assertJsonPath('doctors', []);
        $this->actingAs($this->cashier)->postJson(route('pos.cash-movements.store'), [
            'device' => $this->till->uuid, 'type' => 'doctor_payment', 'amount' => '100', 'doctor' => $this->doctor->getKey(),
        ])->assertStatus(422)->assertJsonPath('message', 'Dr. S. Perera is not an active doctor of this shop.');
    }

    public function test_the_doctor_payment_slip_is_for_the_doctor_to_sign(): void
    {
        app(ShiftService::class)->open($this->till, $this->cashier, 0);
        $movement = app(CashMovementService::class)->create($this->till, $this->cashier, CashMovementType::DoctorPayment, 500_000, doctor: $this->doctor, note: 'Evening session');

        $lines = null;
        $this->actingAs($this->cashier)->get(route('pos.cash-movements.slip', $movement))->assertOk()
            ->assertInertia(function (Assert $page) use (&$lines): void {
                $lines = $page->toArray()['props']['thermal']['lines'];
            });
        $slip = implode("\n", array_column((array) $lines, 'text'));

        $this->assertStringContainsString('DOCTOR PAYMENT', $slip);
        $this->assertMatchesRegularExpression('/^Doctor\s+Dr\. S\. Perera$/m', $slip);
        $this->assertMatchesRegularExpression('/^Till\s+T1/m', $slip);
        $this->assertMatchesRegularExpression('/^Location\s+General Pharmacy$/m', $slip);
        $this->assertMatchesRegularExpression('/^Shift\s+18\/10\/2026 10:00 '.preg_quote($this->cashier->name, '/').'$/m', $slip);
        $this->assertMatchesRegularExpression('/^Date\s+18\/10\/2026 10:00$/m', $slip);
        $this->assertMatchesRegularExpression('/^Paid by\s+'.preg_quote($this->cashier->name, '/').'$/m', $slip);
        $this->assertMatchesRegularExpression('/^AMOUNT LKR\s+5,000\.00$/m', $slip);
        $this->assertStringContainsString('Note: Evening session', $slip);
        $this->assertMatchesRegularExpression("/^Doctor's signature _+$/m", $slip);
        $this->assertDoesNotMatchRegularExpression('/^For\s/m', $slip);
    }

    /** Cash the OPD bill took (net of change), on the OPD till's shift. */
    private function cashTakenAt(Shift $shift): int
    {
        return app(ShiftService::class)->summary($shift)['cash']['sales'];
    }
}
