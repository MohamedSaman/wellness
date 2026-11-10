<?php

namespace Tests\Feature\Pos;

use App\Enums\DosageForm;
use App\Enums\DrugSchedule;
use App\Enums\PaymentMethod;
use App\Exceptions\PosException;
use App\Models\Patient;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PatientInput;
use App\Services\Data\PaymentInput;
use App\Services\PatientService;
use App\Support\PatientAge;
use Illuminate\Support\Str;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * The patient on a clinic sale (docs/PHARMACY.md, branch 13): one record
 * shared by every till, found or added at the till, with the sale keeping
 * their name and age at the visit. The shop's patient_on_sale says when one
 * is required ('ask' by default: never). Online a missing patient is
 * refused; offline it is flagged and the sale kept.
 */
class PatientCheckoutTest extends BatchTestCase
{
    use WorksWithCheckout;

    private ProductVariant $amox;

    private ProductVariant $morphine;

    private ProductVariant $panadol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setShopSetting('rx_prescriber', 'prompt');
        $this->amox = $this->medicine('Amoxicillin 250 mg Capsules', [], schedule: DrugSchedule::PrescriptionOnly);
        $this->morphine = $this->medicine('Morphine 10 mg Tablets', [], schedule: DrugSchedule::Controlled);
        $this->panadol = $this->medicine();

