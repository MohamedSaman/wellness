<?php

namespace Tests\Feature\Pos;

use App\Models\Device;
use App\Models\Patient;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Services\OfflineSaleSyncService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * Patients added at a till while it was offline (docs/PHARMACY.md, branch
 * 13): the till makes the patient's uuid, so however many of their sales
 * are uploaded, by however many retries, the server creates the patient
 * once and links every sale to them. The sale keeps the name and age the
 * till printed.
 */
class PatientOfflineSyncTest extends BatchTestCase
{
    use WorksWithCheckout;

    private ProductVariant $panadol;

    private int $invoice = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->panadol = $this->medicine();
        $this->receive($this->panadol, 'B1', '12/2029', 100);
    }

    /**
     * @param  array<string, mixed>|null  $patient
     * @return array<string, mixed>
     */
    private function offlineSale(?array $patient, ?Device $till = null): array
    {
        $till ??= $this->till;
        $this->invoice++;

        return [
            'uuid' => (string) Str::uuid(),
            'invoice_no' => sprintf('%s-%06d', $till->code, 900_000 + $this->invoice),
            'sold_at' => now()->subMinutes(20)->toIso8601String(),
            'cashier_id' => $this->cashier->getKey(),
            'lines' => [['variant_id' => $this->panadol->getKey(), 'quantity' => 1, 'unit_price' => 2_000]],
            'payments' => [['method' => 'cash', 'amount' => 2_000]],
            'grand_total' => 2_000,
            ...($patient === null ? [] : ['patient' => $patient]),
        ];
    }

    /** @param  list<array<string, mixed>>  $sales */
    private function sync(array $sales, ?Device $till = null): TestResponse
    {
        $till ??= $this->till;
        app(OfflineSaleSyncService::class)->reserveInvoiceBlock($till);
        $this->app['auth']->forgetGuards();

        return $this->withToken($till->issueSyncToken('Counter browser')->plainTextToken)
            ->postJson(route('pos.sync.sales'), ['sales' => $sales, 'device_time' => now()->toIso8601String()]);
    }

    public function test_a_patient_added_offline_is_created_once_for_all_their_sales_and_retries(): void
    {
        $patient = ['uuid' => (string) Str::uuid(), 'name' => 'Nimal Perera', 'age_months' => 408];
        $first = $this->offlineSale($patient);
        $second = $this->offlineSale($patient);

        $this->sync([$first, $second])->assertOk()
            ->assertJsonPath('results.0.status', 'synced')
            ->assertJsonPath('results.1.status', 'synced');

        // The same upload again (the till never heard back), and the first sale once more.
        $this->sync([$first, $second, $first])->assertOk()
            ->assertJsonPath('results.0.status', 'duplicate')
            ->assertJsonPath('results.1.status', 'duplicate')
            ->assertJsonPath('results.2.status', 'duplicate');

        $record = Patient::query()->sole();
        $this->assertSame($patient['uuid'], $record->uuid);
        $this->assertSame('Nimal Perera', $record->name);
        $this->assertSame([$record->getKey(), $record->getKey()], Sale::query()->orderBy('id')->pluck('patient_id')->all());
        $this->assertSame([408, 408], Sale::query()->orderBy('id')->pluck('patient_age_months')->all());
        $this->assertSame(2, Sale::query()->count());
    }

    public function test_a_patient_added_offline_on_one_till_is_the_same_patient_on_another(): void
    {
        $patient = ['uuid' => (string) Str::uuid(), 'name' => 'Nimal Perera', 'age_months' => 408];
        $this->sync([$this->offlineSale($patient)])->assertOk();

        // The other till synced the patient into its copy, then sold to them offline (uppercase uuid, no age).
        $doc = $this->docTill(opd: false);
        $this->allowAt($this->cashier, $this->general, $this->doctors);
        $this->receive($this->panadol, 'D1', '12/2029', 10, $this->doctors);
        $this->sync([$this->offlineSale(['uuid' => strtoupper($patient['uuid']), 'name' => 'Nimal Perera'], $doc)], $doc)
            ->assertOk()->assertJsonPath('results.0.status', 'synced');

        $this->assertSame(1, Patient::query()->count());
        $this->assertSame(2, Patient::query()->sole()->sales()->count());
    }

    public function test_an_offline_sale_keeps_the_name_the_till_printed(): void
    {
        $patient = ['uuid' => (string) Str::uuid(), 'name' => 'Nimal Perera', 'age_months' => 408];
        $this->sync([$this->offlineSale($patient)])->assertOk();
        Patient::query()->sole()->forceFill(['name' => 'Nimal K. Perera'])->save();

        // A till that had not synced the new name yet.
        $this->sync([$this->offlineSale($patient)])->assertOk();

        $this->assertSame(['Nimal Perera', 'Nimal Perera'], Sale::query()->orderBy('id')->pluck('customer_name')->all());
        $this->assertSame('Nimal K. Perera', Patient::query()->sole()->name);
    }

    public function test_a_bad_patient_is_rejected_with_its_sale_only(): void
    {
        $this->sync([
            $this->offlineSale(['uuid' => 'not-a-uuid', 'name' => 'X']),
            $this->offlineSale(null),
        ])->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.1.status', 'synced');

        $this->assertSame(0, Patient::query()->count());
    }
}
