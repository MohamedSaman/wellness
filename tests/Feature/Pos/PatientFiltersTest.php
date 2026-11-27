<?php

namespace Tests\Feature\Pos;

use App\Filament\Resources\Patients\Pages\ListPatients;
use App\Models\Patient;
use App\Services\Data\PatientFilters;
use App\Services\PatientListService;
use App\Support\ReportPeriod;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithPatientVisits;

/**
 * The Patients list's filters and columns (docs/PHARMACY.md, branch 16):
 * last visit on the shop's days (a patient who visited in the period),
 * location visited, bill type (both = an OPD and a pharmacy bill), the
 * minimum visits in the period or all time, the shift (or every open
 * shift), all on the same bills; voided bills are no visit. The visits
 * are in WorksWithPatientVisits.
 */
class PatientFiltersTest extends PharmacyTestCase
{
    use WorksWithCheckout;
    use WorksWithPatientVisits;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedVisits();
    }

    /** @return array<string, int> name => visits, by name */
    private function listed(PatientFilters $filters): array
    {
        return app(PatientListService::class)->query($filters)->orderBy('name')->get()
            ->mapWithKeys(fn (Patient $patient): array => [$patient->name => (int) $patient->getAttribute('visits')])
            ->all();
    }

    private function preset(string $key): ReportPeriod
    {
        return ReportPeriod::preset($this->shop->fresh(), $key) ?? throw new \LogicException($key);
    }

    public function test_unfiltered_every_patient_is_listed_with_their_visits_and_voided_bills_left_out(): void
    {
        $this->assertSame([
            'Kamala Perera' => 1,
            'Nimal Perera' => 3,
            'Rani Silva' => 0,
            'Sunil Fernando' => 1,
            'Zara Khan' => 0,
        ], $this->listed(new PatientFilters));
    }

    public function test_the_columns_first_and_last_visit_last_location_and_bills(): void
    {
        $rows = app(PatientListService::class)->query(new PatientFilters)->get()->keyBy('name');

        $nimal = $rows['Nimal Perera'];
        $this->assertSame('2026-10-01 04:30:00', substr((string) $nimal->getAttribute('first_visit_at'), 0, 19));
        $this->assertSame('2026-10-18 03:30:00', substr((string) $nimal->getAttribute('last_visit_at'), 0, 19));
        $this->assertSame("Doctor's Medicines", $nimal->getAttribute('last_location'));
        $this->assertSame(3, (int) $nimal->getAttribute('sales_count'));

        // A voided bill is no visit, but still one of the patient's bills.
        $rani = $rows['Rani Silva'];
        $this->assertNull($rani->getAttribute('first_visit_at'));
        $this->assertNull($rani->getAttribute('last_visit_at'));
        $this->assertNull($rani->getAttribute('last_location'));
        $this->assertSame(1, (int) $rani->getAttribute('sales_count'));
    }

    public function test_last_visit_presets_are_the_shops_days(): void
    {
        // Kamala's 23:30 and Nimal's 00:10 are the same UTC day, but not in Colombo.
        $this->assertSame(['Nimal Perera' => 2], $this->listed(new PatientFilters(period: $this->preset('today'))));
        $this->assertSame(['Kamala Perera' => 1], $this->listed(new PatientFilters(period: $this->preset('yesterday'))));
        $this->assertSame(['Kamala Perera' => 1, 'Nimal Perera' => 2], $this->listed(new PatientFilters(period: $this->preset('this_week'))));
        $this->assertSame(['Kamala Perera' => 1, 'Nimal Perera' => 3], $this->listed(new PatientFilters(period: $this->preset('this_month'))));
    }

    public function test_a_custom_range_includes_both_days_and_may_be_open_ended(): void
    {
        $shop = $this->shop->fresh();

        $this->assertSame(['Sunil Fernando' => 1], $this->listed(new PatientFilters(period: PatientFilters::period($shop, 'custom', '2026-09-20', '2026-09-20'))));
        $this->assertSame(['Kamala Perera' => 1, 'Nimal Perera' => 1, 'Sunil Fernando' => 1], $this->listed(new PatientFilters(period: PatientFilters::period($shop, 'custom', null, '2026-10-17'))));
        $this->assertSame(['Kamala Perera' => 1, 'Nimal Perera' => 2], $this->listed(new PatientFilters(period: PatientFilters::period($shop, 'custom', '2026-10-17', null))));
        // Longer than a report's year is fine for a list.
        $this->assertSame('2023-10-19', PatientFilters::period($shop, 'custom', '2023-10-19', '2026-10-18')?->fromDate());
        $this->assertNull(PatientFilters::period($shop, 'custom', null, null));
        $this->assertNull(PatientFilters::period($shop, 'custom', 'not a date', '2026-02-31'));
    }

    public function test_location_visited(): void
    {
        $this->assertSame(['Nimal Perera' => 1], $this->listed(new PatientFilters(locationId: (int) $this->doctors->getKey())));
        $this->assertSame(['Kamala Perera' => 1, 'Nimal Perera' => 2, 'Sunil Fernando' => 1], $this->listed(new PatientFilters(locationId: (int) $this->general->getKey())));
    }

    public function test_bill_type_opd_pharmacy_or_both(): void
    {
        $this->assertSame(['Nimal Perera' => 1], $this->listed(new PatientFilters(billType: PatientFilters::BILL_OPD)));
        $this->assertSame(['Kamala Perera' => 1, 'Nimal Perera' => 2, 'Sunil Fernando' => 1], $this->listed(new PatientFilters(billType: PatientFilters::BILL_PHARMACY)));
        $this->assertSame(['Nimal Perera' => 3], $this->listed(new PatientFilters(billType: PatientFilters::BILL_BOTH)));
        // Both, within the period: Nimal had only a pharmacy bill on the 1st.
        $this->assertSame([], $this->listed(new PatientFilters(period: PatientFilters::period($this->shop->fresh(), 'custom', '2026-10-01', '2026-10-17'), billType: PatientFilters::BILL_BOTH)));
    }

    public function test_minimum_visits_all_time_or_in_the_period(): void
    {
        $this->assertSame(['Nimal Perera' => 3], $this->listed(new PatientFilters(minVisits: 3)));
        $this->assertSame(['Kamala Perera' => 1, 'Nimal Perera' => 3, 'Sunil Fernando' => 1], $this->listed(new PatientFilters(minVisits: 1)));
        $this->assertSame(['Nimal Perera' => 2], $this->listed(new PatientFilters(period: $this->preset('today'), minVisits: 2)));
        $this->assertSame([], $this->listed(new PatientFilters(period: $this->preset('today'), minVisits: 3)));
    }

    public function test_shift_filter_one_shift_or_every_open_shift(): void
    {
        $this->assertSame(['Kamala Perera' => 1], $this->listed(new PatientFilters(shift: (int) $this->shiftA->getKey())));
        $this->assertSame(['Nimal Perera' => 1], $this->listed(new PatientFilters(shift: (int) $this->shiftC->getKey())));
        // Shift B is the only open one: Nimal's 00:10 bill (Rani's was voided).
        $this->assertSame(['Nimal Perera' => 1], $this->listed(new PatientFilters(shift: PatientFilters::OPEN_SHIFTS)));
    }

    public function test_filters_combine_on_the_same_bill(): void
    {
        $today = $this->preset('today');

        $this->assertSame(['Nimal Perera' => 1], $this->listed(new PatientFilters(period: $today, locationId: (int) $this->general->getKey())));
        // Nimal had an OPD bill today and a General Pharmacy bill today, but not one bill that was both.
        $this->assertSame([], $this->listed(new PatientFilters(period: $today, locationId: (int) $this->general->getKey(), billType: PatientFilters::BILL_OPD)));
        $this->assertSame(['Nimal Perera' => 2], $this->listed(new PatientFilters(period: $this->preset('this_month'), billType: PatientFilters::BILL_PHARMACY, minVisits: 2)));
        $this->assertSame([], $this->listed(new PatientFilters(period: $this->preset('yesterday'), shift: (int) $this->shiftC->getKey())));
    }

    public function test_the_table_state_is_read_safely(): void
    {
        $shop = $this->shop->fresh();

        $filters = PatientFilters::fromTableState($shop, [
            'last_visit' => ['range' => 'yesterday'],
            'location' => ['value' => (string) $this->general->getKey()],
            'bill_type' => ['value' => 'both'],
            'visits' => ['min' => '3'],
            'shift' => ['value' => 'open'],
        ]);
        $this->assertSame('2026-10-17', $filters->period?->fromDate());
        $this->assertSame((int) $this->general->getKey(), $filters->locationId);
        $this->assertSame('both', $filters->billType);
        $this->assertSame(3, $filters->minVisits);
        $this->assertSame('open', $filters->shift);

        $junk = PatientFilters::fromTableState($shop, [
            'last_visit' => ['range' => 'forever'],
            'location' => ['value' => 'x'],
            'bill_type' => ['value' => 'retail'],
            'visits' => ['min' => '0'],
            'shift' => ['value' => '-4'],
            'other' => 'x',
        ]);
        $this->assertFalse($junk->isFiltered());
    }

    public function test_the_list_page_filters_from_the_url_and_shows_what_is_applied(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);

        Livewire::withQueryParams(['filters' => ['last_visit' => ['range' => 'today'], 'bill_type' => ['value' => 'pharmacy']]])
            ->test(ListPatients::class)
            ->assertCanSeeTableRecords([$this->patients['nimal']])
            ->assertCanNotSeeTableRecords([$this->patients['kamala'], $this->patients['sunil'], $this->patients['rani'], $this->patients['zara']])
            ->assertSee('Visited: Today (18/10/2026)')
            ->assertSee('Bill type: Pharmacy')
            ->assertSee('Doctor&#039;s Medicines', false);

        // Query parameters stay for the next component under test, and the filters in the session (branch 21).
        $this->flushSession();
        Livewire::withQueryParams([])->test(ListPatients::class)
            ->filterTable('shift', ['value' => (string) $this->shiftA->getKey()])
            ->assertCanSeeTableRecords([$this->patients['kamala']])
            ->assertCanNotSeeTableRecords([$this->patients['nimal']])
            ->assertSee('Shift: T1 · 17/10/2026 22:00 · '.$this->cashier->name.' · closed');

        $this->flushSession();
        Livewire::test(ListPatients::class)
            ->filterTable('visits', ['min' => 3])
            ->searchTable('nimal')
            ->assertCanSeeTableRecords([$this->patients['nimal']])
            ->assertCountTableRecords(1)
            ->sortTable('visits', 'desc')
            ->assertSuccessful();
    }

    public function test_the_shift_choices_name_till_opening_cashier_and_state(): void
    {
        $options = app(PatientListService::class)->shiftOptions();

        $this->assertSame([
            'open' => 'Open shifts now',
            (string) $this->shiftC->getKey() => 'T2 · 18/10/2026 08:00 · '.$this->doctor->name.' · closed',
            (string) $this->shiftB->getKey() => 'T1 · 18/10/2026 00:05 · '.$this->cashier->name.' · open',
            (string) $this->shiftA->getKey() => 'T1 · 17/10/2026 22:00 · '.$this->cashier->name.' · closed',
        ], $options);
        $this->assertSame(['open', (int) $this->shiftC->getKey()], array_keys(app(PatientListService::class)->shiftOptions('T2')));
    }
}
