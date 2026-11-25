<?php

namespace Tests\Feature\Pos;

use App\Filament\Resources\Patients\Pages\ListPatients;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Patients\Widgets\PatientsSummary;
use App\Filament\Widgets\Summary\SummaryChart;
use App\Models\User;
use App\Services\ModuleService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithPatientVisits;

/**
 * The summary on the Patients list page (docs/PHARMACY.md, branch 20): it
 * follows the page's filters and search, says what it covers, shows the
 * money only with reports.view and is seen by whoever may see the list. The
 * figures themselves are PatientSummaryTest's.
 */
class PatientSummaryWidgetTest extends PharmacyTestCase
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

    /** @param array<string, mixed>|null $filters */
    private function widget(?array $filters = null, ?string $search = null): Testable
    {
        return Livewire::test(PatientsSummary::class, ['tableFilters' => $filters, 'tableSearch' => $search]);
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create(['shop_id' => $this->shop->getKey()]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    public function test_the_page_shows_todays_summary_above_every_patient(): void
    {
        $this->get(PatientResource::getUrl())->assertOk()->assertSeeLivewire(PatientsSummary::class);

        Livewire::withoutLazyLoading();
        Livewire::test(ListPatients::class)
            ->assertCountTableRecords(5)
            ->assertSee('Today')
            ->assertSeeHtml('data-test="patients-summary"')
            ->assertSeeInOrder(['Patients', 'Visits', 'Visits per patient', 'New / Returning', 'OPD / Pharmacy', 'By location', 'Child / Adult / Senior', 'Consultations &amp; services', 'Medicines', 'Visits over time', 'Age groups'], false);
    }

    public function test_the_page_hands_its_applied_filters_and_search_to_the_summary(): void
    {
        $page = Livewire::test(ListPatients::class)
            ->filterTable('bill_type', ['value' => 'opd'])
            ->filterTable('shift', ['value' => (string) $this->shiftC->getKey()])
            ->searchTable('nimal');

        $data = $page->instance()->getWidgetData();
        $this->assertSame(['tableFilters', 'tableSearch'], array_keys($data));
        $this->assertSame('nimal', $data['tableSearch']);

        $this->widget($data['tableFilters'], $data['tableSearch'])
            ->assertSee('OPD consultation · Shift T2 08:00 · Search: nimal')
            ->assertSee('New: first visit ever in this shift');
    }

    public function test_the_cards(): void
    {
        $html = $this->widget(['last_visit' => ['range' => 'this_month']])
            ->assertSee('This month')
            ->assertSee("General Pharmacy 3 · Doctor's Medicines 1")
            ->assertSee('25% OPD consultations · 75% pharmacy bills')
            ->assertSee('New: first visit ever in this period')
            ->html();

        foreach (['patients' => '2', 'visits' => '4', 'visits-per-patient' => '2.0', 'new-returning' => '2 · 0', 'opd-pharmacy' => '1 · 3', 'by-location' => '3 · 1', 'child-adult-senior' => '0 · 1 · 1'] as $card => $value) {
            $this->assertMatchesRegularExpression('/data-test="summary-'.$card.'".*?stat-value">\s*'.preg_quote($value, '/').'\s*</s', $html, $card);
        }

        $this->widget(['location' => ['value' => (string) $this->general->getKey()]])
            ->assertSee('All time · General Pharmacy')
            ->assertSee('Choose a period or a shift');
    }

    public function test_the_charts_get_the_bars(): void
    {
        $this->widget()
            ->assertSeeLivewire(SummaryChart::class)
            ->assertSee('Per hour')
            ->assertSee('00:00: Pharmacy 1; 09:00: OPD 1')
            ->assertSee('Adult (12–59): Patients 1');

        $this->widget(['last_visit' => ['range' => 'this_month']])->assertSee('Per day')->assertSee('17 Oct: Pharmacy 1; 18 Oct: OPD 1, Pharmacy 1');
        $this->widget(['last_visit' => ['range' => 'today']], 'nobody')->assertSee('No visits');
    }

    public function test_the_money_cards_and_their_note_need_reports_view(): void
    {
        $this->widget()->assertSee('Consultations & services')->assertSee('Medicines')->assertSee(PatientsSummary::MONEY_NOTE);
        $this->widget()->assertDontSee('Consultation fees');

        $this->actingAs($this->userWith('admin.access', 'dispensing_register.view'));
        DB::enableQueryLog();
        $this->widget()
            ->assertSee('Patients')
            ->assertDontSee('Consultations & services')
            ->assertDontSee(PatientsSummary::MONEY_NOTE);
        $this->assertStringNotContainsString('sale_items', json_encode(DB::getQueryLog(), JSON_THROW_ON_ERROR));
    }

    public function test_it_is_seen_by_whoever_may_see_the_list(): void
    {
        $this->actingAs($this->userWith('admin.access', 'dispensing_register.view'));
        $this->assertTrue(PatientsSummary::canView());

        $this->actingAs($this->userWith('admin.access', 'reports.view'));
        $this->assertFalse(PatientsSummary::canView());
        Livewire::test(PatientsSummary::class)->assertForbidden();

        $this->actingAs($this->owner);
        app(ModuleService::class)->disable($this->shop->fresh(), 'pharmacy');
        $this->assertFalse(PatientsSummary::canView());
    }

    public function test_the_fold_is_remembered_per_user(): void
    {
        $this->widget()->assertSee('patients-summary-'.$this->owner->getKey(), false)->assertSee('$persist', false);
    }
}
