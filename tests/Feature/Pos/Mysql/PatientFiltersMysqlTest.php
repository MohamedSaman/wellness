<?php

namespace Tests\Feature\Pos\Mysql;

use App\Models\Patient;
use App\Models\Shift;
use App\Services\Data\PatientFilters;
use App\Services\PatientListService;
use App\Services\PatientService;
use App\Services\PatientSummaryService;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithPatientVisits;

/**
 * The patient filters on MySQL (docs/PHARMACY.md, branch 16): the shop's
 * days, the grouped visits under ONLY_FULL_GROUP_BY, "both" and the
 * minimum, the shift filter and case-insensitive search. With
 * POS_PATIENTS_BENCH=1, a year of clinic bills (POS_PATIENTS_BENCH_BILLS,
 * about 33,000 by default, for about 8,000 patients, two shifts a day at
 * each of two tills) and the list's first page and count timed for every
 * filter and a combination against POS_PATIENTS_BENCH_MAX_SECONDS (1 by
 * default); EXPLAIN shows no full scan of sales and each patient's
 * subqueries read by patient.
 */
#[Group('mysql')]
class PatientFiltersMysqlTest extends MysqlTestCase
{
    use WorksWithCheckout;
    use WorksWithPatientVisits;

    /** @return array<string, int> */
    private function listed(PatientFilters $filters): array
    {
        return app(PatientListService::class)->query($filters)->orderBy('name')->get()
            ->mapWithKeys(fn (Patient $patient): array => [$patient->name => (int) $patient->getAttribute('visits')])
            ->all();
    }

    public function test_the_filters_on_mysql(): void
    {
        $this->seedVisits();
        $shop = $this->shop->fresh();
        $preset = fn (string $key): ReportPeriod => ReportPeriod::preset($shop, $key) ?? throw new \LogicException($key);

        $this->assertSame(['Kamala Perera' => 1, 'Nimal Perera' => 3, 'Rani Silva' => 0, 'Sunil Fernando' => 1, 'Zara Khan' => 0], $this->listed(new PatientFilters));
        $this->assertSame(['Nimal Perera' => 2], $this->listed(new PatientFilters(period: $preset('today'))));
        $this->assertSame(['Kamala Perera' => 1], $this->listed(new PatientFilters(period: $preset('yesterday'))));
        $this->assertSame(['Nimal Perera' => 3], $this->listed(new PatientFilters(billType: PatientFilters::BILL_BOTH)));
        $this->assertSame(['Nimal Perera' => 3], $this->listed(new PatientFilters(minVisits: 3)));
        $this->assertSame(['Nimal Perera' => 1], $this->listed(new PatientFilters(shift: PatientFilters::OPEN_SHIFTS)));
        $this->assertSame([], $this->listed(new PatientFilters(period: $preset('today'), locationId: (int) $this->general->getKey(), billType: PatientFilters::BILL_OPD)));

        $nimal = app(PatientListService::class)->query(new PatientFilters)->where('patients.name', 'Nimal Perera')->sole();
        $this->assertSame("Doctor's Medicines", $nimal->getAttribute('last_location'));
        $this->assertSame('2026-10-01 04:30:00', (string) $nimal->getAttribute('first_visit_at'));

        // The list's count (pagination) and sort on the derived columns.
        $this->assertSame(3, app(PatientListService::class)->query(new PatientFilters(minVisits: 1))->toBase()->getCountForPagination());
        $this->assertSame('Nimal Perera', app(PatientListService::class)->query(new PatientFilters)->orderByDesc('visits')->value('name'));

        $this->assertSame(['Nimal Perera'], Patient::query()->search('NIMAL')->pluck('name')->all());
        $this->assertSame([(int) $this->shiftA->getKey() => 1], app(PatientListService::class)->patientsPerShift([(int) $this->shiftA->getKey()]));
    }

