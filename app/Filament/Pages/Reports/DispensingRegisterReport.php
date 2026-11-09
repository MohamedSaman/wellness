<?php

namespace App\Filament\Pages\Reports;

use App\Enums\DrugSchedule;
use App\Filament\Pages\Reports\Export\ReportDocument;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Sales\SaleResource;
use App\Models\Patient;
use App\Models\ProductVariant;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Support\CurrentShop;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The dispensing register (docs/PHARMACY.md, branch 13): which patient
 * received which medicine, at every till and location, from the sales'
 * own rows (ReportService::dispensingRegister()). Bills without a patient
 * say "No patient recorded". Filters: the shop's days, a patient (or no
 * patient), a medicine, a schedule and the location dispensed from.
 *
 * Health data: needs dispensing_register.view (the owner; a Pharmacist and
 * a Doctor role later), and every opening, change of filter and export
 * (Excel, PDF, CSV, print) is written to the audit log with its filters.
 */
class DispensingRegisterReport extends ReportPage
{
    public const VIEWED_EVENT = 'dispensing_register_viewed';

    public const EXPORTED_EVENT = 'dispensing_register_exported';

    protected static ?string $title = 'Dispensing register';

    protected static ?int $navigationSort = 27;

    protected static bool $hasLocationFilter = true;

    protected static bool $isPaginated = true;

    /** A register reads in the order it was written. */
    protected static bool $isSortable = false;

    /** A patient's id, or 'none' for bills without one. */
    #[Url]
    public ?string $patient = null;

    #[Url]
    public ?string $medicine = null;

    #[Url]
    public ?string $schedule = null;

    /** Set by the export about to build the document; null = the print page. */
    private ?string $exportFormat = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $shop = app(CurrentShop::class)->get();

