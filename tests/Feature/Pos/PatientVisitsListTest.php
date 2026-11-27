<?php

namespace Tests\Feature\Pos;

use App\Filament\Resources\Patients\Pages\ListPatients;
use App\Filament\Resources\Patients\Pages\ViewPatient;
use App\Filament\Resources\Patients\PatientResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Data\PatientFilters;
use App\Services\PatientSummaryService;
use App\Services\PatientVisitsService;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithPatientVisits;

/**
 * The Visits tab and the quick view on the Patients list
 * (docs/PHARMACY.md, branch 21): the same filters, search and summary as
 * the Patients tab, one row per bill, the tab and each tab's sort kept in
 * the session, the amount only with reports.view, the owner's audited
 * export, and the patient's bills in a panel over the list. Visits in
 * WorksWithPatientVisits (now is 2026-10-18 10:00 in Colombo).
 */
class PatientVisitsListTest extends PharmacyTestCase
{
    use WorksWithCheckout;
    use WorksWithPatientVisits;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->seedVisits();
        $this->actingAs($this->owner);
    }

    private function visitsTab(): Testable
    {
        return Livewire::withQueryParams(['tab' => ListPatients::VISITS])->test(ListPatients::class);
    }

    private function viewer(string ...$extra): User
    {
        $user = User::factory()->create(['shop_id' => $this->shop->getKey()]);
        $user->givePermissionTo(['admin.access', 'dispensing_register.view', ...$extra]);

        return $user;
    }

    public function test_the_visits_tab_lists_every_bill_with_a_patient_that_was_not_voided_newest_first(): void
    {
        $this->get(PatientResource::getUrl(parameters: ['tab' => 'visits']))->assertOk()->assertSee('Visits');

        $this->visitsTab()
            ->assertSet('activeTab', ListPatients::VISITS)
            ->assertCanSeeTableRecords([$this->bills['nimal_opd'], $this->bills['nimal_gen'], $this->bills['kamala'], $this->bills['nimal_oct'], $this->bills['sunil']], inOrder: true)
            ->assertCanNotSeeTableRecords([$this->bills['rani']])
            ->assertCountTableRecords(5)
            ->assertTableColumnStateSet('patient.name', 'Nimal Perera', $this->bills['nimal_opd'])
            ->assertTableColumnStateSet('kind', 'OPD', $this->bills['nimal_opd'])
            ->assertTableColumnStateSet('kind', 'Pharmacy', $this->bills['nimal_gen'])
            ->assertTableColumnStateSet('location.name', "Doctor's Medicines", $this->bills['nimal_opd'])
            ->assertTableColumnStateSet('cashier.name', $this->doctor->name, $this->bills['nimal_opd'])
            ->assertTableColumnStateSet('amount', $this->bills['nimal_gen']->grand_total, $this->bills['nimal_gen'])
            ->assertTableColumnFormattedStateSet('patient_age_months', '34 y', $this->bills['nimal_gen'])
            ->assertTableColumnFormattedStateSet('reported_at', '18/10/2026 00:10', $this->bills['nimal_gen'])
            // The consultation is no item given; the lab test is.
            ->assertTableColumnStateSet('medicines', null, $this->bills['nimal_opd'])
            ->assertTableColumnStateSet('medicines', ['Other: CBS ×1 pcs'], $this->bills['nimal_gen'])
            ->assertSee('Other: CBS ×1 pcs');
    }

    public function test_visits_sort_by_time_amount_and_patient(): void
    {
        $this->bills['kamala']->forceFill(['grand_total' => 90_000])->saveQuietly();

        $this->visitsTab()
            ->sortTable('reported_at', 'asc')
            ->assertCanSeeTableRecords([$this->bills['sunil'], $this->bills['nimal_oct'], $this->bills['kamala'], $this->bills['nimal_gen'], $this->bills['nimal_opd']], inOrder: true)
            ->sortTable('amount', 'desc')
            ->assertCanSeeTableRecords([$this->bills['nimal_opd'], $this->bills['kamala']], inOrder: true)
            ->sortTable('patient.name')
            ->assertCanSeeTableRecords([$this->bills['kamala'], $this->bills['nimal_opd'], $this->bills['nimal_gen'], $this->bills['nimal_oct'], $this->bills['sunil']], inOrder: true);
    }

    public function test_the_tabs_share_the_filters_and_search_and_the_summary_agrees_with_both(): void
    {
        $states = [
            [],
            ['last_visit' => ['range' => 'today']],
            ['last_visit' => ['range' => 'this_month']],
            ['bill_type' => ['value' => 'opd']],
            ['bill_type' => ['value' => 'both']],
            ['visits' => ['min' => '2']],
            ['location' => ['value' => (string) $this->general->getKey()]],
            ['shift' => ['value' => 'open']],
            ['shift' => ['value' => (string) $this->shiftA->getKey()]],
        ];

        foreach ($states as $state) {
            foreach ([null, 'nimal'] as $search) {
                $this->flushSession();
                $filters = PatientFilters::fromTableState($this->shop->fresh(), $state);
                $patients = Livewire::test(ListPatients::class, ['tableFilters' => $state, 'tableSearch' => $search ?? '']);
                $visits = $patients->set('activeTab', ListPatients::VISITS);
                $rows = $visits->instance()->getFilteredTableQuery()?->count();

                $listed = Livewire::test(ListPatients::class, ['tableFilters' => $state, 'tableSearch' => $search ?? ''])->set('activeTab', ListPatients::PATIENTS);
                $sum = (int) $listed->instance()->getFilteredTableQuery()?->get()->sum('visits');
                $label = json_encode($state).' '.$search;

                $this->assertSame($sum, $rows, $label);

                // The summary covers today when nothing is set: compare it where it covers what the list shows.
                if ($filters->isFiltered() || $search !== null) {
                    $this->assertSame(app(PatientSummaryService::class)->summary($filters, $search)->visits, $rows, $label);
                }
            }
        }
    }

    public function test_the_tab_and_each_tabs_sort_come_back_from_the_sidebar(): void
    {
        $this->visitsTab()
            ->filterTable('bill_type', ['value' => 'pharmacy'])
            ->sortTable('amount', 'desc')
            ->set('activeTab', ListPatients::PATIENTS)
            ->assertSet('tableSort', null)
            ->sortTable('visits', 'desc')
            ->set('activeTab', ListPatients::VISITS)
            ->assertSet('tableSort', 'amount:desc')
            ->assertCountTableRecords(4);

        $this->get(PatientResource::getUrl('view', ['record' => $this->patients['nimal']]))->assertOk();

        Livewire::test(ListPatients::class)
            ->assertSet('activeTab', ListPatients::VISITS)
            ->assertSet('tableSort', 'amount:desc')
            ->assertSet('tableFilters.bill_type.value', 'pharmacy')
            ->assertCountTableRecords(4)
            ->set('activeTab', ListPatients::PATIENTS)
            ->assertSet('tableSort', 'visits:desc')
            ->callAction('resetList')
            ->assertSet('tableSort', null)
            ->set('activeTab', ListPatients::VISITS)
            ->assertSet('tableSort', null)
            ->assertCountTableRecords(5);
    }

    public function test_the_amount_needs_reports_view_and_what_was_given_does_not(): void
    {
        $this->actingAs($this->viewer());

        $this->visitsTab()
            ->assertTableColumnHidden('amount')
            ->assertTableColumnVisible('medicines')
            ->assertSee('Other: CBS ×1 pcs')
            ->assertDontSee('Consultations &amp; services', false);

        $this->actingAs($this->viewer('reports.view'));
        $this->visitsTab()->assertTableColumnVisible('amount');
    }

    public function test_the_quick_view_shows_the_patients_bills_within_the_filters_and_is_audited(): void
    {
        $this->visitsTab()
            ->filterTable('last_visit', ['range' => 'today'])
            ->mountTableAction('quickView', $this->bills['nimal_opd'])
            ->assertMountedActionModalSee(['Nimal Perera', '34 y · Bills: 18/10/2026', $this->bills['nimal_opd']->invoice_no, $this->bills['nimal_gen']->invoice_no, 'CBS ×1 pcs', 'Open full page'])
            ->assertMountedActionModalDontSee($this->bills['nimal_oct']->invoice_no);

        $entry = AuditLog::query()->where('event', ViewPatient::VIEWED_EVENT)->sole();
        $this->assertSame((int) $this->patients['nimal']->getKey(), (int) $entry->auditable_id);
        $this->assertSameIgnoringKeyOrder(['name' => 'Nimal Perera', 'via' => 'quick view'], $entry->new_values);

        // From the Patients tab, with the filters of the moment (the query parameters stay between components under test).
        $this->flushSession();
        Livewire::withQueryParams([])->test(ListPatients::class)
            ->filterTable('bill_type', ['value' => 'opd'])
            ->mountTableAction('quickView', $this->patients['nimal'])
            ->assertMountedActionModalSee([$this->bills['nimal_opd']->invoice_no, 'Bills: OPD consultation'])
            ->assertMountedActionModalDontSee($this->bills['nimal_gen']->invoice_no);
    }

    public function test_the_quick_view_shows_the_amount_only_with_reports_view(): void
    {
        $this->actingAs($this->viewer());

        Livewire::test(ListPatients::class)
            ->mountTableAction('quickView', $this->patients['kamala'])
            ->assertMountedActionModalSee($this->bills['kamala']->invoice_no)
            ->assertMountedActionModalDontSee('500.00');

        $this->actingAs($this->owner);
        Livewire::test(ListPatients::class)
            ->mountTableAction('quickView', $this->patients['kamala'])
            ->assertMountedActionModalSee('500.00');
    }

    public function test_the_owner_exports_the_visits_with_every_item_and_it_is_audited(): void
    {
        $this->visitsTab()
            ->filterTable('last_visit', ['range' => 'today'])
            ->callAction('exportXlsx')
            ->assertFileDownloaded('patient_visits_2026-10-18.xlsx');

        $entry = AuditLog::query()->where('event', PatientVisitsService::EXPORTED_EVENT)->sole();
        $this->assertSame((int) $this->owner->getKey(), (int) $entry->user_id);
        $this->assertSameIgnoringKeyOrder([
            'format' => 'xlsx',
            'rows' => 2,
            'filters' => ['Visited' => '18/10/2026'],
            'from' => '2026-10-18',
            'to' => '2026-10-18',
        ], $entry->new_values);

        $this->visitsTab()->callAction('exportPdf')->assertFileDownloaded('patient_visits_2026-10-18.pdf');
        $this->assertSame(2, AuditLog::query()->where('event', PatientVisitsService::EXPORTED_EVENT)->count());
    }

    public function test_without_patients_export_there_is_no_export_of_the_visits(): void
    {
        $this->actingAs($this->viewer('reports.view'));

        $this->visitsTab()
            ->assertActionHidden('exportXlsx')
            ->assertActionHidden('exportPdf');
    }

    public function test_patients_rows_open_the_quick_view_and_open_goes_to_the_page(): void
    {
        Livewire::test(ListPatients::class)
            ->assertTableActionExists('quickView')
            ->assertTableActionHasUrl('open', PatientResource::getUrl('view', ['record' => $this->patients['nimal']]), $this->patients['nimal']);
    }

    public function test_a_closed_shift_of_the_doctor_lists_its_visit(): void
    {
        $this->visitsTab()
            ->filterTable('shift', ['value' => (string) $this->shiftC->getKey()])
            ->assertCanSeeTableRecords([$this->bills['nimal_opd']])
            ->assertCountTableRecords(1);
    }
}
