<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use App\Services\ShiftService;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithPatientVisits;

/**
 * Patients this shift at the till (docs/PHARMACY.md, branch 16): the bills
 * with a patient in the till's own open shift, with time in shop time,
 * patient, age at the visit and invoice; never another shift or till; a
 * till the user may not use is refused; pharmacy only.
 */
class TillShiftPatientsTest extends PharmacyTestCase
{
    use WorksWithCheckout;
    use WorksWithPatientVisits;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedVisits();
    }

    public function test_the_tills_open_shift_only(): void
    {
        $this->actingAs($this->cashier)
            ->getJson(route('pos.shift.patients', ['device' => $this->till->uuid]))
            ->assertOk()
            ->assertExactJson([
                'shift' => ['uuid' => $this->shiftB->uuid, 'opened_at' => '2026-10-18 00:05'],
                'bills' => [
                    [
                        'uuid' => $this->bills['nimal_gen']->uuid,
                        'invoice_no' => $this->bills['nimal_gen']->invoice_no,
                        'sold_at' => '2026-10-18 00:10',
                        'patient' => 'Nimal Perera',
                        'age' => '34 y',
                        'bill_type' => 'standard',
                        'status' => 'completed',
                    ],
                    [
                        'uuid' => $this->bills['rani']->uuid,
                        'invoice_no' => $this->bills['rani']->invoice_no,
                        'sold_at' => '2026-10-18 09:30',
                        'patient' => 'Rani Silva',
                        'age' => '25 y',
                        'bill_type' => 'standard',
                        'status' => 'voided',
                    ],
                ],
                'without_patient' => 0,
            ]);
    }

    public function test_a_till_with_no_open_shift_has_no_patients_and_other_tills_stay_apart(): void
    {
        // T2's shift C is closed: its 09:00 OPD bill is not shown.
        $this->actingAs($this->doctor)
            ->getJson(route('pos.shift.patients', ['device' => $this->doc->uuid]))
            ->assertOk()
            ->assertExactJson(['shift' => null, 'bills' => [], 'without_patient' => 0]);

        // A new shift at T2 shows only its own bill; T1's list does not change.
        $this->at('2026-10-18 10:05');
        $shift = app(ShiftService::class)->open($this->doc, $this->doctor, 0);
        $this->at('2026-10-18 10:10');
        $opd = $this->bill('Kamala Perera', till: $this->doc, cashier: $this->doctor, opd: true);

        $this->actingAs($this->doctor)
            ->getJson(route('pos.shift.patients', ['device' => $this->doc->uuid]))
            ->assertOk()
            ->assertJsonPath('shift.uuid', $shift->uuid)
            ->assertJsonCount(1, 'bills')
            ->assertJsonPath('bills.0.uuid', $opd->uuid)
            ->assertJsonPath('bills.0.patient', 'Kamala Perera')
            ->assertJsonPath('bills.0.bill_type', 'opd');

        $this->actingAs($this->cashier)
            ->getJson(route('pos.shift.patients', ['device' => $this->till->uuid]))
            ->assertJsonCount(2, 'bills')
            ->assertJsonMissing(['uuid' => $opd->uuid]);
    }

    public function test_a_till_the_user_may_not_use_is_refused(): void
    {
        // The General Pharmacy cashier may not use the OPD till.
        $this->actingAs($this->cashier)
            ->getJson(route('pos.shift.patients', ['device' => $this->doc->uuid]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('device');

        $this->actingAs($this->cashier)
            ->getJson(route('pos.shift.patients'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('device');
    }

    public function test_bills_without_a_patient_are_counted(): void
    {
        $this->at('2026-10-18 09:45');
        app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->till,
            cashier: $this->cashier,
            lines: [new CartLine($this->labTest->getKey(), 1)],
            payments: [new PaymentInput(PaymentMethod::Cash, 100_000)],
            uuid: (string) Str::uuid(),
        ));

        $this->actingAs($this->cashier)
            ->getJson(route('pos.shift.patients', ['device' => $this->till->uuid]))
            ->assertJsonCount(2, 'bills')
            ->assertJsonPath('without_patient', 1);
    }

    public function test_pharmacy_only(): void
    {
        Feature::for($this->shop)->deactivate('module:pharmacy');

        $this->actingAs($this->cashier)
            ->getJson(route('pos.shift.patients', ['device' => $this->till->uuid]))
            ->assertNotFound();
    }
}
