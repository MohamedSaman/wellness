<?php

namespace App\Services;

use App\Enums\BillType;
use App\Enums\SaleStatus;
use App\Exceptions\PosException;
use App\Models\Device;
use App\Models\Location;
use App\Models\Patient;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Services\Data\PatientFilters;
use App\Support\CurrentShop;
use App\Support\PatientAge;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The Patients list with its filters, and the patients of a shift
 * (docs/PHARMACY.md, branch 16). A visit is a bill with the patient that
 * was not voided, at the time reports count it (sales.reported_at), on the
 * shop's days. Read only; the admin list, its exports, the admin shift
 * view and the till's "Patients this shift" all read from here.
 */
class PatientListService
{
    /** How many shifts the shift filter offers before a search. */
    public const SHIFT_OPTIONS = 30;

    public function __construct(private readonly CurrentShop $currentShop) {}

    /**
     * The patients matching the filters, each with:
     * visits (the matching bills; every bill when nothing narrows them),
     * sales_count (every bill, voided ones too, as before),
     * first_visit_at, last_visit_at and last_location (all time, voided
     * bills left out).
     *
     * @return Builder<Patient>
     */
    public function query(PatientFilters $filters): Builder
    {
        return $this->apply(Patient::query(), $filters);
    }

    /**
     * query() on a given patients query (the admin table's own).
     *
     * @param  Builder<Patient>  $query
     * @return Builder<Patient>
     */
    public function apply(Builder $query, PatientFilters $filters): Builder
    {
        return $query
            ->select('patients.*')
            ->joinSub($this->visits($filters), 'patient_visits', 'patient_visits.patient_id', '=', 'patients.id', $filters->isFiltered() ? 'inner' : 'left')
            ->selectRaw('COALESCE(patient_visits.visits, 0) as visits')
            ->withCount('sales')
            ->selectSub($this->visitBills()->selectRaw('MIN(sales.reported_at)')->whereColumn('sales.patient_id', 'patients.id'), 'first_visit_at')
            ->selectSub($this->visitBills()->selectRaw('MAX(sales.reported_at)')->whereColumn('sales.patient_id', 'patients.id'), 'last_visit_at')
            ->selectSub($this->visitBills()
                ->join('locations', 'locations.id', '=', 'sales.location_id')
                ->select('locations.name')
                ->whereColumn('sales.patient_id', 'patients.id')
                ->orderByDesc('sales.reported_at')
                ->orderByDesc('sales.id')
                ->limit(1), 'last_location');
    }

    /**
     * The matching bills per patient: patient_id, visits. One pass over the
     * period's bills (sales by shop and reported_at), or over a shift's.
     */
    public function visits(PatientFilters $filters): QueryBuilder
    {
        $bills = $this->visitBills()
            ->whereNotNull('sales.patient_id')
            ->when($filters->period !== null, fn (QueryBuilder $query) => $query
                ->where('sales.reported_at', '>=', $filters->period?->startUtc())
                ->where('sales.reported_at', '<', $filters->period?->endUtc()))
            ->when($filters->locationId !== null, fn (QueryBuilder $query) => $query->where('sales.location_id', $filters->locationId))
            ->when($filters->billType === PatientFilters::BILL_OPD, fn (QueryBuilder $query) => $query->where('sales.bill_type', BillType::Opd->value))
            ->when($filters->billType === PatientFilters::BILL_PHARMACY, fn (QueryBuilder $query) => $query->where('sales.bill_type', BillType::Standard->value))
            ->when(is_int($filters->shift), fn (QueryBuilder $query) => $query->where('sales.shift_id', $filters->shift))
            ->when($filters->shift === PatientFilters::OPEN_SHIFTS, fn (QueryBuilder $query) => $query
                ->whereIn('sales.shift_id', Shift::query()->whereNull('closed_at')->select('id')))
            ->groupBy('sales.patient_id')
            ->select('sales.patient_id')
            ->selectRaw('COUNT(*) as visits');

        if ($filters->billType === PatientFilters::BILL_BOTH) {
            $bills->havingRaw('SUM(CASE WHEN sales.bill_type = ? THEN 1 ELSE 0 END) > 0', [BillType::Opd->value])
                ->havingRaw('SUM(CASE WHEN sales.bill_type = ? THEN 1 ELSE 0 END) > 0', [BillType::Standard->value]);
        }

        if ($filters->minVisits !== null) {
            $bills->havingRaw('COUNT(*) >= ?', [$filters->minVisits]);
        }

        return $bills;
    }

    /** The audit log's event for an export of the list. */
    public const EXPORTED_EVENT = 'patients_exported';

    /** A PDF of more patients than this is refused (Excel takes any number). */
    public const PDF_MAX_ROWS = 2_000;

