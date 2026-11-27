<?php

namespace App\Services;

use App\Enums\BillType;
use App\Exceptions\PosException;
use App\Models\Patient;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Services\Data\PatientFilters;
use App\Support\CurrentShop;
use App\Support\PatientAge;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The Visits tab of the Patients list and the patient's quick view
 * (docs/PHARMACY.md, branch 21): one row per bill behind the list
 * (PatientListService::bills(): the same filters, search and rules as the
 * Patients tab and the summary, voided bills left out), with what was given
 * on it. Read only.
 *
 * What was given is two lists of the bill's lines, kept quantity first:
 * medicines (lines with a drug schedule, as the patient's history and the
 * dispensing register count them) and other items (lab tests, procedures,
 * goods), the shop's consultation line left out. The lines come in one
 * query per page (or per batch of an export), never one per bill.
 */
class PatientVisitsService
{
    /** The audit log's event for an export of the visits. */
    public const EXPORTED_EVENT = 'patient_visits_exported';

    /** A PDF of more visits than this is refused (Excel takes any number). */
    public const PDF_MAX_ROWS = 2_000;

    /** The quick view lists this many of the patient's bills, newest first. */
    public const QUICK_VIEW_BILLS = 50;

    /** An export reads the bills' lines this many bills at a time. */
    private const EXPORT_BATCH = 500;

