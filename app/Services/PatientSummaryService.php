<?php

namespace App\Services;

use App\Enums\BillType;
use App\Enums\SaleStatus;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\Shop;
use App\Models\User;
use App\Services\Data\PatientFilters;
use App\Services\Data\PatientSummary;
use App\Support\CurrentShop;
use App\Support\PatientAge;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The summary above the Patients list (docs/PHARMACY.md, branch 20): the
 * list's own bills (PatientListService::bills(): the same filters, search
 * and rules, voided bills left out), added up in SQL. Three grouped
 * queries whatever the filters: the patients, the visits by time, kind of
 * bill and location, and the money. Read only.
 *
 * @phpstan-type Visits array{bucket: string, opd: bool, location_id: int, visits: int, total: int, refunded: int, first_at: string, last_at: string, first_id: int, last_id: int}
 */
class PatientSummaryService
{
    /** The chart is by the hour over a single day, or over shifts no longer than this. */
    public const HOURLY_MAX_HOURS = 48;

    /** The chart is by the day up to this many days; longer by the month. */
    public const DAILY_MAX_DAYS = 92;

    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly PatientListService $patients,
    ) {}

    /**
     * The figures for what the list shows; today's when nothing is filtered
     * or searched. The money only for a user with reports.view.
     */
    public function summary(PatientFilters $filters, ?string $search = null, ?User $user = null): PatientSummary
    {
        $shop = $this->currentShop->getOrFail();
        $search = trim((string) $search);
        $filters = $filters->orToday($shop, $search);
        $shifts = $this->patients->shiftsOf($filters);

        $people = $this->people($filters, $search, $shifts);
        $visits = $people['patients'] === 0 ? [] : $this->visits($shop, $filters, $search, $shifts);
        $first = $visits === [] ? null : CarbonImmutable::parse(min(array_column($visits, 'first_at')), 'UTC')->setTimezone($this->timezone($shop));
        $last = $visits === [] ? null : CarbonImmutable::parse(max(array_column($visits, 'last_at')), 'UTC')->setTimezone($this->timezone($shop));
        [$unit, $range] = $this->bars($shop, $filters, $shifts, $first, $last);

        return new PatientSummary(
            title: $this->title($filters, $search, $shifts),
            patients: $people['patients'],
            visits: array_sum(array_column($visits, 'visits')),
            new: $people['returning'] === null ? null : $people['patients'] - $people['returning'],
            returning: $people['returning'],
            opd: array_sum(array_column(array_filter($visits, fn (array $row): bool => $row['opd']), 'visits')),
            pharmacy: array_sum(array_column(array_filter($visits, fn (array $row): bool => ! $row['opd']), 'visits')),
            locations: $this->locations($visits),
            ages: $people['ages'],
            unit: $unit,
            timeline: $range === null || $first === null || $last === null ? [] : $this->timeline($visits, $range, $unit, $first, $last),
            income: $user?->can('reports.view') ? $this->income($filters, $search, $visits) : null,
        );
    }

    /**
     * What the summary covers, in words: the period, location, bill type,
     * minimum, shift and search set.
     *
     * @param  Collection<int, Shift>|null  $shifts
     */
    private function title(PatientFilters $filters, string $search, ?Collection $shifts): string
    {
        $timezone = $this->timezone($this->currentShop->getOrFail());
        $shift = is_int($filters->shift) ? $shifts?->first() : null;
        $opened = $shift === null ? null : CarbonImmutable::instance($shift->opened_at)->setTimezone($timezone);

        return implode(' · ', array_filter([
            match (true) {
                $filters->preset !== null => PatientFilters::PERIODS[$filters->preset],
                $filters->startsSomewhere() => $filters->period?->label(),
                $filters->period !== null => 'Until '.CarbonImmutable::parse($filters->period->toDate())->format('d/m/Y'),
                $filters->shift !== null => null,
                default => 'All time',
            },
            $filters->locationId === null ? null : (string) Location::query()->whereKey($filters->locationId)->value('name'),
            $filters->billType === null ? null : PatientFilters::BILL_TYPES[$filters->billType],
            $filters->minVisits === null ? null : 'At least '.$filters->minVisits.' '.($filters->minVisits === 1 ? 'visit' : 'visits'),
            match (true) {
                $filters->shift === PatientFilters::OPEN_SHIFTS => 'Open shifts now',
                $shift !== null && $opened !== null => 'Shift '.$shift->device?->code.' '.$opened->format($opened->isSameDay(CarbonImmutable::now($timezone)) ? 'H:i' : 'd/m/Y H:i'),
                default => null,
            },
            $search === '' ? null : 'Search: '.$search,
        ], fn (?string $part): bool => $part !== null && $part !== ''));
    }

    /**
     * The patients of the bills: how many, each one's age group at their
     * latest bill (the age kept on it), and how many had visited before the
     * period or the shift (any location, any bill; a voided one is no
     * visit).
     *
     * @param  Collection<int, Shift>|null  $shifts
     * @return array{patients: int, returning: int|null, ages: array{child: int, adult: int, senior: int, unknown: int}}
     */
    private function people(PatientFilters $filters, string $search, ?Collection $shifts): array
    {
        $ranked = $this->patients->bills($filters, $search)
            ->select('sales.patient_id', 'sales.patient_age_months')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY sales.patient_id ORDER BY sales.reported_at DESC, sales.id DESC) as latest');

        $latest = DB::query()
            ->fromSub($ranked, 'bills')
            ->where('bills.latest', 1)
            ->select('bills.patient_age_months');

        $earlier = $this->earlierVisit($filters, $shifts);

        if ($earlier !== null) {
            $latest->selectSub($earlier->limit(1), 'visited_before');
        }

        $query = DB::query()
            ->fromSub($latest, 'people')
            ->selectRaw('COUNT(*) as patients')
            ->selectRaw('SUM(CASE WHEN people.patient_age_months < ? THEN 1 ELSE 0 END) as child', [PatientAge::ADULT_FROM_MONTHS])
            ->selectRaw('SUM(CASE WHEN people.patient_age_months >= ? AND people.patient_age_months < ? THEN 1 ELSE 0 END) as adult', [PatientAge::ADULT_FROM_MONTHS, PatientAge::SENIOR_FROM_MONTHS])
            ->selectRaw('SUM(CASE WHEN people.patient_age_months >= ? THEN 1 ELSE 0 END) as senior', [PatientAge::SENIOR_FROM_MONTHS])
            ->selectRaw('SUM(CASE WHEN people.patient_age_months IS NULL THEN 1 ELSE 0 END) as unknown')
            ->when($earlier !== null, fn (Builder $query) => $query->selectRaw('COUNT(people.visited_before) as returning_patients'));

        $row = $query->first();

        return [
            'patients' => (int) ($row->patients ?? 0),
            'returning' => $earlier === null ? null : (int) ($row->returning_patients ?? 0),
            'ages' => [
                'child' => (int) ($row->child ?? 0),
                'adult' => (int) ($row->adult ?? 0),
                'senior' => (int) ($row->senior ?? 0),
                'unknown' => (int) ($row->unknown ?? 0),
            ],
        ];
    }

    /**
     * A visit of the outer row's patient (bills.patient_id) from before the
     * period or the shift (the earliest one, of several) began. Bills of
     * those shifts are never "before": an offline till's clock may date one
     * before its shift opened. Read by patient, so the shop's scope is left
     * off as in PatientListService. Null when nothing says where "before"
     * ends.
     *
     * @param  Collection<int, Shift>|null  $shifts
     */
    private function earlierVisit(PatientFilters $filters, ?Collection $shifts): ?Builder
    {
        $opened = $shifts?->min('opened_at');
        $starts = array_filter([
            $filters->startsSomewhere() ? $filters->period?->startUtc() : null,
            $opened === null ? null : CarbonImmutable::instance($opened)->utc()->format('Y-m-d H:i:s'),
        ]);

        if ($starts === []) {
            return null;
        }

        return DB::table('sales as earlier')
            ->selectRaw('1')
            ->whereColumn('earlier.patient_id', 'bills.patient_id')
            ->where('earlier.status', '!=', SaleStatus::Voided->value)
            ->where('earlier.reported_at', '<', max($starts))
            ->when($shifts !== null && $shifts->isNotEmpty(), fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereNull('earlier.shift_id')
                ->orWhereNotIn('earlier.shift_id', $shifts?->modelKeys() ?? [])));
    }

    /**
     * The bills added up by kind of bill, location, and the shop's hour (a
     * single day, or shifts) or day; the bars of the chart add these up.
     *
     * @param  Collection<int, Shift>|null  $shifts
     * @return non-empty-list<Visits>|array{}
     */
    private function visits(Shop $shop, PatientFilters $filters, string $search, ?Collection $shifts): array
    {
        $hourly = $shifts !== null || ($filters->period !== null && self::days($filters->period) === 1);
        $range = $this->billedDays($shop, $filters->period);

        if ($range === null) {
            return [];
        }

        [$bucket, $bindings] = $range->localBucketSql('sales.reported_at', $hourly ? 'hour' : 'day');

        return array_values($this->patients->bills($filters, $search)
            ->selectRaw($bucket, $bindings)
            ->addSelect('sales.bill_type', 'sales.location_id')
            ->selectRaw('COUNT(*) as visits, SUM(sales.grand_total) as total, SUM(sales.refunded_total) as refunded')
            ->selectRaw('MIN(sales.reported_at) as first_at, MAX(sales.reported_at) as last_at, MIN(sales.id) as first_id, MAX(sales.id) as last_id')
            ->groupBy('bucket', 'sales.bill_type', 'sales.location_id')
            ->get()
            ->map(fn (\stdClass $row): array => [
                'bucket' => (string) $row->bucket,
                'opd' => $row->bill_type === BillType::Opd->value,
                'location_id' => (int) $row->location_id,
                'visits' => (int) $row->visits,
                'total' => (int) $row->total,
                'refunded' => (int) $row->refunded,
                'first_at' => (string) $row->first_at,
                'last_at' => (string) $row->last_at,
                'first_id' => (int) $row->first_id,
                'last_id' => (int) $row->last_id,
            ])
            ->all());
    }

    /**
     * The shop's days from its first bill to its last, within the period
     * (two reads of the index): how far apart the hours and days to group by
     * can be, where the clocks change. Null without bills.
     */
    private function billedDays(Shop $shop, ?ReportPeriod $period): ?ReportPeriod
    {
        if ($period !== null && self::days($period) <= ReportPeriod::MAX_DAYS) {
            return $period;
        }

        $bills = fn (): Builder => Sale::query()->toBase()
            ->when($period !== null, fn (Builder $query) => $query
                ->where('sales.reported_at', '>=', $period?->startUtc())
                ->where('sales.reported_at', '<', $period?->endUtc()));
        $first = $bills()->min('sales.reported_at');
        $last = $bills()->max('sales.reported_at');
        $day = fn (string $utc): string => CarbonImmutable::parse($utc, 'UTC')->setTimezone($this->timezone($shop))->format('Y-m-d');

        return $first === null || $last === null ? null : ReportPeriod::between($shop, $day($first), $day($last), clamp: false);
    }

    /**
     * What one bar of the chart is, and the shop's days the bars cover
     * (null: no bills). By the hour for a single day, or for shifts of up
     * to HOURLY_MAX_HOURS from the first opening to the last bill; by the
     * day up to DAILY_MAX_DAYS days; by the month beyond. A period longer
     * than that is cut to the days that have bills.
     *
     * @param  Collection<int, Shift>|null  $shifts
     * @return array{0: 'hour'|'day'|'month', 1: ReportPeriod|null}
     */
    private function bars(Shop $shop, PatientFilters $filters, ?Collection $shifts, ?CarbonImmutable $first, ?CarbonImmutable $last): array
    {
        $singleDay = $filters->period !== null && self::days($filters->period) === 1;

        if ($first === null || $last === null) {
            return [$singleDay || $shifts !== null ? 'hour' : 'day', null];
        }

        $billed = ReportPeriod::between($shop, $first->format('Y-m-d'), $last->format('Y-m-d'), clamp: false);

        if ($singleDay) {
            return ['hour', $billed];
        }

        if ($shifts !== null) {
            $opened = CarbonImmutable::instance($shifts->min('opened_at') ?? $first);

            if (min($opened, $first)->diffInHours($last) <= self::HOURLY_MAX_HOURS) {
                return ['hour', $billed];
            }
        }

        $range = $shifts === null && $filters->period !== null && self::days($filters->period) <= self::DAILY_MAX_DAYS ? $filters->period : $billed;

        return [self::days($range) <= self::DAILY_MAX_DAYS ? 'day' : 'month', $range];
    }

    private static function days(ReportPeriod $period): int
    {
        return (int) round($period->start->diffInDays($period->end));
    }

    /**
     * @param  list<Visits>  $visits
     * @return list<array{location: string, visits: int}>
     */
    private function locations(array $visits): array
    {
        $counts = [];

        foreach ($visits as $row) {
            $counts[$row['location_id']] = ($counts[$row['location_id']] ?? 0) + $row['visits'];
        }

        return $counts === [] ? [] : array_values(Location::query()->whereKey(array_keys($counts))->ordered()->get(['id', 'name'])
            ->map(fn (Location $location): array => ['location' => $location->name, 'visits' => $counts[(int) $location->getKey()]])
            ->all());
    }

    /**
     * The chart's bars, empty ones too. By the hour: from the first bill's
     * hour to the last one's, with the day when there is more than one.
     *
     * @param  list<Visits>  $visits
     * @param  'hour'|'day'|'month'  $unit
     * @return list<array{bucket: string, label: string, opd: int, pharmacy: int}>
     */
    private function timeline(array $visits, ReportPeriod $range, string $unit, CarbonImmutable $first, CarbonImmutable $last): array
    {
        $bars = [];
        $from = $first->format('Y-m-d H');
        $to = $last->format('Y-m-d H');

        foreach ($range->buckets($unit) as $bucket) {
            if ($unit !== 'hour' || ($bucket >= $from && $bucket <= $to)) {
                $bars[$bucket] = ['opd' => 0, 'pharmacy' => 0];
            }
        }

        // Hours add up to a day and days to a month: the start of their name.
        $length = ['hour' => 13, 'day' => 10, 'month' => 7][$unit];

        foreach ($visits as $row) {
            $bucket = substr($row['bucket'], 0, $length);
            $bars[$bucket] ??= ['opd' => 0, 'pharmacy' => 0];
            $bars[$bucket][$row['opd'] ? 'opd' : 'pharmacy'] += $row['visits'];
        }

        ksort($bars);
        $severalDays = count(array_unique(array_map(fn (string $bucket): string => substr($bucket, 0, 10), array_keys($bars)))) > 1;
        $label = fn (string $bucket): string => match ($unit) {
            'hour' => ($severalDays ? CarbonImmutable::parse(substr($bucket, 0, 10))->format('j M ') : '').substr($bucket, 11, 2).':00',
            'day' => CarbonImmutable::parse($bucket)->format('j M'),
            'month' => CarbonImmutable::parse($bucket.'-01')->format('M Y'),
        };

        return array_map(fn (string $bucket, array $bar): array => ['bucket' => $bucket, 'label' => $label($bucket), ...$bar], array_keys($bars), array_values($bars));
    }

    /**
     * The money of the bills, net of the refunds given on them whenever that
     * was: services (products without stock: consultations, lab tests) and
     * medicines (the rest), as the reports split them. A bill's total is the
     * sum of its lines, so only the few service items' lines are read: added
     * up per bill on their own, between the first bill shown and the last,
     * so MySQL reads them by item for a year and by bill for a day. Joined
     * line by line it read every line of a year's bills.
     *
     * @param  list<Visits>  $visits
     * @return array{services: int, medicines: int}
     */
    private function income(PatientFilters $filters, string $search, array $visits): array
    {
        $total = array_sum(array_column($visits, 'total'));
        $refunded = array_sum(array_column($visits, 'refunded'));
        $services = ProductVariant::withTrashed()->toBase()
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('products.track_stock', false)
            ->pluck('product_variants.id')
            ->all();

        if ($services === [] || $visits === []) {
            return ['services' => 0, 'medicines' => $total - $refunded];
        }

        $net = (int) $this->patients->bills($filters, $search)
            ->joinSub(DB::table('sale_items')
                ->whereIn('sale_items.product_variant_id', $services)
                ->whereBetween('sale_items.sale_id', [min(array_column($visits, 'first_id')), max(array_column($visits, 'last_id'))])
                ->groupBy('sale_items.sale_id')
                ->select('sale_items.sale_id')
                ->selectRaw('SUM(sale_items.line_total) as total'), 'service_lines', 'service_lines.sale_id', '=', 'sales.id')
            ->sum('service_lines.total');

        if ($refunded > 0) {
            $net -= (int) $this->patients->bills($filters, $search)
                ->where('sales.refunded_total', '>', 0)
                ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
                ->whereIn('sale_items.product_variant_id', $services)
                ->join('sale_return_items', 'sale_return_items.sale_item_id', '=', 'sale_items.id')
                ->sum('sale_return_items.refund_amount');
        }

        return ['services' => $net, 'medicines' => $total - $refunded - $net];
    }

    private function timezone(Shop $shop): string
    {
        return (string) ($shop->timezone ?: config('app.timezone'));
    }
}