    /**
     * The filters in words, for an export's heading and its audit entry.
     *
     * @return array<string, string> label => value, only the filters set
     */
    public function describe(PatientFilters $filters, ?string $search = null): array
    {
        $shift = is_int($filters->shift) ? Shift::query()->with(['device', 'user'])->find($filters->shift) : null;

        return array_filter([
            'Visited' => $filters->period?->label(),
            'Location' => $filters->locationId === null ? null : (string) Location::query()->whereKey($filters->locationId)->value('name'),
            'Bill type' => $filters->billType === null ? null : PatientFilters::BILL_TYPES[$filters->billType],
            'Visits at least' => $filters->minVisits === null ? null : (string) $filters->minVisits,
            'Shift' => match (true) {
                $filters->shift === PatientFilters::OPEN_SHIFTS => 'Open shifts now',
                $shift !== null => $this->shiftLabel($shift),
                default => null,
            },
            'Search' => filled($search) ? trim((string) $search) : null,
        ], fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * The rows of an export of the list as filtered (the table's own query:
     * filters, search and sort), for a user with patients.export only.
     *
     * @param  Builder<Patient>  $query  query() as the table filtered and sorted it
     * @return list<array{name: string, age: string|null, visits: int, bills: int, first_visit: string|null, last_visit: string|null, last_location: string|null}>
     */
    public function exportRows(Builder $query, User $user): array
    {
        if (! $user->can('patients.export') || ! $user->can('dispensing_register.view')) {
            throw new PosException('You do not have permission to export patients.');
        }

        $timezone = $this->currentShop->getOrFail()->timezone ?: config('app.timezone');
        $local = fn (mixed $utc, string $format): ?string => $utc === null ? null : CarbonImmutable::parse((string) $utc, 'UTC')->setTimezone($timezone)->format($format);
        $rows = [];

        foreach ($query->lazy(500) as $patient) {
            /** @var Patient $patient */
            $rows[] = [
                'name' => $patient->name,
                'age' => PatientAge::format($patient->ageMonths()),
                'visits' => (int) $patient->getAttribute('visits'),
                'bills' => (int) $patient->getAttribute('sales_count'),
                'first_visit' => $local($patient->getAttribute('first_visit_at'), 'd/m/Y'),
                'last_visit' => $local($patient->getAttribute('last_visit_at'), 'd/m/Y H:i'),
                'last_location' => $patient->getAttribute('last_location') === null ? null : (string) $patient->getAttribute('last_location'),
            ];
        }

        return $rows;
    }

    /** Writes an export to the audit log, with its filters, format and number of rows, on the shop. */
    public function recordExport(User $user, PatientFilters $filters, ?string $search, string $format, int $rows): void
    {
        app(AuditObserver::class)->record(self::EXPORTED_EVENT, $this->currentShop->getOrFail(), [], [
            'format' => $format,
            'rows' => $rows,
            'filters' => $this->describe($filters, $search),
            'from' => $filters->period?->fromDate(),
            'to' => $filters->period?->toDate(),
        ], (int) $user->getKey());
    }

    /** The shop's bills that count as visits: every bill but a voided one. */
    private function visitBills(): QueryBuilder
    {
        return Sale::query()->toBase()->where('sales.status', '!=', SaleStatus::Voided->value);
    }

    /**
     * The bills with a patient rung up in a shift, oldest first, voided ones
     * included (marked), with the patient and the location.
     *
     * @return Builder<Sale>
     */
    public function shiftBillsQuery(Shift $shift): Builder
    {
        return Sale::query()
            ->with(['patient', 'location'])
            ->where('shift_id', $shift->getKey())
            ->whereNotNull('patient_id')
            ->orderBy('sold_at')
            ->orderBy('id');
    }

    /** @return Collection<int, Sale> shiftBillsQuery(), every row */
    public function shiftBills(Shift $shift): Collection
    {
        return $this->shiftBillsQuery($shift)->get();
    }

    /** Bills of the shift with no patient recorded (said under the list). */
    public function shiftBillsWithoutPatient(Shift $shift): int
    {
        return Sale::query()->where('shift_id', $shift->getKey())->whereNull('patient_id')->count();
    }

    /** The till's open shift, if any. */
    public function openShift(Device $till): ?Shift
    {
        return Shift::query()
            ->where('device_id', $till->getKey())
            ->whereNull('closed_at')
            ->latest('opened_at')
            ->first();
    }

    /**
     * Patients per shift (distinct patients with a bill in it, voided bills
     * included as on the shift's own list), for Shift history.
     *
     * @param  list<int>  $shiftIds
     * @return array<int, int>
     */
    public function patientsPerShift(array $shiftIds): array
    {
        if ($shiftIds === []) {
            return [];
        }

        return Sale::query()->toBase()
            ->whereIn('shift_id', $shiftIds)
            ->whereNotNull('patient_id')
            ->groupBy('shift_id')
            ->select('shift_id', DB::raw('COUNT(DISTINCT patient_id) as patients'))
            ->pluck('patients', 'shift_id')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * The shift filter's choices: open shifts now, then the latest shifts
     * (or those matching a search by till or cashier), newest first.
     *
     * @return array<string, string>
     */
    public function shiftOptions(?string $search = null): array
    {
        $search = trim((string) $search);

        $shifts = Shift::query()
            ->with(['device', 'user'])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereHas('device', fn (Builder $device) => $device->where('code', 'like', '%'.addcslashes($search, '%_\\').'%'))
                ->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))))
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->limit(self::SHIFT_OPTIONS)
            ->get();

        // A union, not a spread: a spread renumbers the shifts' ids.
        return [PatientFilters::OPEN_SHIFTS => 'Open shifts now']
            + $shifts->mapWithKeys(fn (Shift $shift): array => [(string) $shift->getKey() => $this->shiftLabel($shift)])->all();
    }

    /** "T1 · 14/11/2026 08:02 · Nimal Perera · open" */
    public function shiftLabel(Shift $shift): string
    {
        $timezone = $this->currentShop->getOrFail()->timezone ?: config('app.timezone');

        return implode(' · ', [
            (string) $shift->device?->code,
            $shift->opened_at->setTimezone($timezone)->format('d/m/Y H:i'),
            (string) $shift->user?->name,
            $shift->isOpen() ? 'open' : 'closed',
        ]);
    }
}
