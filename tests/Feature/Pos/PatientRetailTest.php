<?php

namespace Tests\Feature\Pos;

use App\Filament\Pages\Reports\ShiftHistoryReport;
use App\Filament\Pages\ShiftPatients;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Patients\Widgets\PatientsSummary;
use App\Models\Patient;
use App\Models\Sale;
use App\Services\ShiftService;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;

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

    public function test_shift_history_has_no_patients_column_and_no_shift_patients_page(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);
        app(ShiftService::class)->open($this->till, $this->cashier, 0);

        $document = Livewire::test(ShiftHistoryReport::class)->instance()->document();

        $this->assertNotContains('Patients', array_map(fn ($column) => $column->label, $document->tables[0]->columns));
        $this->assertArrayNotHasKey('patients', $document->tables[0]->rows[0]);
        $this->assertFalse(ShiftPatients::canAccess());
    }

    public function test_there_is_no_patients_list_and_no_summary(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);

        $this->assertFalse(PatientsSummary::canView());
        $this->get(PatientResource::getUrl())->assertForbidden();
        Livewire::test(PatientsSummary::class)->assertForbidden();
    }

    public function test_the_tills_patients_this_shift_is_not_there(): void
    {
        app(ShiftService::class)->open($this->till, $this->cashier, 0);

        $this->actingAs($this->cashier)
            ->getJson(route('pos.shift.patients', ['device' => $this->till->uuid]))
            ->assertNotFound();
    }

    public function test_the_patient_settings_default_to_ask_and_nothing_printed(): void
    {
        $this->assertSame('ask', $this->shop->setting('patient_on_sale'));
        $this->assertFalse($this->shop->setting('receipt_show_patient_name'));
        $this->assertFalse($this->shop->setting('receipt_show_patient_age'));
    }
}
