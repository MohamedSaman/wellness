<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Filament\Pages\Reports\ShiftHistoryReport;
use App\Filament\Pages\ShiftPatients;
use App\Filament\Resources\Patients\Pages\ListPatients;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use App\Services\PatientListService;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithPatientVisits;

/**
 * Patients in a shift, in the admin panel (docs/PHARMACY.md, branch 16):
 * the shift's bills with a patient (voided ones marked), from Shift
 * history's Patients column and the Patients list's shift filter;
 * dispensing_register.view, pharmacy shops only.
 */
class ShiftPatientsTest extends PharmacyTestCase
{
    use WorksWithCheckout;
    use WorksWithPatientVisits;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->seedVisits();
    }

    public function test_the_page_lists_the_shifts_bills_with_a_patient_only(): void
    {
        $this->actingAs($this->owner);

        $this->get(ShiftPatients::getUrl(['shift' => $this->shiftB]))
            ->assertOk()
            ->assertSee('Patients in this shift')
            ->assertSee('T1 · 18/10/2026 00:05 · '.$this->cashier->name.' · open');

        Livewire::test(ShiftPatients::class, ['shift' => $this->shiftB])
            ->assertCanSeeTableRecords([$this->bills['nimal_gen'], $this->bills['rani']], inOrder: true)
            ->assertCanNotSeeTableRecords([$this->bills['kamala'], $this->bills['nimal_opd'], $this->bills['nimal_oct']])
            ->assertSee('Nimal Perera')
            ->assertSee($this->bills['nimal_gen']->invoice_no)
            ->assertSee('Voided')
            ->assertSee('34 y');

        Livewire::test(ShiftPatients::class, ['shift' => $this->shiftC])
            ->assertCanSeeTableRecords([$this->bills['nimal_opd']])
            ->assertCountTableRecords(1)
            ->assertSee('OPD')
            ->assertSee('T2 · 18/10/2026 08:00 · '.$this->doctor->name.' · closed at 18/10/2026 09:15');
    }

    public function test_bills_without_a_patient_are_counted_under_the_heading(): void
    {
        $this->at('2026-10-18 09:40');
        app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->till,
            cashier: $this->cashier,
            lines: [new CartLine($this->labTest->getKey(), 1)],
            payments: [new PaymentInput(PaymentMethod::Cash, 100_000)],
            uuid: (string) Str::uuid(),
        ));
        $this->actingAs($this->owner);

        Livewire::test(ShiftPatients::class, ['shift' => $this->shiftB])
            ->assertCountTableRecords(2)
            ->assertSee('1 bill with no patient recorded');
    }

    public function test_only_who_may_see_patients_in_a_pharmacy_shop(): void
    {
        $this->actingAs($this->cashier);
        $this->get(ShiftPatients::getUrl(['shift' => $this->shiftB]))->assertForbidden();

        $this->actingAs($this->owner);
        $this->get('/admin/shifts/'.Str::uuid().'/patients')->assertNotFound();

        Feature::for($this->shop)->deactivate('module:pharmacy');
        $this->assertFalse(ShiftPatients::canAccess());
    }

    public function test_shift_history_counts_each_shifts_patients_and_links_to_them(): void
    {
        $this->actingAs($this->owner);

        $page = Livewire::withQueryParams(['range' => 'this_month'])->test(ShiftHistoryReport::class)
            ->assertSee('Patients')
            ->assertSee(ShiftPatients::getUrl(['shift' => $this->shiftA]), false);

        $rows = collect($page->instance()->document()->tables[0]->rows)->keyBy('shift_id');
        $this->assertSame(1, $rows[$this->shiftA->getKey()]['patients']);
        $this->assertSame(2, $rows[$this->shiftB->getKey()]['patients']); // Nimal, and Rani's voided bill
        $this->assertSame(1, $rows[$this->shiftC->getKey()]['patients']);
        $this->assertContains('Patients', array_map(fn ($column) => $column->label, $page->instance()->document()->tables[0]->columns));
        $this->assertSame([], app(PatientListService::class)->patientsPerShift([]));
    }

    public function test_the_patients_list_opens_the_shift_it_is_filtered_by(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ListPatients::class)
            ->assertActionHidden('shiftPatients')
            ->filterTable('shift', ['value' => (string) $this->shiftA->getKey()])
            ->assertActionVisible('shiftPatients')
            ->assertActionHasUrl('shiftPatients', ShiftPatients::getUrl(['shift' => $this->shiftA]))
            ->filterTable('shift', ['value' => 'open'])
            ->assertActionHidden('shiftPatients');
    }
}