        foreach ([$this->amox, $this->morphine, $this->panadol] as $variant) {
            $this->receive($variant, 'B1', '12/2029', 100);
        }
    }

    /** @param  list<CartLine>|null  $lines */
    private function ring(?PatientInput $patient = null, ?array $lines = null, bool $offline = false, ?string $customerName = null, ?string $customerPhone = null): Sale
    {
        return app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->till,
            cashier: $this->cashier,
            lines: $lines ?? [new CartLine($this->panadol->getKey(), 2, tillUnitPrice: 2_000)],
            payments: [new PaymentInput(PaymentMethod::Cash, 1_000_000)],
            uuid: (string) Str::uuid(),
            customerName: $customerName,
            customerPhone: $customerPhone,
            isOfflineSync: $offline,
            soldAt: $offline ? now()->subMinutes(3) : null,
            patient: $patient,
        ));
    }

    private function newPatient(string $name = 'Nimal Perera', ?int $ageMonths = 408): PatientInput
    {
        return new PatientInput((string) Str::uuid(), $name, $ageMonths);
    }

    private function assertRefused(callable $attempt, string $message): void
    {
        try {
            $attempt();
            $this->fail("Accepted; expected: {$message}");
        } catch (PosException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }

    public function test_a_sale_may_skip_the_patient_by_default(): void
    {
        $this->assertSame('ask', $this->shop->setting('patient_on_sale'));

        $sale = $this->ring(lines: [new CartLine($this->amox->getKey(), 1)]);

        $this->assertNull($sale->patient_id);
        $this->assertNull($sale->patient_age_months);
        $this->assertNull($sale->customer_name);
        $this->assertFalse($sale->needs_review);
    }

    public function test_a_patient_added_at_the_till_is_created_and_the_sale_keeps_their_name_and_age(): void
    {
        $input = $this->newPatient('  Nimal   Perera ', 408);
        $sale = $this->ring($input);

        $patient = Patient::query()->sole();
        $this->assertSame($input->uuid, $patient->uuid);
        $this->assertSame('Nimal Perera', $patient->name);
        // 34 years before the visit (2026-10-18 in Colombo), estimated.
        $this->assertSame('1992-10-18', $patient->birth_date?->toDateString());
        $this->assertTrue($patient->birth_date_estimated);
        $this->assertSame($this->cashier->getKey(), $patient->created_by);
        $this->assertSame($this->till->getKey(), $patient->device_id);

        $this->assertSame($patient->getKey(), $sale->patient_id);
        $this->assertSame('Nimal Perera', $sale->customer_name);
        $this->assertSame(408, $sale->patient_age_months);
        $this->assertSame(DosageForm::Tablet, $sale->items->sole()->dosage_form);

        // A baby: months.
        $baby = $this->ring($this->newPatient('Baby Silva', 8));
        $this->assertSame(8, $baby->patient_age_months);
        $this->assertSame('2026-02-18', $baby->patient->birth_date?->toDateString());

        // Without an age: no date of birth, no age on the sale.
        $this->assertNull($this->ring($this->newPatient('Kamala Fernando', null))->patient_age_months);
    }

    public function test_an_existing_patient_keeps_their_record_name_and_the_age_at_each_visit(): void
    {
        $first = $this->newPatient('Nimal Perera', 408);
        $this->ring($first);
        $patient = Patient::query()->sole();

        // A year later, no age typed: the age comes from the date of birth.
        $this->travel(365)->days();
        $sale = $this->ring(new PatientInput($first->uuid, 'Someone Else'));
        $this->assertSame(1, Patient::query()->count());
        $this->assertSame('Nimal Perera', $sale->customer_name, 'Online the till cannot rename a patient.');
        $this->assertSame(420, $sale->patient_age_months);

        // An age typed re-estimates a date of birth that was only estimated.
        $sale = $this->ring(new PatientInput($first->uuid, 'Nimal Perera', 432));
        $this->assertSame(432, $sale->patient_age_months);
        $this->assertSame('1991-10-18', $patient->refresh()->birth_date?->toDateString());

        // A date of birth that was told is kept; the sale keeps the age typed.
        app(PatientService::class)->update($patient, $this->owner, ['name' => 'Nimal Perera', 'birth_date' => '1990-01-05']);
        $sale = $this->ring(new PatientInput($first->uuid, null, 300));
        $this->assertSame(300, $sale->patient_age_months);
        $this->assertSame('1990-01-05', $patient->refresh()->birth_date?->toDateString());
        $this->assertFalse($patient->birth_date_estimated);
    }

    public function test_a_sale_keeps_its_snapshot_when_the_patient_is_edited_later(): void
    {
        $input = $this->newPatient('Nimal Perera', 408);
        $sale = $this->ring($input);

        app(PatientService::class)->update($sale->patient, $this->owner, ['name' => 'Nimal K. Perera', 'age' => '40']);

        $sale->refresh();
        $this->assertSame('Nimal Perera', $sale->customer_name);
        $this->assertSame(408, $sale->patient_age_months);
        $this->assertSame('Nimal K. Perera', $sale->patient->name);
        $this->assertSame(480, $sale->patient->ageMonths());
    }

    public function test_required_on_every_sale(): void
    {
        $this->setShopSetting('patient_on_sale', 'always');

        $this->assertRefused(fn () => $this->ring(), 'Choose or add the patient for this sale.');
        $this->assertSame(0, Sale::query()->count());
        $this->assertNotNull($this->ring($this->newPatient())->patient_id);

        $offline = $this->ring(offline: true);
        $this->assertTrue($offline->needs_review);
        $this->assertSame([['type' => 'patient_missing', 'message' => 'This sale was recorded without the patient the shop requires on every sale.']], $offline->review_reasons);
    }

    public function test_required_for_prescription_and_controlled_lines(): void
    {
        $this->setShopSetting('patient_on_sale', 'rx');

        // Over the counter only: may skip.
        $this->assertNull($this->ring()->patient_id);

        $this->assertRefused(
            fn () => $this->ring(lines: [new CartLine($this->panadol->getKey(), 1), new CartLine($this->amox->getKey(), 1)]),
            "{$this->amox->sku} is a prescription medicine: choose or add the patient.",
        );
        $this->assertRefused(
            fn () => $this->ring(lines: [new CartLine($this->morphine->getKey(), 1)]),
            "{$this->morphine->sku} is a prescription medicine: choose or add the patient.",
        );
        $this->assertNotNull($this->ring($this->newPatient(), [new CartLine($this->amox->getKey(), 1)])->patient_id);

        $offline = $this->ring(lines: [new CartLine($this->amox->getKey(), 1, tillUnitPrice: 2_000)], offline: true);
        $this->assertSame([['type' => 'patient_missing', 'message' => "{$this->amox->sku} is a prescription medicine and was sold without the patient."]], $offline->review_reasons);
    }

    public function test_off_never_asks_but_still_records_a_patient_the_till_sends(): void
    {
        $this->setShopSetting('patient_on_sale', 'off');

        $this->assertNull($this->ring(lines: [new CartLine($this->amox->getKey(), 1)])->patient_id);
        $this->assertNotNull($this->ring($this->newPatient())->patient_id);
    }

    public function test_the_controlled_drug_register_takes_the_name_and_phone_as_before_or_from_the_patient(): void
    {
        foreach (['ask', 'off'] as $mode) {
            $this->setShopSetting('patient_on_sale', $mode);
            $this->setShopSetting('controlled_register', true);
            $line = [new CartLine($this->morphine->getKey(), 1, prescriber: 'Dr Silva')];

            $this->assertRefused(fn () => $this->ring(lines: $line), "Enter the patient's name for {$this->morphine->sku} (controlled drug).");

            // As before: the name and phone typed.
            $sale = $this->ring(lines: $line, customerName: 'Nimal Perera', customerPhone: '0771234567');
            $this->assertSame(['Nimal Perera', '0771234567', null], [$sale->customer_name, $sale->customer_phone, $sale->patient_id]);

            // The patient's name counts, and the phone is kept with it.
            $sale = $this->ring($this->newPatient('Kamala Fernando', 600), $line, customerPhone: '0719876543');
            $this->assertSame(['Kamala Fernando', '0719876543'], [$sale->customer_name, $sale->customer_phone]);
            $this->assertNotNull($sale->patient_id);
        }
    }

    public function test_an_unknown_patient_without_a_name_is_refused_online_and_flagged_offline(): void
    {
        $unknown = new PatientInput((string) Str::uuid());

        $this->assertRefused(fn () => $this->ring($unknown), "That patient was not found. Enter the patient's name.");

        $sale = $this->ring($unknown, offline: true);
        $this->assertNull($sale->patient_id);
        $this->assertSame('patient_unknown', $sale->review_reasons[0]['type']);
        $this->assertSame(0, Patient::query()->count());
    }

    public function test_the_checkout_endpoint_takes_the_patient(): void
    {
        $uuid = (string) Str::uuid();
        $payload = fn (array $patient): array => [
            'device' => $this->till->uuid,
            'uuid' => (string) Str::uuid(),
            'lines' => [['variant_id' => $this->panadol->getKey(), 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => '100']],
            'patient' => $patient,
        ];

        $this->actingAs($this->cashier)->postJson(route('pos.checkout'), $payload(['uuid' => 'nope', 'name' => 'X']))
            ->assertUnprocessable()->assertJsonValidationErrors('patient.uuid');
        $this->actingAs($this->cashier)->postJson(route('pos.checkout'), $payload(['uuid' => $uuid, 'age_months' => 1801]))
            ->assertUnprocessable()->assertJsonValidationErrors('patient.age_months');

        $this->actingAs($this->cashier)->postJson(route('pos.checkout'), $payload(['uuid' => $uuid, 'name' => 'Nimal Perera', 'age_months' => 8]))->assertOk();
        $this->actingAs($this->cashier)->postJson(route('pos.checkout'), $payload(['uuid' => $uuid]))->assertOk();

        $this->assertSame(1, Patient::query()->count());
        $this->assertSame([8, 8], Sale::query()->orderBy('id')->pluck('patient_age_months')->all());
        $this->assertSame(['Nimal Perera', 'Nimal Perera'], Sale::query()->orderBy('id')->pluck('customer_name')->all());
    }

    public function test_patient_age_is_typed_in_years_or_months(): void
    {
        $this->assertSame(408, PatientAge::parse('34'));
        $this->assertSame(408, PatientAge::parse(' 34 Y '));
        $this->assertSame(8, PatientAge::parse('8m'));
        $this->assertSame(8, PatientAge::parse('8 M'));
        $this->assertSame(0, PatientAge::parse('0'));
        $this->assertNull(PatientAge::parse(''));
        $this->assertNull(PatientAge::parse('abc'));
        $this->assertNull(PatientAge::parse('151'));
        $this->assertNull(PatientAge::parse('3.5'));

        $this->assertSame('8 m', PatientAge::format(8));
        $this->assertSame('23 m', PatientAge::format(23));
        $this->assertSame('2 y', PatientAge::format(24));
        $this->assertSame('34 y', PatientAge::format(413));
        $this->assertNull(PatientAge::format(null));
    }
}