        return $user instanceof User && $user->can('dispensing_register.view') && $shop !== null && $shop->hasModule('pharmacy');
    }

    public function mount(): void
    {
        parent::mount();

        $this->audit(self::VIEWED_EVENT);
    }

    protected function filtersUpdated(): void
    {
        parent::filtersUpdated();

        $this->audit(self::VIEWED_EVENT);
    }

    protected function locationLabel(): string
    {
        return 'Dispensed at';
    }

    /** @return array{patient: int|string|null, variant_id: int|null, schedule: string|null, location_id: int|null} */
    public function registerFilters(): array
    {
        return [
            'patient' => $this->patient === 'none' ? 'none' : (ctype_digit((string) $this->patient) ? (int) $this->patient : null),
            'variant_id' => ctype_digit((string) $this->medicine) ? (int) $this->medicine : null,
            'schedule' => DrugSchedule::tryFrom((string) $this->schedule)?->value,
            'location_id' => $this->locationId(),
        ];
    }

    protected function reportColumns(): array
    {
        return [
            ReportColumn::text('when', 'Date'),
            ReportColumn::text('invoice', 'Bill'),
            ReportColumn::text('till', 'Till'),
            ...($this->multiLocation() ? [ReportColumn::text('location', 'Dispensed at')] : []),
            ReportColumn::text('patient', 'Patient'),
            ReportColumn::text('age', 'Age'),
            ReportColumn::text('medicine', 'Medicine'),
            ReportColumn::text('form', 'Form'),
            ReportColumn::text('schedule', 'Schedule'),
            ReportColumn::text('batch', 'Batch'),
            ReportColumn::text('expiry', 'Expiry'),
            ReportColumn::quantity('quantity', 'Quantity', total: false),
            ReportColumn::text('unit', 'Unit'),
            ReportColumn::quantity('returned', 'Returned', total: false)->toggleable(hiddenByDefault: true),
            ReportColumn::text('prescriber', 'Prescriber'),
            ReportColumn::text('rx_ref', 'Rx ref.')->toggleable(hiddenByDefault: true),
            ReportColumn::text('cashier', 'Cashier'),
        ];
    }

    protected function filterFields(): array
    {
        $changed = fn () => $this->filtersUpdated();

        return [
            ...parent::filterFields(),
            Select::make('patient')
                ->label('Patient')
                ->placeholder('Every patient')
                ->searchable()
                ->options(['none' => 'No patient recorded'])
                ->getSearchResultsUsing(fn (string $search): array => ['none' => 'No patient recorded', ...Patient::query()
                    ->search($search)
                    ->orderBy('name')
                    ->limit(50)
                    ->pluck('name', 'id')
                    ->mapWithKeys(fn (string $name, int $id): array => [(string) $id => $name])
                    ->all()])
                ->getOptionLabelUsing(fn (?string $value): ?string => $value === 'none' ? 'No patient recorded' : Patient::query()->whereKey((int) $value)->value('name'))
                ->live()
                ->afterStateUpdated($changed),
            Select::make('medicine')
                ->label('Medicine')
                ->placeholder('Every medicine')
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array => $this->medicines($search))
                ->getOptionLabelUsing(fn (?string $value): ?string => $this->medicines(null, (int) $value)[(int) $value] ?? null)
                ->live()
                ->afterStateUpdated($changed),
            Select::make('schedule')
                ->label('Schedule')
                ->placeholder('Every schedule')
                ->options(collect(DrugSchedule::cases())->mapWithKeys(fn (DrugSchedule $schedule): array => [$schedule->value => $schedule->label()])->all())
                ->live()
                ->afterStateUpdated($changed),
        ];
    }

    /** @return array<int, string> variant id => medicine, by name (or the one id) */
    private function medicines(?string $search, ?int $id = null): array
    {
        return ProductVariant::query()
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->join('drug_details', 'drug_details.product_id', '=', 'products.id')
            ->when($id !== null, fn ($query) => $query->where('product_variants.id', $id))
            ->when($search !== null, fn ($query) => $query->where('products.name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderBy('products.name')
            ->limit(50)
            ->get(['product_variants.id', 'products.name', 'product_variants.name as variant'])
            ->mapWithKeys(fn ($variant): array => [(int) $variant->id => trim($variant->name.' '.($variant->variant ?? ''))])
            ->all();
    }

    protected function documentFilters(): array
    {
        $filters = $this->registerFilters();
        $patient = match (true) {
            $filters['patient'] === 'none' => 'No patient recorded',
            is_int($filters['patient']) => (string) Patient::query()->whereKey($filters['patient'])->value('name'),
            default => 'Every patient',
        };

        return [
            ['Patient', $patient],
            ['Medicine', $filters['variant_id'] === null ? 'Every medicine' : ($this->medicines(null, $filters['variant_id'])[$filters['variant_id']] ?? '')],
            ['Schedule', DrugSchedule::tryFrom((string) $filters['schedule'])?->label() ?? 'Every schedule'],
        ];
    }

    protected function configureColumn(ReportColumn $column, TextColumn $text): TextColumn
    {
        return match ($column->key) {
            'when' => $text->extraAttributes(['style' => 'white-space: nowrap']),
            'invoice' => $text
                ->url(fn (array $record): string => SaleResource::getUrl('view', ['record' => $record['sale_uuid']]))
                ->color('primary')
                ->extraAttributes(['style' => 'white-space: nowrap']),
            'patient' => $text
                ->wrap()
                ->url(fn (array $record): ?string => $record['patient_uuid'] !== null && PatientResource::canViewAny()
                    ? PatientResource::getUrl('view', ['record' => $record['patient_uuid']])
                    : null)
                ->color(fn (array $record): string => $record['patient_id'] === null ? 'gray' : 'primary'),
            'medicine' => $text->wrap()->extraAttributes(['style' => 'min-width: 10rem']),
            default => $text,
        };
    }

    protected function tableRows(int $page, int $perPage, ?string $sortColumn, ?string $sortDirection): array|LengthAwarePaginator
    {
        return $this->reports()->dispensingRegister($this->period(), $this->registerFilters(), $page, $perPage);
    }

    protected function reportRows(): iterable
    {
        return $this->reports()->dispensingRegisterRows($this->period(), $this->registerFilters());
    }

    protected function documentNote(): ?string
    {
        return 'Quantities in base units, per batch dispensed. Patient and age as recorded on the bill.';
    }

    public function document(): ReportDocument
    {
        $document = parent::document();
        $this->audit(self::EXPORTED_EVENT, ['format' => $this->exportFormat ?? 'print', 'rows' => count($document->tables[0]->rows ?? [])]);

        return $document;
    }

    public function exportXlsx(): StreamedResponse
    {
        $this->exportFormat = 'xlsx';

        return parent::exportXlsx();
    }

    public function exportPdf(): StreamedResponse
    {
        $this->exportFormat = 'pdf';

        return parent::exportPdf();
    }

    public function exportCsv(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);
        $this->audit(self::EXPORTED_EVENT, ['format' => 'csv']);

        return parent::exportCsv();
    }

    /**
     * Who looked at the register, or took a copy, and with what filters.
     *
     * @param  array<string, mixed>  $extra
     */
    private function audit(string $event, array $extra = []): void
    {
        $period = $this->period();

        app(AuditObserver::class)->record($event, app(CurrentShop::class)->getOrFail(), [], [
            'from' => $period->fromDate(),
            'to' => $period->toDate(),
            ...array_filter($this->registerFilters(), fn (mixed $value): bool => $value !== null),
            ...$extra,
        ], auth()->id() === null ? null : (int) auth()->id());
    }
}