    /** The columns of a line that say what was given. */
    private const LINE_COLUMNS = ['id', 'sale_id', 'product_variant_id', 'description', 'strength', 'quantity', 'returned_quantity', 'unit_name', 'drug_schedule'];

    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly PatientListService $patients,
    ) {}

    /**
     * The bills matching the filters (and the search, when given; the table
     * applies its own), with the patient, location, cashier, exchange and the
     * lines that say what was given.
     *
     * @return Builder<Sale>
     */
    public function query(PatientFilters $filters, ?string $search = null): Builder
    {
        // bills() is already scoped to the shop.
        return Sale::query()
            ->withoutGlobalScope('shop')
            ->setQuery($this->patients->bills($filters, $search))
            ->select('sales.*')
            ->with($this->relations());
    }

    /**
     * What to load with each bill.
     *
     * @return array<int|string, mixed>
     */
    public function relations(): array
    {
        $consultation = $this->consultationVariantId();

        return [
            'patient',
            'location:id,name',
            'cashier:id,name',
            'exchange:id,number,new_sale_id',
            'items' => function (HasMany $items) use ($consultation): void {
                $items->select(self::LINE_COLUMNS)->orderBy('id');

                if ($consultation !== null) {
                    $items->where('product_variant_id', '!=', $consultation);
                }
            },
        ];
    }

    /**
     * What the bill gave: its medicines and its other items, each line
     * "Amoxil 250 mg ×10 Capsule", with what came back: "(4 returned)", or
     * "returned" when all of it did. Read from the lines loaded by query().
     *
     * @return array{medicines: list<string>, other: list<string>}
     */
    public function given(Sale $sale): array
    {
        $consultation = $this->consultationVariantId();
        $given = ['medicines' => [], 'other' => []];

        foreach ($sale->items as $item) {
            /** @var SaleItem $item */
            if ($consultation !== null && (int) $item->product_variant_id === $consultation) {
                continue;
            }

            $given[$item->drug_schedule !== null ? 'medicines' : 'other'][] = self::line($item);
        }

        return $given;
    }

    /** "Amoxil 250 mg ×6 Capsule (4 returned)" */
    public static function line(SaleItem $item): string
    {
        // A pack sold names itself in the description ("Paracetamol Syrup (Bottle)"): said once, after the quantity.
        $description = filled($item->unit_name) ? (string) preg_replace('/\s*\('.preg_quote((string) $item->unit_name, '/').'\)$/u', '', $item->description) : $item->description;
        $name = trim($description.' '.(filled($item->strength) && ! str_contains($description, (string) $item->strength) ? $item->strength : ''));
        $sold = (float) $item->quantity;
        $returned = min((float) $item->returned_quantity, $sold);
        $unit = filled($item->unit_name) ? ' '.$item->unit_name : '';

        return match (true) {
            $returned <= 0 => $name.' ×'.Quantity::format($sold).$unit,
            $returned >= $sold => $name.' ×'.Quantity::format($sold).$unit.' returned',
            default => $name.' ×'.Quantity::format($sold - $returned).$unit.' ('.Quantity::format($returned).' returned)',
        };
    }

    /** What the patient paid for the bill, less what was refunded on it since. */
    public static function net(Sale $sale): int
    {
        return (int) $sale->grand_total - (int) $sale->refunded_total;
    }

    /** "OPD", "Pharmacy", or the exchange whose new bill it is: "Exchange EX-000001". */
    public static function kind(Sale $sale): string
    {
        return match (true) {
            $sale->exchange !== null => 'Exchange '.$sale->exchange->number,
            $sale->bill_type === BillType::Opd => 'OPD',
            default => 'Pharmacy',
        };
    }

    /**
     * The patient's bills within the filters, newest first, at most
     * QUICK_VIEW_BILLS, and how many more there are.
     *
     * @return array{bills: Collection<int, Sale>, more: int}
     */
    public function forPatient(Patient $patient, PatientFilters $filters): array
    {
        $query = $this->query($filters)->where('sales.patient_id', $patient->getKey());
        $total = $query->toBase()->getCountForPagination();
        $bills = $query->orderByDesc('sales.reported_at')->orderByDesc('sales.id')->limit(self::QUICK_VIEW_BILLS)->get();

        return ['bills' => $bills, 'more' => max(0, $total - $bills->count())];
    }

    /**
     * The rows of an export of the visits as filtered (the table's own
     * query: filters, search and sort), every line given in full, for a user
     * with patients.export only; the amount only with reports.view.
     *
     * @param  Builder<Sale>  $query  query() as the table filtered and sorted it
     * @return list<array<string, string|int|null>>
     */
    public function exportRows(Builder $query, User $user): array
    {
        if (! $user->can('patients.export') || ! $user->can('dispensing_register.view')) {
            throw new PosException('You do not have permission to export patients.');
        }

        $money = $user->can('reports.view');
        $timezone = $this->timezone();
        $rows = [];

        // Sorted as the table is: the bills a batch at a time, each batch's lines in one query.
        $query->with($this->relations())->chunk(self::EXPORT_BATCH, function (Collection $bills) use (&$rows, $money, $timezone): void {
            foreach ($bills as $sale) {
                /** @var Sale $sale */
                $given = $this->given($sale);
                $rows[] = [
                    'time' => CarbonImmutable::instance($sale->reported_at)->setTimezone($timezone)->format('d/m/Y H:i'),
                    'patient' => $sale->patient?->name,
                    'age' => PatientAge::format($sale->patient_age_months),
                    'kind' => self::kind($sale),
                    'location' => $sale->location?->name,
                    'invoice' => $sale->invoice_no,
                    ...($money ? ['amount' => self::net($sale)] : []),
                    'medicines' => implode('; ', $given['medicines']),
                    'other' => implode('; ', $given['other']),
                    'cashier' => $sale->cashier?->name,
                ];
            }
        });

        return $rows;
    }

    /** Writes an export to the audit log, with its filters, format and number of rows, on the shop. */
    public function recordExport(User $user, PatientFilters $filters, ?string $search, string $format, int $rows): void
    {
        app(AuditObserver::class)->record(self::EXPORTED_EVENT, $this->currentShop->getOrFail(), [], [
            'format' => $format,
            'rows' => $rows,
            'filters' => $this->patients->describe($filters, $search),
            'from' => $filters->period?->fromDate(),
            'to' => $filters->period?->toDate(),
        ], (int) $user->getKey());
    }

    /** The shop's consultation line (Shop settings → OPD), left out of what was given. */
    private function consultationVariantId(): ?int
    {
        $id = $this->currentShop->getOrFail()->setting('opd_consultation_variant_id');

        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }

    private function timezone(): string
    {
        return (string) ($this->currentShop->getOrFail()->timezone ?: config('app.timezone'));
    }
}
