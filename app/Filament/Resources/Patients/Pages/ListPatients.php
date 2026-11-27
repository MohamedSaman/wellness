<?php

namespace App\Filament\Resources\Patients\Pages;

use App\Exceptions\PosException;
use App\Filament\Pages\Reports\Export\ReportDocument;
use App\Filament\Pages\Reports\Export\ReportExporter;
use App\Filament\Pages\Reports\Export\ReportTable;
use App\Filament\Pages\Reports\ReportColumn;
use App\Filament\Pages\ShiftPatients;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Patients\Widgets\PatientsSummary;
use App\Filament\Widgets\Concerns\ExposesListFilters;
use App\Models\Shift;
use App\Models\User;
use App\Services\PatientListService;
use App\Support\CurrentShop;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The clinic's patients with their filters (docs/PHARMACY.md, branch 16).
 * With one shift chosen, "Patients in this shift" opens that shift's bills.
 * The owner (patients.export) exports the list as filtered to Excel or PDF.
 * Above the list, the summary of what it shows (branch 20). Filters,
 * search and sort stay in the user's session, with Reset filters to clear
 * them (branch 21).
 */
class ListPatients extends ListRecords
{
    use ExposesListFilters;

    protected static string $resource = PatientResource::class;

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
     * the audit log with its filters.
     */
    public function export(string $format): ?StreamedResponse
    {
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
        $this->updatedTableSort();
    }

    /** The one shift the filter names, if it names one (not "open shifts now"). */
    private function chosenShift(): int|string|null
    {
        return PatientResource::filtersOf($this)->shift;
    }
}
