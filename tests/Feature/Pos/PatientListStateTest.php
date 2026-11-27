<?php

namespace Tests\Feature\Pos;

use App\Filament\Resources\Patients\Pages\ListPatients;
use App\Filament\Resources\Patients\PatientResource;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithPatientVisits;

/**
 * The Patients list keeps its filters, search and sort in the user's
 * session (docs/PHARMACY.md, branch 21): opening a patient and coming back
 * by the sidebar or the breadcrumb (the list's bare URL) finds the list as
 * it was, and Reset filters clears it. Visits in WorksWithPatientVisits.
 */
class PatientListStateTest extends PharmacyTestCase
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

    public function test_filters_search_and_sort_survive_opening_a_patient_and_coming_back(): void
    {
        Livewire::test(ListPatients::class)
            ->filterTable('last_visit', ['range' => 'today'])
            ->filterTable('bill_type', ['value' => 'opd'])
            ->searchTable('nimal')
            ->sortTable('visits', 'desc')
            ->assertCanSeeTableRecords([$this->patients['nimal']])
            ->assertCountTableRecords(1);

        $this->get(PatientResource::getUrl('view', ['record' => $this->patients['nimal']]))->assertOk();

        // The sidebar and the breadcrumb open the list's bare URL.
        $this->get(PatientResource::getUrl())->assertOk();
        $page = Livewire::test(ListPatients::class)
            ->assertSet('tableFilters.last_visit.range', 'today')
            ->assertSet('tableFilters.bill_type.value', 'opd')
            ->assertSet('tableSearch', 'nimal')
            ->assertSet('tableSort', 'visits:desc')
            ->assertCountTableRecords(1)
            ->assertActionVisible('resetList');

        // The summary above the list follows the filters as restored.
        $this->assertSame('opd', $page->instance()->getWidgetData()['tableFilters']['bill_type']['value']);
        $this->assertSame('nimal', $page->instance()->getWidgetData()['tableSearch']);
    }

    public function test_filters_in_the_url_win_over_the_session(): void
    {
        Livewire::test(ListPatients::class)->filterTable('bill_type', ['value' => 'opd']);

        Livewire::withQueryParams(['filters' => ['bill_type' => ['value' => 'pharmacy']]])
            ->test(ListPatients::class)
            ->assertSet('tableFilters.bill_type.value', 'pharmacy');
    }

    public function test_reset_filters_clears_filters_search_and_sort_in_the_session_too(): void
    {
        Livewire::test(ListPatients::class)->assertActionHidden('resetList');

        Livewire::test(ListPatients::class)
            ->filterTable('bill_type', ['value' => 'opd'])
            ->searchTable('nimal')
            ->sortTable('visits', 'desc')
            ->callAction('resetList')
            ->assertSet('tableSearch', '')
            ->assertSet('tableSort', null)
            ->assertSet('tableFilters.bill_type.value', null)
            ->assertCountTableRecords(5)
            ->assertActionHidden('resetList');

        Livewire::test(ListPatients::class)
            ->assertSet('tableSearch', '')
            ->assertSet('tableSort', null)
            ->assertCountTableRecords(5);
    }

    public function test_the_session_is_the_users_own(): void
    {
        Livewire::test(ListPatients::class)->filterTable('bill_type', ['value' => 'opd']);

        $this->post(route('filament.admin.auth.logout'));
        $this->actingAs($this->owner);

        Livewire::test(ListPatients::class)->assertCountTableRecords(5);
    }
}
