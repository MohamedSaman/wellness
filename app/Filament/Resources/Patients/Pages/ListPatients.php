<?php

namespace App\Filament\Resources\Patients\Pages;

use App\Exceptions\PosException;
use App\Filament\Pages\Reports\Export\ReportDocument;
use App\Filament\Pages\Reports\Export\ReportExporter;
use App\Filament\Pages\Reports\Export\ReportTable;
use App\Filament\Pages\Reports\ReportColumn;
use App\Filament\Pages\ShiftPatients;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Patients\Tables\PatientVisitsTable;
use App\Filament\Resources\Patients\Widgets\PatientsSummary;
use App\Filament\Widgets\Concerns\ExposesListFilters;
use App\Models\Shift;
use App\Models\User;
use App\Services\PatientListService;
use App\Services\PatientVisitsService;
use App\Support\CurrentShop;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The clinic's patients with their filters (docs/PHARMACY.md, branch 16).
 * With one shift chosen, "Patients in this shift" opens that shift's bills.
 * The owner (patients.export) exports the list as filtered to Excel or PDF.
 * Above the list, the summary of what it shows (branch 20). Filters,
 * search and sort stay in the user's session, with Reset filters to clear
 * them, and the Visits tab lists the bills behind the list, one row each,
 * with the same filters, search and summary (branch 21).
 */
class ListPatients extends ListRecords
{
    use ExposesListFilters;

    public const PATIENTS = 'patients';

    public const VISITS = 'visits';

    protected static string $resource = PatientResource::class;

    /** Patients | Visits: the same filters, search and summary, each tab with its own columns and sort. */
    public function getTabs(): array
    {
        return [
            self::PATIENTS => Tab::make('Patients')->icon(Heroicon::OutlinedUserGroup),
            self::VISITS => Tab::make('Visits')->icon(Heroicon::OutlinedClipboardDocumentList),
        ];
    }

    /** The tab last used comes back with the filters (the sidebar's link names none). */
    public function mount(): void
    {
        if (blank($this->activeTab) && in_array($remembered = session($this->tabSessionKey()), [self::PATIENTS, self::VISITS], true)) {
            $this->activeTab = $remembered;
        }

        parent::mount();

        if (! in_array($this->activeTab, [self::PATIENTS, self::VISITS], true)) {
            $this->activeTab = self::PATIENTS;
        }

        session()->put($this->tabSessionKey(), $this->activeTab);
    }

    /** Another tab: its own columns and its own sort, as last left. */
    public function updatedActiveTab(): void
    {
        if (! in_array($this->activeTab, [self::PATIENTS, self::VISITS], true)) {
            $this->activeTab = self::PATIENTS;
        }

        session()->put($this->tabSessionKey(), $this->activeTab);
        $sort = session($this->getTableSortSessionKey());
        $this->tableSort = is_string($sort) ? $sort : null;
        $this->table = $this->table($this->makeTable());

        parent::updatedActiveTab();
    }

    public function isVisits(): bool
    {
        return $this->activeTab === self::VISITS;
    }

    /** Each tab sorts by its own columns. */
    public function getTableSortSessionKey(): string
    {
        return parent::getTableSortSessionKey().'.'.($this->isVisits() ? self::VISITS : self::PATIENTS);
    }

    protected function makeTable(): Table
    {
        return $this->isVisits() ? PatientVisitsTable::configure($this->makeBaseTable(), $this) : parent::makeTable();
    }

