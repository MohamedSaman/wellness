<?php

namespace Tests\Feature\Pos;

use App\Models\Patient;
use App\Models\Sale;
use Illuminate\Support\Str;

/**
 * Patients are a pharmacy feature (docs/PHARMACY.md, branch 13): a retail
 * shop never records one, whatever a till sends, and its settings read the
 * defaults (nothing printed).
 */
class PatientRetailTest extends PosTestCase
{
    public function test_a_retail_sale_ignores_a_patient(): void
    {
        $this->actingAs($this->cashier)->postJson(route('pos.checkout'), [
            'device' => $this->till->uuid,
            'uuid' => (string) Str::uuid(),
            'lines' => [['variant_id' => $this->variant('CPR8EA-9')->getKey(), 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => '5000']],
            'customer_name' => 'Walk-in',
            'patient' => ['uuid' => (string) Str::uuid(), 'name' => 'Nimal Perera', 'age_months' => 408],
        ])->assertOk();

        $sale = Sale::query()->sole();
        $this->assertNull($sale->patient_id);
        $this->assertNull($sale->patient_age_months);
        $this->assertSame('Walk-in', $sale->customer_name);
        $this->assertSame(0, Patient::query()->count());
    }

    public function test_the_patient_settings_default_to_ask_and_nothing_printed(): void
    {
        $this->assertSame('ask', $this->shop->setting('patient_on_sale'));
        $this->assertFalse($this->shop->setting('receipt_show_patient_name'));
        $this->assertFalse($this->shop->setting('receipt_show_patient_age'));
    }
}
