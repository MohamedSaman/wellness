<?php

namespace Tests\Feature\Pos;

use App\Enums\CashMovementCategory;
use App\Enums\CashMovementType;
use App\Exceptions\PosException;
use App\Models\AuditLog;
use App\Models\CashMovement;
use App\Models\Doctor;
use App\Models\Shift;
use App\Models\User;
use App\Services\CashMovementService;
use App\Services\GoLiveResetService;
use App\Services\ShiftService;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Role;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithReceipts;

/**
 * Cash movements in a shift (docs/PHARMACY.md, branch 18): paid out, paid
 * in and doctor payments come off (or add to) the paying till's expected
 * cash only; the rules (module, permissions, open shift, amount, role
 * limits, categories, doctor), voids (owner, open shift, reason), numbers
 * and audit entries.
 */
class CashMovementServiceTest extends BatchTestCase
{
    use WorksWithCheckout, WorksWithReceipts;

    private CashMovementService $movements;

    private ShiftService $shifts;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stockTheClinic();
        $this->movements = app(CashMovementService::class);
        $this->shifts = app(ShiftService::class);
        $this->doctor = Doctor::query()->create(['name' => 'Dr. Perera']);
    }

    private function paidOut(int $amount, ?User $user = null, CashMovementCategory $category = CashMovementCategory::Expense, ?string $note = 'Milk powder'): CashMovement
    {
        return $this->movements->create($this->till, $user ?? $this->cashier, CashMovementType::PaidOut, $amount, $category, note: $note);
    }

    public function test_paid_out_doctor_payments_and_paid_in_move_the_expected_cash(): void
    {
        $shift = $this->shifts->open($this->till, $this->cashier, 500_000);
        $this->billAtGeneral(); // 1,065.00 by bank transfer: no cash

        $this->paidOut(1_000, category: CashMovementCategory::RefundDifference, note: null); // LKR 10.00
        $this->movements->create($this->till, $this->cashier, CashMovementType::PaidIn, 200_000, CashMovementCategory::FloatTopUp);
        $this->movements->create($this->till, $this->cashier, CashMovementType::DoctorPayment, 500_000, doctor: $this->doctor, note: 'Evening session');

        $this->assertSame(500_000 - 1_000 + 200_000 - 500_000, $this->shifts->expectedCash($shift));

        $summary = $this->shifts->summary($shift);
        $this->assertSame([
            'opening' => 500_000, 'sales' => 0, 'refunds' => 0,
            'paid_in' => 200_000, 'paid_out' => 1_000, 'doctor_payments' => 500_000,
            'expected' => 199_000, 'counted' => null, 'difference' => null,
        ], $summary['cash']);
        $this->assertSame([['doctor' => 'Dr. Perera', 'payments' => 1, 'amount' => 500_000]], $summary['doctor_payments']);
        $this->assertSame(['CM-000001', 'CM-000002'], array_column($summary['movements'], 'number'));
        $this->assertSame(['Refund difference', 'Float top-up'], array_column($summary['movements'], 'category'));

        $closed = $this->shifts->close($shift, $this->cashier, 199_000);
        $this->assertSame(199_000, $closed->expected_cash);
        $this->assertSame(0, $closed->difference);
    }

    public function test_only_the_paying_tills_shift_is_affected(): void
    {
        $opd = $this->docTill();
        $docCashier = $this->cashierAt($this->doctors);
        $general = $this->shifts->open($this->till, $this->cashier, 100_000);
        $opdShift = $this->shifts->open($opd, $docCashier, 50_000);

        // A doctor paid from the OPD till, another from the pharmacy's.
        $second = Doctor::query()->create(['name' => 'Dr. Silva']);
        $this->movements->create($opd, $docCashier, CashMovementType::DoctorPayment, 30_000, doctor: $this->doctor);
        $this->movements->create($this->till, $this->cashier, CashMovementType::DoctorPayment, 40_000, doctor: $second);

        $this->assertSame(100_000 - 40_000, $this->shifts->expectedCash($general));
        $this->assertSame(50_000 - 30_000, $this->shifts->expectedCash($opdShift));
        $this->assertSame([['doctor' => 'Dr. Silva', 'payments' => 1, 'amount' => 40_000]], $this->shifts->summary($general)['doctor_payments']);
        $this->assertSame((int) $this->doctors->getKey(), (int) CashMovement::query()->where('device_id', $opd->getKey())->value('location_id'));
    }

    public function test_two_payments_to_one_doctor_are_one_row(): void
    {
        $shift = $this->shifts->open($this->till, $this->cashier, 0);
        $this->movements->create($this->till, $this->cashier, CashMovementType::DoctorPayment, 300_000, doctor: $this->doctor);
        $this->movements->create($this->till, $this->owner, CashMovementType::DoctorPayment, 200_000, doctor: $this->doctor);

        $this->assertSame([['doctor' => 'Dr. Perera', 'payments' => 2, 'amount' => 500_000]], $this->shifts->summary($shift)['doctor_payments']);
    }

    public function test_a_movement_needs_an_open_shift_on_the_till(): void
    {
        $this->expectExceptionMessage('Open a shift on this till first');

        $this->paidOut(1_000);
    }

    public function test_a_closed_shift_takes_no_movement(): void
    {
        $shift = $this->shifts->open($this->till, $this->cashier, 0);
        $this->shifts->close($shift, $this->cashier, 0);

        $this->expectExceptionMessage('Open a shift on this till first');

        $this->paidOut(1_000);
    }

    public function test_the_amount_category_note_and_doctor_are_checked(): void
    {
        $this->shifts->open($this->till, $this->cashier, 0);

        $refused = function (callable $make, string $message): void {
            try {
                $make();
                $this->fail("Expected: {$message}");
            } catch (PosException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        };

        $refused(fn () => $this->paidOut(0), 'above zero');
        $refused(fn () => $this->paidOut(-100), 'above zero');
        $refused(fn () => $this->movements->create($this->till, $this->cashier, CashMovementType::PaidOut, 1_000), 'Choose what the cash was paid out for');
        $refused(fn () => $this->paidOut(1_000, category: CashMovementCategory::FloatTopUp), 'Choose what the cash was paid out for');
        $refused(fn () => $this->movements->create($this->till, $this->cashier, CashMovementType::PaidIn, 1_000, CashMovementCategory::Expense), 'Choose what the cash was paid in for');
        $refused(fn () => $this->paidOut(1_000, category: CashMovementCategory::Other, note: '  '), 'Say what the cash was for');
        $refused(fn () => $this->movements->create($this->till, $this->cashier, CashMovementType::DoctorPayment, 1_000), 'Choose the doctor');

        $this->doctor->update(['is_active' => false]);
        $refused(fn () => $this->movements->create($this->till, $this->cashier, CashMovementType::DoctorPayment, 1_000, doctor: $this->doctor), 'not an active doctor');

        $this->assertSame(0, CashMovement::query()->count());
    }

    public function test_permissions_are_checked_in_the_service(): void
    {
        $clerk = $this->cashierAt();
        $clerk->syncRoles([Role::findOrCreate('clerk')->givePermissionTo(['pos.checkout', 'shifts.open_close'])]);
        $this->shifts->open($this->till, $clerk, 0);

        try {
            $this->paidOut(1_000, $clerk);
            $this->fail('Paid out without cash.movements.');
        } catch (PosException $e) {
            $this->assertSame('You are not allowed to pay cash in or out.', $e->getMessage());
        }

        $this->expectExceptionMessage('You are not allowed to pay doctors.');
        $this->movements->create($this->till, $clerk, CashMovementType::DoctorPayment, 1_000, doctor: $this->doctor);
    }

    public function test_the_module_must_be_on(): void
    {
        $this->shifts->open($this->till, $this->cashier, 0);
        Feature::for($this->shop)->deactivate('module:cash_movements');

        try {
            $this->paidOut(1_000);
            $this->fail('Paid out with the module off.');
        } catch (PosException $e) {
            $this->assertStringContainsString('does not record cash paid in or out', $e->getMessage());
        }

        $this->expectExceptionMessage('does not pay doctors from the till');
        $this->movements->create($this->till, $this->cashier, CashMovementType::DoctorPayment, 1_000, doctor: $this->doctor);
    }

    public function test_role_limits_apply_to_paid_out_and_doctor_payments_not_to_paid_in_nor_the_owner(): void
    {
        $this->shifts->open($this->till, $this->cashier, 0);

        $this->assertSame(200_000, $this->movements->limitFor($this->cashier, CashMovementType::PaidOut));
        $this->assertSame(2_500_000, $this->movements->limitFor($this->cashier, CashMovementType::DoctorPayment));
        $this->assertNull($this->movements->limitFor($this->cashier, CashMovementType::PaidIn));
        $this->assertNull($this->movements->limitFor($this->owner, CashMovementType::PaidOut));

        $this->paidOut(200_000);                         // exactly the limit
        $this->movements->create($this->till, $this->cashier, CashMovementType::PaidIn, 9_000_000, CashMovementCategory::FloatTopUp);
        $this->movements->create($this->till, $this->cashier, CashMovementType::DoctorPayment, 2_500_000, doctor: $this->doctor);
        $this->paidOut(5_000_000, $this->owner);

        try {
            $this->paidOut(200_001);
            $this->fail('Paid out above the limit.');
        } catch (PosException $e) {
            $this->assertSame('The most you may pay out at once is LKR 2,000.00. Ask the owner.', $e->getMessage());
        }

        $this->expectExceptionMessage('The most you may pay a doctor at once is LKR 25,000.00.');
        $this->movements->create($this->till, $this->cashier, CashMovementType::DoctorPayment, 2_500_001, doctor: $this->doctor);
    }

    public function test_a_limit_can_be_lifted_and_a_user_with_two_roles_gets_the_highest(): void
    {
        $this->setShopSetting('cash_movement_limits', ['cashier' => ['paid_out' => 100_000, 'doctor_payment' => null]]);
        $this->assertSame(100_000, $this->movements->limitFor($this->cashier, CashMovementType::PaidOut));
        $this->assertNull($this->movements->limitFor($this->cashier, CashMovementType::DoctorPayment));

        $this->cashier->assignRole('owner');
        $this->assertNull($this->movements->limitFor($this->cashier->fresh(), CashMovementType::PaidOut), 'owner is not listed: no limit');
    }

    public function test_the_owner_voids_a_movement_of_an_open_shift_with_a_reason(): void
    {
        $shift = $this->shifts->open($this->till, $this->cashier, 100_000);
        $movement = $this->paidOut(10_000);

        try {
            $this->movements->void($movement, $this->cashier, 'Typed wrong');
            $this->fail('A cashier voided a movement.');
        } catch (PosException $e) {
            $this->assertSame('You are not allowed to void cash movements.', $e->getMessage());
        }

        try {
            $this->movements->void($movement, $this->owner, ' ');
            $this->fail('Voided without a reason.');
        } catch (PosException $e) {
            $this->assertSame('Give the reason for voiding it.', $e->getMessage());
        }

        $this->movements->void($movement, $this->owner, 'Typed 100 instead of 10');
        $this->assertSame(100_000, $this->shifts->expectedCash($shift));
        $this->assertSame(1, $this->shifts->summary($shift)['voided_movements']);
        $this->assertSame([], $this->shifts->summary($shift)['movements']);

        $this->expectExceptionMessage('CM-000001 is already voided.');
        $this->movements->void($movement, $this->owner, 'Again');
    }

    public function test_a_movement_of_a_closed_shift_cannot_be_voided(): void
    {
        $shift = $this->shifts->open($this->till, $this->cashier, 100_000);
        $movement = $this->paidOut(10_000);
        $this->shifts->close($shift, $this->cashier, 90_000);

        $this->expectExceptionMessage("CM-000001's shift is closed");

        $this->movements->void($movement, $this->owner, 'Too late');
    }

    public function test_creating_and_voiding_are_audited(): void
    {
        $shift = $this->shifts->open($this->till, $this->cashier, 0);
        $movement = $this->movements->create($this->till, $this->cashier, CashMovementType::DoctorPayment, 500_000, doctor: $this->doctor, note: 'Evening session');
        $this->movements->void($movement, $this->owner, 'Paid twice');

        $created = AuditLog::query()->where('event', 'cash_movement_created')->sole();
        $this->assertSame((int) $this->cashier->getKey(), (int) $created->user_id);
        $this->assertSame((int) $this->shop->getKey(), (int) $created->shop_id);
        $this->assertSameIgnoringKeyOrder([
            'number' => 'CM-000001', 'type' => 'doctor_payment', 'category' => null, 'amount' => 500_000,
            'doctor_id' => $this->doctor->getKey(), 'doctor' => 'Dr. Perera', 'shift_id' => $shift->getKey(),
            'till' => 'T1', 'note' => 'Evening session',
        ], $created->new_values);

        $voided = AuditLog::query()->where('event', 'cash_movement_voided')->sole();
        $this->assertSame((int) $this->owner->getKey(), (int) $voided->user_id);
        $this->assertSame('Paid twice', $voided->new_values['reason']);
    }

    public function test_a_shift_whose_drawer_went_below_zero_still_closes(): void
    {
        // The float was entered as 0, and a doctor was paid 5,000.00 from cash that was there.
        $shift = $this->shifts->open($this->till, $this->cashier, 0);
        $this->movements->create($this->till, $this->cashier, CashMovementType::DoctorPayment, 500_000, doctor: $this->doctor);

        $closed = $this->shifts->close($shift, $this->cashier, 20_000);

        $this->assertSame(-500_000, $closed->fresh()?->expected_cash);
        $this->assertSame(520_000, $closed->fresh()?->difference);
        $this->assertSame(-500_000, $this->shifts->summary($closed->fresh() ?? $closed)['cash']['expected']);
    }

    public function test_go_live_reset_removes_the_rehearsals_movements(): void
    {
        $shift = $this->shifts->open($this->till, $this->cashier, 0);
        $this->paidOut(1_000);
        $this->shifts->close($shift, $this->cashier, 0);

        $removed = app(GoLiveResetService::class)->reset($this->shop, $this->owner, false);

        $this->assertSame(1, $removed['cash_movements']);
        $this->assertSame(0, CashMovement::query()->count());
        $this->assertSame(0, Shift::query()->count());
        $this->assertSame(1, Doctor::query()->count(), 'doctors are master data');
    }
}