    /** Read in mount(), before the table is built (so not getTableSessionKey()). */
    private function tabSessionKey(): string
    {
        return 'patients-list.tab';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('resetList')
                ->label('Reset filters')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn (): bool => $this->hasListState())
                ->action(fn () => $this->resetList()),
            Action::make('exportXlsx')
                ->label('Excel')
                ->icon(Heroicon::OutlinedTableCells)
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('patients.export') ?? false)
                ->action(fn (): ?StreamedResponse => $this->export('xlsx')),
            Action::make('exportPdf')
                ->label('PDF')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('patients.export') ?? false)
                ->action(fn (): ?StreamedResponse => $this->export('pdf')),
            Action::make('shiftPatients')
                ->label('Patients in this shift')
                ->icon(Heroicon::OutlinedClock)
                ->color('gray')
                ->visible(fn (): bool => is_int($this->chosenShift()) && ShiftPatients::canAccess())
                ->url(fn (): ?string => is_int($id = $this->chosenShift()) && ($shift = Shift::query()->find($id)) !== null ? ShiftPatients::getUrl(['shift' => $shift]) : null),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [PatientsSummary::class];
    }

    /**
     * The list as filtered, searched and sorted, every page of it, to Excel
     * or PDF (patients.export, checked by PatientListService), written to
     * the audit log with its filters. On the Visits tab, the visits.
     */
    public function export(string $format): ?StreamedResponse
    {
        if ($this->isVisits()) {
            return $this->exportVisits($format);
        }

        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $service = app(PatientListService::class);
        $filters = PatientResource::filtersOf($this);
        $search = $this->getTableSearch();
        $query = $this->getFilteredSortedTableQuery() ?? abort(500);

        if ($format === 'pdf' && ($count = $query->toBase()->getCountForPagination()) > PatientListService::PDF_MAX_ROWS) {
            Notification::make()
                ->title('Too many patients for a PDF ('.number_format($count).')')
                ->body('Narrow the filters, or export to Excel.')
                ->warning()
                ->send();

            return null;
        }

        try {
            $rows = $service->exportRows($query, $user);
        } catch (PosException $exception) {
            abort(403, $exception->getMessage());
        }

        $service->recordExport($user, $filters, $search, $format, count($rows));

        $timezone = app(CurrentShop::class)->getOrFail()->timezone ?: 'UTC';
        $meta = collect($service->describe($filters, $search))->map(fn (string $value, string $label): array => [$label, $value])->values()->all();
        $document = new ReportDocument(
            'Patients',
            [['As at', CarbonImmutable::now($timezone)->format('d/m/Y H:i')], ...($meta === [] ? [['Filters', 'None: every patient']] : $meta)],
            [new ReportTable(null, [
                ReportColumn::text('name', 'Name'),
                ReportColumn::text('age', 'Age'),
                ReportColumn::integer('visits', 'Visits', total: false),
                ReportColumn::integer('bills', 'Bills', total: false),
                ReportColumn::text('first_visit', 'First visit'),
                ReportColumn::text('last_visit', 'Last visit'),
                ReportColumn::text('last_location', 'Last location'),
            ], $rows)],
            'patients_'.CarbonImmutable::now($timezone)->format('Y-m-d'),
        );

        return $format === 'pdf'
            ? app(ReportExporter::class)->pdfDownload($document)
            : app(ReportExporter::class)->xlsxDownload($document);
    }

    /**
     * The visits as filtered, searched and sorted, every page, each with every
     * medicine and other item given, to Excel or PDF (patients.export, checked
     * by PatientVisitsService; the amount with reports.view), audited.
     */
    private function exportVisits(string $format): ?StreamedResponse
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $service = app(PatientVisitsService::class);
        $filters = PatientResource::filtersOf($this);
        $search = $this->getTableSearch();
        $query = $this->getFilteredSortedTableQuery() ?? abort(500);

        if ($format === 'pdf' && ($count = $query->toBase()->getCountForPagination()) > PatientVisitsService::PDF_MAX_ROWS) {
            Notification::make()
                ->title('Too many visits for a PDF ('.number_format($count).')')
                ->body('Narrow the filters, or export to Excel.')
                ->warning()
                ->send();

            return null;
        }

        try {
            $rows = $service->exportRows($query, $user);
        } catch (PosException $exception) {
            abort(403, $exception->getMessage());
        }

        $service->recordExport($user, $filters, $search, $format, count($rows));

        $timezone = PatientResource::timezone();
        $meta = collect(app(PatientListService::class)->describe($filters, $search))->map(fn (string $value, string $label): array => [$label, $value])->values()->all();
        $document = new ReportDocument(
            'Patient visits',
            [['As at', CarbonImmutable::now($timezone)->format('d/m/Y H:i')], ...($meta === [] ? [['Filters', 'None: every visit']] : $meta)],
            [new ReportTable(null, array_values(array_filter([
                ReportColumn::text('time', 'Time'),
                ReportColumn::text('patient', 'Patient'),
                ReportColumn::text('age', 'Age'),
                ReportColumn::text('kind', 'Type'),
                ReportColumn::text('location', 'Location'),
                ReportColumn::text('invoice', 'Invoice'),
                $user->can('reports.view') ? ReportColumn::money('amount', 'Amount') : null,
                ReportColumn::text('medicines', 'Medicines'),
                ReportColumn::text('other', 'Other items'),
                ReportColumn::text('cashier', 'Cashier'),
            ])), $rows)],
            'patient_visits_'.CarbonImmutable::now($timezone)->format('Y-m-d'),
        );

        return $format === 'pdf'
            ? app(ReportExporter::class)->pdfDownload($document)
            : app(ReportExporter::class)->xlsxDownload($document);
    }

    /** Something narrows or orders the list: a filter, the search or a sort. */
    public function hasListState(): bool
    {
        return PatientResource::filtersOf($this)->isFiltered() || filled($this->getTableSearch()) || filled($this->tableSort);
    }

    /**
     * Every filter, the search and the sort back to none, in the session too
     * (branch 21): the list as on a first visit.
     */
    public function resetList(): void
    {
        $this->removeTableFilters();
        $this->tableSort = null;

        foreach ([self::PATIENTS, self::VISITS] as $tab) {
            session()->forget(parent::getTableSortSessionKey().'.'.$tab);
        }

        $this->resetPage();
    }

    /** The one shift the filter names, if it names one (not "open shifts now"). */
    private function chosenShift(): int|string|null
    {
        return PatientResource::filtersOf($this)->shift;
    }
}
