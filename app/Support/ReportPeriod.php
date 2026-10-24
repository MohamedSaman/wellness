<?php

namespace App\Support;

use App\Models\Shop;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A range of whole days in the shop's own timezone ("today" in Colombo is not
 * "today" in UTC). Timestamps are stored in UTC, so queries use the UTC
 * instants where the shop's days start and end.
 */
final readonly class ReportPeriod
{
    /** Longest range a report may cover, to keep day-by-day queries small. */
    public const MAX_DAYS = 366;

    /** The date-range presets of the report filters (docs/PHARMACY.md §9, branch 11); "custom" is the dates typed. */
    public const PRESETS = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'this_week' => 'This week',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'custom' => 'Custom',
    ];

    private function __construct(
        public CarbonImmutable $start,      // first day, 00:00 shop time
        public CarbonImmutable $end,        // day after the last day, 00:00 shop time (exclusive)
        public string $timezone,
    ) {}

    /** From and to are inclusive dates (Y-m-d) in the shop's timezone. */
    public static function between(Shop $shop, string $from, string $to): self
    {
        $timezone = self::timezone($shop);
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, $timezone);
        $last = CarbonImmutable::createFromFormat('!Y-m-d', $to, $timezone);

        if ($start === null || $last === null) {
            throw new \InvalidArgumentException('Dates must look like 2026-10-12.');
        }

        if ($last->lessThan($start)) {
            [$start, $last] = [$last, $start];
        }

        if ($start->diffInDays($last) >= self::MAX_DAYS) {
            $start = $last->subDays(self::MAX_DAYS - 1);
        }

        return new self($start, $last->addDay(), $timezone);
    }

    public static function today(Shop $shop): self
    {
        $today = CarbonImmutable::now(self::timezone($shop))->format('Y-m-d');

        return self::between($shop, $today, $today);
    }

    /** The last $days days, ending today. */
    public static function lastDays(Shop $shop, int $days): self
    {
        $today = CarbonImmutable::now(self::timezone($shop));

        return self::between($shop, $today->subDays($days - 1)->format('Y-m-d'), $today->format('Y-m-d'));
    }

    /** Monday to today. */
    public static function thisWeek(Shop $shop): self
    {
        $today = CarbonImmutable::now(self::timezone($shop));

        return self::between($shop, $today->startOfWeek()->format('Y-m-d'), $today->format('Y-m-d'));
    }

    public static function yesterday(Shop $shop): self
    {
        $yesterday = CarbonImmutable::now(self::timezone($shop))->subDay()->format('Y-m-d');

        return self::between($shop, $yesterday, $yesterday);
    }

    /** The 1st of this month to today. */
    public static function thisMonth(Shop $shop): self
    {
        $today = CarbonImmutable::now(self::timezone($shop));

        return self::between($shop, $today->startOfMonth()->format('Y-m-d'), $today->format('Y-m-d'));
    }

    /** The whole of last month. */
    public static function lastMonth(Shop $shop): self
    {
        $month = CarbonImmutable::now(self::timezone($shop))->startOfMonth()->subMonth();

        return self::between($shop, $month->format('Y-m-d'), $month->endOfMonth()->format('Y-m-d'));
    }

    /** A preset of PRESETS (not "custom"), or null for an unknown key. */
    public static function preset(Shop $shop, ?string $key): ?self
    {
        return match ($key) {
            'today' => self::today($shop),
            'yesterday' => self::yesterday($shop),
            'this_week' => self::thisWeek($shop),
            'this_month' => self::thisMonth($shop),
            'last_month' => self::lastMonth($shop),
            default => null,
        };
    }

    /** "21/10/2026", or "01/10/2026 – 21/10/2026", for report headings. */
    public function label(): string
    {
        $from = $this->start->format('d/m/Y');
        $to = $this->end->subDay()->format('d/m/Y');

        return $from === $to ? $from : "{$from} – {$to}";
    }

    /** @return list<string> every month the period touches (Y-m), in order */
    public function months(): array
    {
        return array_values(array_unique(array_map(fn (string $day): string => substr($day, 0, 7), $this->days())));
    }

    private static function timezone(Shop $shop): string
    {
        return (string) ($shop->timezone ?: config('app.timezone'));
    }

    /** First instant, as stored in the database (UTC). */
    public function startUtc(): string
    {
        return $this->start->utc()->format('Y-m-d H:i:s');
    }

    /** First instant after the period, as stored in the database (UTC). */
    public function endUtc(): string
    {
        return $this->end->utc()->format('Y-m-d H:i:s');
    }

    public function fromDate(): string
    {
        return $this->start->format('Y-m-d');
    }

    public function toDate(): string
    {
        return $this->end->subDay()->format('Y-m-d');
    }

    /** @return list<string> every day in the period (Y-m-d, shop time) */
    public function days(): array
    {
        $days = [];

        for ($day = $this->start; $day->lessThan($this->end); $day = $day->addDay()) {
            $days[] = $day->format('Y-m-d');
        }

        return $days;
    }

    /**
     * SQL that turns a UTC timestamp column into the shop-local day (Y-m-d).
     * A CASE over the period's days works on every database and stays exact
     * across daylight-saving changes, unlike a fixed hour offset.
     *
     * Only $column and $alias (both written in code, never user input) are put
     * into the SQL; every date goes in as a binding.
     *
     * @param  literal-string  $column
     * @param  literal-string  $alias
     * @return array{0: literal-string, 1: list<string>}
     */
    public function localDaySql(string $column, string $alias = 'day'): array
    {
        // A timezone with one UTC offset over the whole period (Asia/Colombo has no
        // daylight saving) shifts by it: one addition a row instead of a branch a day.
        $offset = $this->fixedOffsetMinutes();
        $driver = DB::connection()->getDriverName();

        if ($offset !== null && in_array($driver, ['mysql', 'mariadb'], true)) {
            return ["DATE_FORMAT(DATE_ADD({$column}, INTERVAL ? MINUTE), '%Y-%m-%d') as {$alias}", [(string) $offset]];
        }

        if ($offset !== null && $driver === 'sqlite') {
            return ["strftime('%Y-%m-%d', {$column}, ?) as {$alias}", [sprintf('%+d minutes', $offset)]];
        }

        $sql = 'CASE';
        $bindings = [];

        foreach ($this->days() as $day) {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $day, $this->timezone);
            $sql .= " WHEN {$column} >= ? AND {$column} < ? THEN ?";
            $bindings[] = $start->utc()->format('Y-m-d H:i:s');
            $bindings[] = $start->addDay()->utc()->format('Y-m-d H:i:s');
            $bindings[] = $day;
        }

        return ["{$sql} END as {$alias}", $bindings];
    }

    /** The timezone's UTC offset in minutes when it is the same all through the period (no change of clocks), else null. */
    private function fixedOffsetMinutes(): ?int
    {
        $zone = new \DateTimeZone($this->timezone);
        $transitions = $zone->getTransitions($this->start->getTimestamp(), $this->end->getTimestamp());

        // The first entry is the offset at the start; any more is a change within the period.
        if (count($transitions) > 1) {
            return null;
        }

        return intdiv($zone->getOffset($this->start), 60);
    }

    /** Show a stored UTC timestamp in shop time. */
    public function local(?string $utc, string $format = 'Y-m-d H:i'): ?string
    {
        return $utc === null ? null : CarbonImmutable::parse($utc, 'UTC')->setTimezone($this->timezone)->format($format);
    }
}
