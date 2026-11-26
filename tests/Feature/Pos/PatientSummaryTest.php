<?php

namespace Tests\Feature\Pos;

use App\Enums\BillType;
use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Models\Patient;
use App\Models\Sale;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PatientFilters;
use App\Services\Data\PatientInput;
use App\Services\Data\PatientSummary;
use App\Services\Data\PaymentInput;
use App\Services\Data\ReturnLine;
use App\Services\PatientListService;
use App\Services\PatientSummaryService;
use App\Services\ReportService;
use App\Services\ReturnService;
use App\Services\ShiftService;
use App\Support\ReportPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Pos\Concerns\WorksWithBatches;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithPatientVisits;

/**
 * The figures above the Patients list (docs/PHARMACY.md, branch 20): every
 * one follows the list's filters and search on the same bills (voided ones
 * left out), new or returning, age groups by the age at the visit, the
 * chart's hours, days and months on the shop's clock, and the money only
 * with reports.view. The visits are in WorksWithPatientVisits (now is
 * 2026-10-18 10:00 in Colombo).
 */
class PatientSummaryTest extends PharmacyTestCase
{
    use WorksWithBatches;
    use WorksWithCheckout;
    use WorksWithPatientVisits;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedVisits();
    }

    private function summary(PatientFilters $filters = new PatientFilters, ?string $search = null, ?User $user = null): PatientSummary
    {
        return app(PatientSummaryService::class)->summary($filters, $search, $user);
    }

    private function preset(string $key): PatientFilters
    {
        return $this->filters(['last_visit' => ['range' => $key]]);
    }

    private function custom(?string $from, ?string $to): PatientFilters
    {
        return $this->filters(['last_visit' => ['range' => 'custom', 'from' => $from, 'to' => $to]]);
    }

    /** @param array<string, mixed> $state the table's filters */
    private function filters(array $state): PatientFilters
    {
        return PatientFilters::fromTableState($this->shop->fresh(), $state);
    }

    /** @return array<string, array{0: int, 1: int}> bar label => [OPD, pharmacy], only the bars with visits */
    private function bars(PatientSummary $summary): array
    {
        return collect($summary->timeline)
            ->filter(fn (array $bar): bool => $bar['opd'] + $bar['pharmacy'] > 0)
            ->mapWithKeys(fn (array $bar): array => [$bar['label'] => [$bar['opd'], $bar['pharmacy']]])
            ->all();
    }

    public function test_with_nothing_set_the_summary_is_todays_and_the_list_still_every_patient(): void
    {
        $summary = $this->summary();

        $this->assertSame('Today', $summary->title);
        $this->assertSame(1, $summary->patients);
        $this->assertSame(2, $summary->visits);
        $this->assertSame(2.0, $summary->average());
        $this->assertSame([0, 1], [$summary->new, $summary->returning]);
        $this->assertSame([1, 1], [$summary->opd, $summary->pharmacy]);
        $this->assertEqualsCanonicalizing([['location' => 'General Pharmacy', 'visits' => 1], ['location' => "Doctor's Medicines", 'visits' => 1]], $summary->locations);
        $this->assertSame(['child' => 0, 'adult' => 1, 'senior' => 0, 'unknown' => 0], $summary->ages);
        $this->assertSame(5, app(PatientListService::class)->query(new PatientFilters)->count());
    }

    public function test_periods_are_the_shops_days(): void
    {
        // Kamala's 23:30 and Nimal's 00:10 are the same UTC day, but not in Colombo.
        $yesterday = $this->summary($this->preset('yesterday'));
        $this->assertSame([1, 1], [$yesterday->patients, $yesterday->visits]);
        $this->assertSame(['23:00' => [0, 1]], $this->bars($yesterday));
        $this->assertSame(['child' => 0, 'adult' => 0, 'senior' => 1, 'unknown' => 0], $yesterday->ages);

        $today = $this->summary($this->preset('today'));
        $this->assertSame(['00:00' => [0, 1], '09:00' => [1, 0]], $this->bars($today));

        $week = $this->summary($this->preset('this_week'));
        $this->assertSame([2, 3], [$week->patients, $week->visits]);
        $this->assertSame(['17 Oct' => [0, 1], '18 Oct' => [1, 1]], $this->bars($week));

        $month = $this->summary($this->preset('this_month'));
        $this->assertSame([2, 4], [$month->patients, $month->visits]);
        $this->assertSame(2.0, $month->average());

        $custom = $this->summary($this->custom('2026-09-20', '2026-10-17'));
        $this->assertSame([3, 3], [$custom->patients, $custom->visits]);
    }

    public function test_new_and_returning_patients(): void
    {
        // Nimal's first visit was on 1 October: new in October, returning today and from the 2nd.
        $month = $this->summary($this->preset('this_month'));
        $this->assertSame([2, 0], [$month->new, $month->returning]);

        $later = $this->summary($this->custom('2026-10-02', '2026-10-18'));
        $this->assertSame([1, 1], [$later->new, $later->returning]);

        $shiftA = $this->summary(new PatientFilters(shift: (int) $this->shiftA->getKey()));
        $this->assertSame([1, 0], [$shiftA->new, $shiftA->returning]);
        $shiftC = $this->summary(new PatientFilters(shift: (int) $this->shiftC->getKey()));
        $this->assertSame([0, 1], [$shiftC->new, $shiftC->returning]);
        $open = $this->summary(new PatientFilters(shift: PatientFilters::OPEN_SHIFTS));
        $this->assertSame([0, 1], [$open->new, $open->returning]);

        // First seen at General Pharmacy on the 1st: not new to the clinic at the doctor's.
        $doctors = $this->summary($this->filters(['last_visit' => ['range' => 'today'], 'location' => ['value' => (string) $this->doctors->getKey()]]));
        $this->assertSame([0, 1], [$doctors->new, $doctors->returning]);
    }

    public function test_a_voided_earlier_bill_does_not_make_a_patient_returning(): void
    {
        $this->at('2026-10-20 09:00');
        $this->bill('Rani Silva');

        $summary = $this->summary($this->preset('today'));
        $this->assertSame([1, 0], [$summary->new, $summary->returning]);
    }

    public function test_an_offline_bill_dated_before_its_shift_opened_is_not_an_earlier_visit(): void
    {
        $this->bills['kamala']->forceFill(['reported_at' => $this->shiftA->opened_at->subHour()])->saveQuietly();

        $summary = $this->summary(new PatientFilters(shift: (int) $this->shiftA->getKey()));
        $this->assertSame([1, 0], [$summary->new, $summary->returning]);
    }

    public function test_new_and_returning_need_a_start(): void
    {
        foreach ([
            new PatientFilters(locationId: (int) $this->general->getKey()),
            new PatientFilters(billType: PatientFilters::BILL_PHARMACY),
            new PatientFilters(minVisits: 1),
            $this->custom(null, '2026-10-18'),
        ] as $filters) {
            $summary = $this->summary($filters);
            $this->assertNull($summary->new);
            $this->assertNull($summary->returning);
            $this->assertGreaterThan(0, $summary->patients);
        }
    }

    public function test_location_bill_type_and_minimum_follow_the_filters(): void
    {
        $doctors = $this->summary(new PatientFilters(locationId: (int) $this->doctors->getKey()));
        $this->assertSame([1, 1, 1, 0], [$doctors->patients, $doctors->visits, $doctors->opd, $doctors->pharmacy]);
        $this->assertSame([['location' => "Doctor's Medicines", 'visits' => 1]], $doctors->locations);

        $opd = $this->summary(new PatientFilters(billType: PatientFilters::BILL_OPD));
        $this->assertSame([1, 1, 1, 0], [$opd->patients, $opd->visits, $opd->opd, $opd->pharmacy]);

        $pharmacy = $this->summary(new PatientFilters(billType: PatientFilters::BILL_PHARMACY));
        $this->assertSame([3, 4, 0, 4], [$pharmacy->patients, $pharmacy->visits, $pharmacy->opd, $pharmacy->pharmacy]);

        $both = $this->summary(new PatientFilters(billType: PatientFilters::BILL_BOTH));
        $this->assertSame([1, 3, 1, 2], [$both->patients, $both->visits, $both->opd, $both->pharmacy]);

        $regulars = $this->summary(new PatientFilters(minVisits: 3));
        $this->assertSame([1, 3], [$regulars->patients, $regulars->visits]);

        // On the same bill: Nimal's OPD bill today was not at General Pharmacy.
        $none = $this->summary($this->filters(['last_visit' => ['range' => 'today'], 'location' => ['value' => (string) $this->general->getKey()], 'bill_type' => ['value' => 'opd']]));
        $this->assertSame([0, 0, null, []], [$none->patients, $none->visits, $none->average(), $none->timeline]);
        $this->assertSame(['child' => 0, 'adult' => 0, 'senior' => 0, 'unknown' => 0], $none->ages);
        $this->assertSame([0, 0], [$none->new, $none->returning]);
    }

    public function test_the_search_alone_covers_all_time_and_narrows_any_filter(): void
    {
        $found = $this->summary(search: ' nimal ');
        $this->assertSame('All time · Search: nimal', $found->title);
        $this->assertSame([1, 3], [$found->patients, $found->visits]);

        $perera = $this->summary($this->preset('this_month'), '"perera" kamala');
        $this->assertSame([1, 1], [$perera->patients, $perera->visits]);

        $this->assertSame(0, $this->summary(search: 'nobody')->patients);
        $this->assertSame('Today', $this->summary(search: '   ')->title);
    }

    public function test_voided_bills_are_no_visit(): void
    {
        // Rani's only bill, in shift B, was voided; Nimal's OPD bill is voided now.
        $this->assertSame(0, $this->summary(search: 'rani')->patients);

        app(CheckoutService::class)->void($this->bills['nimal_opd'], $this->owner, 'Wrong patient');
        $today = $this->summary(user: $this->owner);
        $this->assertSame([1, 1, 0, 1], [$today->patients, $today->visits, $today->opd, $today->pharmacy]);
        $this->assertSame(['services' => 50_000, 'medicines' => 0], $today->income);
    }

    public function test_every_filter_agrees_with_the_list(): void
    {
        $general = (int) $this->general->getKey();
        $cases = [
            $this->preset('today'), $this->preset('yesterday'), $this->preset('this_week'), $this->preset('this_month'),
            $this->custom(null, '2026-10-17'), $this->custom('2026-10-17', null),
            new PatientFilters(locationId: $general),
            new PatientFilters(billType: PatientFilters::BILL_OPD),
            new PatientFilters(billType: PatientFilters::BILL_PHARMACY),
            new PatientFilters(billType: PatientFilters::BILL_BOTH),
            new PatientFilters(minVisits: 1), new PatientFilters(minVisits: 2),
            new PatientFilters(shift: (int) $this->shiftA->getKey()),
            new PatientFilters(shift: PatientFilters::OPEN_SHIFTS),
            new PatientFilters(period: ReportPeriod::preset($this->shop->fresh(), 'this_month'), locationId: $general, billType: PatientFilters::BILL_PHARMACY, minVisits: 2),
            new PatientFilters(period: ReportPeriod::preset($this->shop->fresh(), 'this_month'), billType: PatientFilters::BILL_BOTH, minVisits: 3),
        ];

        foreach ($cases as $index => $filters) {
            foreach ([null, 'perera', 'kamala'] as $search) {
                $rows = app(PatientListService::class)->query($filters)->when($search !== null, fn ($query) => $query->search((string) $search))->get();
                $summary = $this->summary($filters, $search);

                $this->assertSame($rows->count(), $summary->patients, "case {$index}, {$search}");
                $this->assertSame((int) $rows->sum(fn (Patient $patient): int => (int) $patient->getAttribute('visits')), $summary->visits, "case {$index}, {$search}");
                $this->assertSame($summary->visits, array_sum(array_column($summary->locations, 'visits')));
                $this->assertSame($summary->visits, array_sum(array_column($summary->timeline, 'opd')) + array_sum(array_column($summary->timeline, 'pharmacy')));
                $this->assertSame($summary->patients, array_sum($summary->ages));
            }
        }
    }

    public function test_age_groups_are_by_the_age_at_the_latest_matching_visit(): void
    {
        // 143 and 719 months on the day: a child and an adult; 144 and 720 the next groups; no age is not known.
        $this->at('2026-09-01 09:00');
        $this->bill('Amal Dias', 143);
        $this->bill('Banu Raj', 144);
        $this->bill('Chitra Devi', 719);
        $this->bill('Dinesh Kumar', 720);
        $this->bill('Esha Nair');
        $day = fn (string $date): PatientFilters => $this->custom($date, $date);

        $this->assertSame(['child' => 1, 'adult' => 2, 'senior' => 1, 'unknown' => 1], $this->summary($day('2026-09-01'))->ages);

        // Two months on Amal is 12 and Chitra 60: counted once each, by the later visit, not the age today.
        $this->at('2026-11-02 09:00');
        $this->bill('Amal Dias');
        $this->bill('Chitra Devi');
        $this->assertSame(['child' => 0, 'adult' => 1, 'senior' => 1, 'unknown' => 0], $this->summary($day('2026-11-02'))->ages);
        $this->assertSame(['child' => 1, 'adult' => 2, 'senior' => 1, 'unknown' => 1], $this->summary($day('2026-09-01'))->ages);

        $search = $this->summary($this->custom('2026-09-01', '2026-11-02'), 'dias');
        $this->assertSame([1, 2], [$search->patients, $search->visits]);
        $this->assertSame(['child' => 0, 'adult' => 1, 'senior' => 0, 'unknown' => 0], $search->ages);
    }

    public function test_bars_are_hours_for_a_day_or_a_shift_even_over_midnight(): void
    {
        $today = $this->summary();
        $this->assertSame('hour', $today->unit);
        // From the first bill's hour to the last one's, the empty hours too.
        $this->assertSame(['00:00', '01:00', '02:00', '03:00', '04:00', '05:00', '06:00', '07:00', '08:00', '09:00'], array_column($today->timeline, 'label'));

        $this->at('2026-10-18 22:00');
        $night = app(ShiftService::class)->open($this->doc, $this->doctor, 0);
        $this->at('2026-10-18 23:40');
        $this->bill('Kamala Perera', till: $this->doc, cashier: $this->doctor, opd: true);
        $this->at('2026-10-19 01:20');
        $this->bill('Nimal Perera', till: $this->doc, cashier: $this->doctor, opd: true);
        $this->at('2026-10-19 02:00');
        app(ShiftService::class)->close($night, $this->doctor, 0);

        $summary = $this->summary(new PatientFilters(shift: (int) $night->getKey()));
        $this->assertSame('hour', $summary->unit);
        $this->assertSame(['18 Oct 23:00', '19 Oct 00:00', '19 Oct 01:00'], array_column($summary->timeline, 'label'));
        $this->assertSame(['18 Oct 23:00' => [1, 0], '19 Oct 01:00' => [1, 0]], $this->bars($summary));

        // The same shift on one of its days only.
        $monday = $this->summary($this->filters(['last_visit' => ['range' => 'today'], 'shift' => ['value' => (string) $night->getKey()]]));
        $this->assertSame(['01:00' => [1, 0]], $this->bars($monday));
    }

    public function test_bars_are_days_for_a_shift_left_open_for_days(): void
    {
        $this->at('2026-10-21 10:00');
        $this->bill('Kamala Perera');

        $summary = $this->summary(new PatientFilters(shift: PatientFilters::OPEN_SHIFTS));
        $this->assertSame('day', $summary->unit);
        $this->assertSame(['18 Oct', '19 Oct', '20 Oct', '21 Oct'], array_column($summary->timeline, 'label'));
        $this->assertSame(['18 Oct' => [0, 1], '21 Oct' => [0, 1]], $this->bars($summary));
    }

    public function test_bars_are_days_for_a_period_and_months_for_a_long_one(): void
    {
        $month = $this->summary($this->preset('this_month'));
        $this->assertSame('day', $month->unit);
        $this->assertCount(18, $month->timeline);
        $this->assertSame(['1 Oct' => [0, 1], '17 Oct' => [0, 1], '18 Oct' => [1, 1]], $this->bars($month));

        // A long period is cut to the days with bills (20 September to 18 October).
        $open = $this->summary($this->custom(null, '2026-10-18'));
        $this->assertSame('day', $open->unit);
        $this->assertCount(29, $open->timeline);
        $this->assertSame('Until 18/10/2026', $open->title);

        // 23:30 UTC on 31 May is 1 June in Colombo.
        $this->bills['sunil']->forceFill(['reported_at' => '2026-05-31 23:30:00'])->saveQuietly();
        $year = $this->summary($this->custom('2025-10-19', '2026-10-18'));
        $this->assertSame('month', $year->unit);
        $this->assertSame(['Jun 2026', 'Jul 2026', 'Aug 2026', 'Sep 2026', 'Oct 2026'], array_column($year->timeline, 'label'));
        $this->assertSame(['Jun 2026' => [0, 1], 'Oct 2026' => [1, 3]], $this->bars($year));
    }

    public function test_hours_and_days_are_right_where_the_clocks_change(): void
    {
        $this->shop->update(['timezone' => 'Europe/London']);
        // The clocks go back at 02:00 on 25 October 2026: 00:30 and 01:30 UTC are both 01:30.
        $this->bills['sunil']->forceFill(['reported_at' => '2026-10-25 00:30:00'])->saveQuietly();
        $this->bills['kamala']->forceFill(['reported_at' => '2026-10-25 01:30:00'])->saveQuietly();
        $this->bills['nimal_oct']->forceFill(['reported_at' => '2026-10-24 23:10:00'])->saveQuietly(); // 00:10 on the 25th
        $this->travelTo('2026-10-25 12:00:00');

        $day = $this->summary($this->preset('today'));
        $this->assertSame(['00:00' => [0, 1], '01:00' => [0, 2]], $this->bars($day));
        $month = $this->summary($this->preset('this_month'));
        $this->assertSame(3, $this->bars($month)['25 Oct'][1]);
    }

    public function test_the_title_says_what_is_set(): void
    {
        $title = fn (array $state): string => $this->summary($this->filters($state))->title;

        $this->assertSame("Today · Doctor's Medicines · Shift T2 08:00", $title([
            'last_visit' => ['range' => 'today'],
            'location' => ['value' => (string) $this->doctors->getKey()],
            'shift' => ['value' => (string) $this->shiftC->getKey()],
        ]));
        $this->assertSame('Shift T1 17/10/2026 22:00', $title(['shift' => ['value' => (string) $this->shiftA->getKey()]]));
        $this->assertSame('Open shifts now', $title(['shift' => ['value' => 'open']]));
        $this->assertSame('This month · OPD consultation · At least 2 visits', $title(['last_visit' => ['range' => 'this_month'], 'bill_type' => ['value' => 'opd'], 'visits' => ['min' => '2']]));
        $this->assertSame('01/10/2026 – 17/10/2026', $title(['last_visit' => ['range' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-17']]));
        $this->assertSame('All time · Pharmacy', $title(['bill_type' => ['value' => 'pharmacy']]));
    }

    public function test_the_money_is_net_of_refunds_and_split_as_the_reports_split_it(): void
    {
        $medicine = $this->medicine('Amoxil 250 mg Capsules');
        $this->receive($medicine, 'B1', '2028-01-01', 100);
        $sale = app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->till,
            cashier: $this->cashier,
            lines: [new CartLine($medicine->getKey(), 10), new CartLine($this->labTest->getKey(), 1)],
            payments: [new PaymentInput(PaymentMethod::Cash, 10_000_000)],
            uuid: (string) Str::uuid(),
            billType: BillType::Standard,
            patient: new PatientInput($this->patients['nimal']->uuid),
        ));
        $medicines = (int) $sale->items->firstWhere('product_variant_id', $medicine->getKey())->line_total;

        // Today: two lab tests (500.00 each), the consultation (1,500.00), the medicine. A bill without a patient is not counted.
        $this->assertGreaterThan(0, $medicines);
        $this->assertSame(['services' => 250_000, 'medicines' => $medicines], $this->summary(user: $this->owner)->income);

        $refund = app(ReturnService::class)->processLines($sale, [
            new ReturnLine($sale->items->firstWhere('product_variant_id', $medicine->getKey())->getKey(), 4),
            new ReturnLine($sale->items->firstWhere('product_variant_id', $this->labTest->getKey())->getKey(), 1),
        ], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);
        $left = $medicines - ($refund->refund_total - 50_000);
        $this->assertSame(['services' => 200_000, 'medicines' => $left], $this->summary(user: $this->owner)->income);

        // On a day whose refunds are of its own bills, all with a patient, the daily summary says the same.
        $counters = app(ReportService::class)->counters(ReportPeriod::today($this->shop->fresh()));
        $this->assertSame(200_000, array_sum(array_column($counters, 'services')));
        $this->assertSame($left, array_sum(array_column($counters, 'goods')));

        // Every filter and the search narrow it.
        $this->assertSame(['services' => 150_000, 'medicines' => 0], $this->summary(new PatientFilters(billType: PatientFilters::BILL_OPD), user: $this->owner)->income);
        $this->assertSame(['services' => 50_000, 'medicines' => 0], $this->summary(search: 'kamala', user: $this->owner)->income);
        $this->assertSame(['services' => 0, 'medicines' => 0], $this->summary(search: 'nobody', user: $this->owner)->income);
    }

    public function test_the_money_needs_reports_view_and_is_not_even_read_without_it(): void
    {
        $doctor = User::factory()->create(['shop_id' => $this->shop->getKey()]);
        $doctor->givePermissionTo('dispensing_register.view');

        DB::enableQueryLog();
        $this->assertNull($this->summary(user: $doctor)->income);
        $this->assertNull($this->summary()->income);
        $this->assertStringNotContainsString('sale_items', json_encode(DB::getQueryLog(), JSON_THROW_ON_ERROR));

        $doctor->givePermissionTo('reports.view');
        $this->assertNotNull($this->summary(user: $doctor->fresh())->income);
        $this->assertFalse($this->cashier->can('reports.view'));
    }

    public function test_bills_without_a_patient_are_not_counted(): void
    {
        $before = $this->summary(user: $this->owner);
        app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->till,
            cashier: $this->cashier,
            lines: [new CartLine($this->labTest->getKey(), 1)],
            payments: [new PaymentInput(PaymentMethod::Cash, 1_000_000)],
            uuid: (string) Str::uuid(),
        ));

        $this->assertSame(2, Sale::query()->where('reported_at', '>=', ReportPeriod::today($this->shop->fresh())->startUtc())->whereNotNull('patient_id')->where('status', '!=', 'voided')->count());
        $this->assertEquals($before, $this->summary(user: $this->owner));
    }

    public function test_a_shift_of_another_shop_or_no_open_shift_is_empty(): void
    {
        $this->assertSame(0, $this->summary(new PatientFilters(shift: 999_999))->patients);

        app(ShiftService::class)->close($this->shiftB, $this->cashier, 50_000);
        $open = $this->summary(new PatientFilters(shift: PatientFilters::OPEN_SHIFTS));
        $this->assertSame([0, 0, []], [$open->patients, $open->visits, $open->timeline]);
        $this->assertNull($open->new);
    }
}
