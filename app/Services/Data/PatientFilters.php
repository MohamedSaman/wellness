<?php

namespace App\Services\Data;

use App\Models\Shop;
use App\Support\ReportPeriod;

/**
 * The Patients list's filters (docs/PHARMACY.md, branch 16). Period,
 * location, bill type and shift are conditions on the same bill: a patient
 * is listed when they have bills (not voided) matching all of them, and
 * their visits are those bills. The minimum is counted over them; with
 * none of the four set, over every bill they ever had.
 */
final readonly class PatientFilters
{
    public const BILL_OPD = 'opd';

    public const BILL_PHARMACY = 'pharmacy';

    /** At least one OPD bill and one pharmacy bill among the matching bills. */
    public const BILL_BOTH = 'both';

    public const BILL_TYPES = [
        self::BILL_OPD => 'OPD consultation',
        self::BILL_PHARMACY => 'Pharmacy',
        self::BILL_BOTH => 'Both (OPD and pharmacy)',
    ];

    /** The shift filter's value for every shift open right now. */
    public const OPEN_SHIFTS = 'open';

    /** The period presets of the "Last visit" filter; "custom" is the dates typed. */
    public const PERIODS = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'this_week' => 'This week',
        'this_month' => 'This month',
        'custom' => 'Custom dates',
    ];

    public function __construct(
        public ?ReportPeriod $period = null,
        public ?int $locationId = null,
        public ?string $billType = null,
        public ?int $minVisits = null,
        /** A shift's id, or OPEN_SHIFTS. */
        public int|string|null $shift = null,
    ) {}

    /** Any condition on the bills themselves (the minimum alone is not one). */
    public function hasBillConditions(): bool
    {
        return $this->period !== null || $this->locationId !== null || $this->billType !== null || $this->shift !== null;
    }

    public function isFiltered(): bool
    {
        return $this->hasBillConditions() || $this->minVisits !== null;
    }

    /**
     * From the Patients table's filter state (`?filters[...]`), anything
     * unknown or malformed ignored.
     *
     * @param  array<string, mixed>  $state
     */
    public static function fromTableState(Shop $shop, array $state): self
    {
        $value = fn (string $filter, string $field): mixed => is_array($state[$filter] ?? null) ? ($state[$filter][$field] ?? null) : null;

        $billType = $value('bill_type', 'value');
        $minVisits = $value('visits', 'min');
        $shift = $value('shift', 'value');
        $location = $value('location', 'value');

        return new self(
            period: self::period($shop, $value('last_visit', 'range'), $value('last_visit', 'from'), $value('last_visit', 'to')),
            locationId: is_numeric($location) && (int) $location > 0 ? (int) $location : null,
            billType: is_string($billType) && array_key_exists($billType, self::BILL_TYPES) ? $billType : null,
            minVisits: is_numeric($minVisits) && (int) $minVisits >= 1 ? min((int) $minVisits, 10_000) : null,
            shift: $shift === self::OPEN_SHIFTS ? self::OPEN_SHIFTS : (is_numeric($shift) && (int) $shift > 0 ? (int) $shift : null),
        );
    }

    /** A preset's days, or the custom dates (either end alone is fine); null = no period. */
    public static function period(Shop $shop, mixed $range, mixed $from, mixed $to): ?ReportPeriod
    {
        if (! is_string($range) || ! array_key_exists($range, self::PERIODS)) {
            return null;
        }

        if ($range !== 'custom') {
            return ReportPeriod::preset($shop, $range);
        }

        $from = self::date($from);
        $to = self::date($to);

        if ($from === null && $to === null) {
            return null;
        }

        return ReportPeriod::between($shop, $from ?? '2000-01-01', $to ?? now($shop->timezone ?: config('app.timezone'))->format('Y-m-d'), clamp: false);
    }

    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return substr($value, 0, 10);
    }
}