    public function test_benchmark_a_year_of_patients(): void
    {
        if (! filter_var(getenv('POS_PATIENTS_BENCH'), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set POS_PATIENTS_BENCH=1 to time the patient filters on a year of data.');
        }

        $bills = (int) (getenv('POS_PATIENTS_BENCH_BILLS') ?: 33_000);
        $limit = (float) (getenv('POS_PATIENTS_BENCH_MAX_SECONDS') ?: 1.0);

        $started = microtime(true);
        [$openShift, $closedShift] = $this->seedAYear($bills);
        fwrite(STDERR, sprintf("\nSeeded %s bills, %s patients, %s shifts in %.1fs\n", number_format($bills), number_format(Patient::query()->count()), number_format(DB::table('shifts')->count()), microtime(true) - $started));

        $shop = $this->shop->fresh();
        $preset = fn (string $key): ReportPeriod => ReportPeriod::preset($shop, $key) ?? throw new \LogicException($key);
        $cases = [
            'no filter' => new PatientFilters,
            'today' => new PatientFilters(period: $preset('today')),
            'yesterday' => new PatientFilters(period: $preset('yesterday')),
            'this week' => new PatientFilters(period: $preset('this_week')),
            'this month' => new PatientFilters(period: $preset('this_month')),
            'a year' => new PatientFilters(period: PatientFilters::period($shop, 'custom', '2025-10-19', '2026-10-18')),
            'location' => new PatientFilters(locationId: (int) $this->doctors->getKey()),
            'OPD' => new PatientFilters(billType: PatientFilters::BILL_OPD),
            'both' => new PatientFilters(billType: PatientFilters::BILL_BOTH),
            'visits >= 3' => new PatientFilters(minVisits: 3),
            'one shift' => new PatientFilters(shift: $closedShift),
            'open shifts' => new PatientFilters(shift: PatientFilters::OPEN_SHIFTS),
            'month + GEN + pharmacy + >= 2' => new PatientFilters(period: $preset('this_month'), locationId: (int) $this->general->getKey(), billType: PatientFilters::BILL_PHARMACY, minVisits: 2),
        ];
        $slowest = [0.0, ''];

        foreach ($cases as $label => $filters) {
            foreach (['name', 'visits', 'last_visit_at'] as $sort) {
                $start = microtime(true);
                $query = app(PatientListService::class)->query($filters)->orderBy($sort === 'name' ? 'patients.name' : $sort, $sort === 'name' ? 'asc' : 'desc')->orderBy('patients.id');
                $count = (clone $query)->toBase()->getCountForPagination();
                $page = $query->limit(25)->get();
                $seconds = microtime(true) - $start;
                $slowest = $seconds > $slowest[0] ? [$seconds, "{$label} by {$sort}"] : $slowest;

                fwrite(STDERR, sprintf("%-34s %-14s %6s patients  first page + count %5.3fs\n", $label, "by {$sort}", number_format($count), $seconds));
                $this->assertLessThanOrEqual(25, $page->count());
                $this->assertIndexedReadsOfSales($query, "{$label} by {$sort}");
            }
        }

        $slowestSummary = [0.0, ''];
        $cases += [
            'until today' => new PatientFilters(period: PatientFilters::period($shop, 'custom', null, '2026-10-18')),
            'half a year' => new PatientFilters(period: PatientFilters::period($shop, 'custom', '2026-04-18', '2026-10-18')),
            'a year + both + >= 2' => new PatientFilters(period: PatientFilters::period($shop, 'custom', '2025-10-19', '2026-10-18'), billType: PatientFilters::BILL_BOTH, minVisits: 2),
            'a year + one shift' => new PatientFilters(period: PatientFilters::period($shop, 'custom', '2025-10-19', '2026-10-18'), shift: $closedShift),
        ];

        // The summary above the list (branch 20), with the money, alone and with a search that finds nearly everyone.
        foreach ($cases as $label => $filters) {
            foreach ([null, 'bench'] as $search) {
                $queries = [];
                DB::listen(function ($query) use (&$queries): void {
                    $queries[] = sprintf('%.3f', $query->time / 1000);
                });
                $start = microtime(true);
                $summary = app(PatientSummaryService::class)->summary($filters, $search, $this->owner);
                $seconds = microtime(true) - $start;
                $name = $label.($search === null ? '' : ' + search');
                $slowestSummary = $seconds > $slowestSummary[0] ? [$seconds, $name] : $slowestSummary;
                DB::getEventDispatcher()?->forget(QueryExecuted::class);

                fwrite(STDERR, sprintf("Summary %-40s %6s patients %6s visits  %5.3fs  (%s)\n", $name, number_format($summary->patients), number_format($summary->visits), $seconds, implode(' ', $queries)));
                $this->assertSame($summary->visits, $summary->opd + $summary->pharmacy);
                $this->assertSame($summary->patients, array_sum($summary->ages));
                $this->assertNotNull($summary->income);
            }
        }

        $start = microtime(true);
        $rows = app(PatientListService::class)->exportRows(app(PatientListService::class)->query(new PatientFilters)->orderBy('patients.name')->orderBy('patients.id'), $this->owner);
        fwrite(STDERR, sprintf("Export rows, every patient (%s): %.2fs\n", number_format(count($rows)), microtime(true) - $start));

        $start = microtime(true);
        $shiftBills = app(PatientListService::class)->shiftBills($openShift);
        fwrite(STDERR, sprintf("Till: patients this shift (%d bills): %.3fs\n", $shiftBills->count(), microtime(true) - $start));
        fwrite(STDERR, sprintf("Slowest: %s, %.3fs\n", $slowest[1], $slowest[0]));

        fwrite(STDERR, sprintf("Slowest summary: %s, %.3fs\n", $slowestSummary[1], $slowestSummary[0]));

        $this->assertLessThanOrEqual($limit, $slowest[0], "{$slowest[1]} took ".round($slowest[0], 3).'s.');
        $this->assertLessThanOrEqual($limit, $slowestSummary[0], "The summary of {$slowestSummary[1]} took ".round($slowestSummary[0], 3).'s.');
    }

    /**
     * No full scan of sales, and each patient's own subqueries (first and
     * last visit, last location, bills) read that patient's bills through
     * an index that starts with the patient, never the shop's by date.
     *
     * @param  Builder<Patient>  $query
     */
    private function assertIndexedReadsOfSales(Builder $query, string $label): void
    {
        $plan = DB::select('EXPLAIN '.$query->toSql(), $query->getBindings());

        foreach ($plan as $row) {
            if (($row->table ?? null) !== 'sales') {
                continue;
            }

            $this->assertNotSame('ALL', $row->type ?? null, "{$label}: a full scan of sales: ".json_encode($row));

            if (($row->select_type ?? null) === 'DEPENDENT SUBQUERY') {
                $this->assertContains($row->key ?? null, ['sales_patient_id_reported_at_index', 'sales_shop_id_patient_id_index'], "{$label}: a patient's bills not read by patient: ".json_encode($row));
            }
        }
    }

    /**
     * A year of bills at General Pharmacy (T1) and the OPD (T2, one bill in
     * five), each with a patient (a third come back often), in two shifts a
     * day per till, the last T1 shift still open; one bill in 200 voided.
     *
     * @return array{0: Shift, 1: int} the open shift, a closed shift's id
     */
    private function seedAYear(int $billCount): array
    {
        $this->seedVisits();
        $template = (array) DB::table('sales')->where('id', $this->bills['nimal_gen']->getKey())->first();
        unset($template['id']);
        $now = CarbonImmutable::now();
        $start = $now->subDays(364)->startOfDay();
        $tills = [['device' => (int) $this->till->getKey(), 'location' => (int) $this->general->getKey(), 'user' => (int) $this->cashier->getKey()],
            ['device' => (int) $this->doc->getKey(), 'location' => (int) $this->doctors->getKey(), 'user' => (int) $this->doctor->getKey()]];

        $shifts = [];
        $shiftRows = [];
        $nextShift = (int) DB::table('shifts')->max('id') + 1;

        for ($day = 0; $day < 365; $day++) {
            foreach ($tills as $t => $till) {
                foreach ([0, 1] as $half) {
                    $opened = $start->addDays($day)->addHours(2 + 8 * $half);
                    $closed = $opened->addHours(8);
                    $open = $day === 364 && $half === 0 && $t === 0; // the shift the clock is in
                    $shiftRows[] = ['id' => $nextShift, 'uuid' => (string) Str::uuid(), 'shop_id' => $this->shop->getKey(), 'device_id' => $till['device'], 'user_id' => $till['user'],
                        'opened_at' => $opened, 'closed_at' => $open ? null : $closed, 'opening_cash' => 0, 'created_at' => $opened, 'updated_at' => $opened];
                    $shifts[$t][$day][$half] = $nextShift++;
                }
            }
        }

        DB::table('shifts')->whereNull('closed_at')->update(['closed_at' => $now->subHours(12)]);
        foreach (array_chunk($shiftRows, 1_000) as $chunk) {
            DB::table('shifts')->insert($chunk);
        }

        // Neither a multiple of 3 nor of 5, so who comes back is not tied to which till.
        $patientCount = max(intdiv($billCount, 4), 10) + 1;
        $regulars = max(intdiv($patientCount, 3) + 3, 7);
        $patientRows = [];
        $firstPatient = (int) DB::table('patients')->max('id') + 1;

        for ($p = 0; $p < $patientCount; $p++) {
            $patientRows[] = ['id' => $firstPatient + $p, 'uuid' => (string) Str::uuid(), 'shop_id' => $this->shop->getKey(), 'name' => PatientService::formatName('bench patient '.$p),
                'birth_date' => $now->subYears(20 + $p % 60)->toDateString(), 'birth_date_estimated' => true, 'created_at' => $start, 'updated_at' => $start];
        }

        foreach (array_chunk($patientRows, 1_000) as $chunk) {
            DB::table('patients')->insert($chunk);
        }

        $rows = [];
        $span = (int) $start->diffInSeconds($now->subMinutes(5));

        for ($n = 0; $n < $billCount; $n++) {
            $t = $n % 5 === 0 ? 1 : 0;
            $at = $start->addSeconds((int) floor($n * $span / max($billCount, 1)));
            $day = min(364, (int) $start->diffInDays($at));
            $half = (int) $at->format('G') >= 10 ? 1 : 0;
            // A third of the patients make most of the visits.
            $patient = $firstPatient + ($n % 3 === 0 ? (7_919 * $n) % $patientCount : (7_919 * $n) % $regulars);
            $voided = $n % 200 === 199;

            $rows[] = [...$template, 'uuid' => (string) Str::uuid(), 'invoice_no' => 'PB-'.str_pad((string) $n, 7, '0', STR_PAD_LEFT),
                'device_id' => $tills[$t]['device'], 'location_id' => $tills[$t]['location'], 'user_id' => $tills[$t]['user'], 'shift_id' => $shifts[$t][$day][$half],
                'bill_type' => $t === 1 ? 'opd' : 'standard', 'patient_id' => $patient, 'customer_name' => null,
                'sold_at' => $at, 'reported_at' => $at, 'created_at' => $at, 'updated_at' => $at,
                'status' => $voided ? 'voided' : 'completed', 'voided_at' => $voided ? $at : null, 'voided_by' => $voided ? $this->owner->getKey() : null];

            if (count($rows) === 1_000) {
                DB::table('sales')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('sales')->insert($rows);
        }

        DB::statement('ANALYZE TABLE sales, patients, shifts');

        $this->seedLines();

        return [Shift::query()->whereNull('closed_at')->sole(), $shifts[0][300][0]];
    }

    /**
     * Ages at the visit (one patient in twenty without), and three lines a
     * bill: medicines (40 of them), and the consultation on OPD bills; one
     * bill in a hundred has a line refunded. A bill's total is its lines'.
     */
    private function seedLines(): void
    {
        $medicines = [];

        for ($m = 0; $m < 40; $m++) {
            $medicines[] = (int) $this->medicine('Bench medicine '.$m)->getKey();
        }

        $firstBill = (int) DB::table('sales')->where('invoice_no', 'like', 'PB-%')->min('id');
        $template = array_diff_key((array) DB::table('sale_items')->first(), array_flip(['id', 'sale_id', 'uuid', 'product_variant_id', 'line_total']));
        $columns = implode(', ', array_map(fn (string $column): string => "`{$column}`", array_keys($template)));
        $values = implode(', ', array_fill(0, count($template), '?'));
        $variant = 'ELT(1 + (sales.id * 3 + ?) % '.count($medicines).', '.implode(', ', $medicines).')';

        foreach ([0, 1, 2] as $line) {
            DB::insert(
                "INSERT INTO sale_items (sale_id, product_variant_id, line_total, {$columns}) SELECT sales.id, CASE WHEN ? = 0 AND sales.bill_type = 'opd' THEN ? ELSE {$variant} END, 20000, {$values} FROM sales WHERE sales.id >= ?",
                [$line, $this->consult->getKey(), $line, ...array_values($template), $firstBill],
            );
        }

        DB::update('UPDATE sales SET grand_total = 60000, refunded_total = CASE WHEN id % 100 = 0 THEN 20000 ELSE 0 END, patient_age_months = CASE WHEN patient_id % 20 = 0 THEN NULL ELSE (patient_id * 7) % 1000 END WHERE id >= ?', [$firstBill]);
        DB::insert("INSERT INTO sale_returns (uuid, shop_id, sale_id, user_id, return_no, refund_total, refund_method, returned_at, created_at, updated_at) SELECT UUID(), shop_id, id, user_id, CONCAT('PBR-', id), 20000, 'cash', sold_at, sold_at, sold_at FROM sales WHERE id >= ? AND id % 100 = 0", [$firstBill]);
        DB::insert("INSERT INTO sale_return_items (sale_return_id, sale_item_id, quantity, refund_amount, restocked, created_at, updated_at) SELECT r.id, MIN(i.id), 1, 20000, 1, r.created_at, r.created_at FROM sale_returns r JOIN sale_items i ON i.sale_id = r.sale_id WHERE r.return_no LIKE 'PBR-%' GROUP BY r.id, r.created_at");

        DB::statement('ANALYZE TABLE sales, sale_items, sale_returns, sale_return_items');
    }
}
