<?php

namespace Tests\Feature\Pos;

use App\Models\Device;
use App\Models\Patient;
use App\Models\ProductVariant;
use App\Services\Data\PatientInput;
use App\Services\PatientService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * Finding a patient at the till (docs/PHARMACY.md, branch 13): online by
 * name, offline in the till's own copy, which the catalog sync fills with
 * name and age only (never the notes) and keeps up to date.
 */
class PatientSearchTest extends BatchTestCase
{
    use WorksWithCheckout;

    private function patient(string $name, ?string $birthDate = null, ?string $notes = null): Patient
    {
        return Patient::query()->create(['uuid' => (string) Str::uuid(), 'name' => $name, 'birth_date' => $birthDate, 'notes' => $notes]);
    }

    private function catalog(?string $since = null): TestResponse
    {
        Feature::for($this->shop)->activate('module:offline_sync');
        $token = $this->till->issueSyncToken('Counter')->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->getJson(route('pos.sync.catalog', $since === null ? [] : ['since' => $since]))->assertOk();
    }

    public function test_the_till_finds_patients_by_every_word_typed(): void
    {
        $nimal = $this->patient('Nimal Perera', '1992-04-01', 'Allergic to penicillin');
        $this->patient('Kamala Perera', '2026-02-18');
        $this->patient('Sunil Silva');

        $response = $this->actingAs($this->cashier)->getJson(route('pos.patients', ['q' => 'perera nim']))->assertOk();
        $this->assertSame([[
            'uuid' => $nimal->uuid,
            'name' => 'Nimal Perera',
            'age_months' => 414,
            'age_on' => '2026-10-18',
            'last_visit' => null,
        ]], $response->json('patients'));
        $this->assertStringNotContainsString('penicillin', (string) $response->getContent());

        $this->assertSame(['Kamala Perera', 'Nimal Perera'], array_column($this->actingAs($this->cashier)->getJson(route('pos.patients', ['q' => 'PERERA']))->json('patients'), 'name'));
        $this->assertSame([], $this->actingAs($this->cashier)->getJson(route('pos.patients', ['q' => 'x']))->json('patients'));
        $this->assertSame([], $this->actingAs($this->cashier)->getJson(route('pos.patients', ['q' => '100%']))->json('patients'));
        $this->actingAs($this->cashier)->getJson(route('pos.patients'))->assertUnprocessable();
    }

    public function test_the_most_recent_visitors_come_first(): void
    {
        $this->patient('Nimal Perera');
        $recent = new PatientInput((string) Str::uuid(), 'Nimal Silva', 400);
        $panadol = $this->medicine();
        $this->receive($panadol, 'B1', '12/2029', 10);
        $this->ringUp($panadol, $recent);

        $found = $this->actingAs($this->cashier)->getJson(route('pos.patients', ['q' => 'nimal']))->json('patients');
        $this->assertSame(['Nimal Silva', 'Nimal Perera'], array_column($found, 'name'));
        $this->assertSame(['2026-10-18', null], array_column($found, 'last_visit'));
    }

    public function test_a_shop_without_the_pharmacy_module_has_no_patient_search(): void
    {
        Feature::for($this->shop)->deactivate('module:pharmacy');

        $this->actingAs($this->owner)->getJson(route('pos.patients', ['q' => 'nimal']))->assertNotFound();
        $this->assertNull($this->catalog()->json('patients'));
    }

    public function test_the_catalog_brings_every_patient_then_who_changed_without_notes(): void
    {
        $nimal = $this->patient('Nimal Perera', '1992-04-01', 'Allergic to penicillin');
        $this->patient('Baby Silva', '2026-02-18');

        $full = $this->catalog();
        $this->assertSame([
            ['uuid' => $nimal->uuid, 'name' => 'Nimal Perera', 'age_months' => 414, 'age_on' => '2026-10-18'],
            ['uuid' => Patient::query()->where('name', 'Baby Silva')->value('uuid'), 'name' => 'Baby Silva', 'age_months' => 8, 'age_on' => '2026-10-18'],
        ], $full->json('patients'));
        $this->assertStringNotContainsString('penicillin', (string) $full->getContent());
        $this->assertSame('ask', $full->json('shop.patientOnSale'));
        $this->assertFalse($full->json('shop.receipt.showPatientName'));
        $this->assertFalse($full->json('shop.receipt.showPatientAge'));

        $this->travel(5)->minutes();
        $cursor = $this->catalog()->json('cursor');
        $this->travel(5)->minutes();
        app(PatientService::class)->update($nimal, $this->owner, ['name' => 'Nimal K. Perera', 'birth_date' => '1992-04-01']);
        $added = $this->patient('Sunil Silva');

        $this->assertSame(['Nimal K. Perera', 'Sunil Silva'], array_column($this->catalog($cursor)->json('patients'), 'name'));
        $this->assertSame([$nimal->uuid, $added->uuid], array_column($this->catalog($cursor)->json('patients'), 'uuid'));
    }

    public function test_the_till_reads_the_shops_patient_setting(): void
    {
        $this->setShopSetting('patient_on_sale', 'rx');
        $this->setShopSetting('receipt_show_patient_name', true);

        $this->assertSame('rx', $this->catalog()->json('shop.patientOnSale'));
        $this->assertTrue($this->catalog()->json('shop.receipt.showPatientName'));

        $this->setShopSetting('patient_on_sale', 'nonsense');
        $this->assertSame('ask', $this->catalog()->json('shop.patientOnSale'));
    }

    private function ringUp(ProductVariant $variant, PatientInput $patient, ?Device $till = null): void
    {
        $this->actingAs($this->cashier)->postJson(route('pos.checkout'), [
            'device' => ($till ?? $this->till)->uuid,
            'uuid' => (string) Str::uuid(),
            'lines' => [['variant_id' => $variant->getKey(), 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => '100']],
            'patient' => ['uuid' => $patient->uuid, 'name' => $patient->name, 'age_months' => $patient->ageMonths],
        ])->assertOk();
    }
}
