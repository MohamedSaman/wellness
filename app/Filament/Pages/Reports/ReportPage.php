<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Pages\Reports\Concerns\HasReportFilters;
use App\Filament\Pages\Reports\Export\ReportDocument;
use App\Filament\Pages\Reports\Export\ReportTable;
use App\Filament\Support\ShopMoney;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Base for the admin reports that are one table: a shop-time date range, a
 * table and its exports (CSV, Excel, PDF, print) with the same columns and
 * figures. Figures come from ReportService; filters and exports from
 * HasReportFilters.
 *
 * Access needs reports.view; profit reports also need reports.profit, and
 * cost/profit columns are dropped (from screen and every export) without it.
 */
abstract class ReportPage extends Page implements HasTable
{
    use HasReportFilters;
    use InteractsWithTable;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    /** Reports about the current state (stock) have no date range. */
    protected static bool $hasDateRange = true;

    protected static bool $requiresProfitPermission = false;

    /** Narrowed to one location (shown only with multi_location). */
    protected static bool $hasLocationFilter = false;

    /** The date preset a report opens on; null = the last 30 days. */
    protected static ?string $defaultRange = null;

    /** Aggregated reports fit on one page; long lists page through SQL. */
    protected static bool $isPaginated = false;

    /** Columns sort on screen, unless the order means something (a running balance). */
    protected static bool $isSortable = true;

    /**
     * The columns of the print page (its URL names them, as the screen showed
     * them); null = the columns on screen.
     *
     * @var list<string>|null
     */
    public ?array $printColumns = null;

    /** @return list<ReportColumn> */
    abstract protected function reportColumns(): array;

    /**
     * Every row of the report for the current period (screen and exports).
     *
     * @return iterable<array<string, mixed>>
     */
    abstract protected function reportRows(): iterable;

    protected function filtersUpdated(): void
    {
        $this->resetPage();
    }

    /**
     * Columns this user may see at all (profit and cost permissions).
     *
     * @return list<ReportColumn>
     */
    protected function visibleColumns(): array
    {
        $canSeeProfit = $this->canSeeProfit();
        $canSeeCost = $this->canSeeCost();

        return array_values(array_filter(
            $this->reportColumns(),
            fn (ReportColumn $column): bool => ($canSeeProfit || ! $column->profitOnly) && ($canSeeCost || ! $column->costOnly),
        ));
    }

    /**
     * Columns currently on screen: every export has exactly these (the
     * print page, the ones its URL names).
     *
     * @return list<ReportColumn>
     */
    protected function exportedColumns(): array
    {
        if ($this->printColumns !== null) {
            return array_values(array_filter($this->visibleColumns(), fn (ReportColumn $column): bool => in_array($column->key, $this->printColumns, true)));
        }

        return array_values(array_filter(
            $this->visibleColumns(),
            fn (ReportColumn $column): bool => ! $column->toggleable || ! $this->isTableColumnToggledHidden($column->key),
        ));
    }

    /**
     * The print page shows the columns on screen.
     *
     * @return array<string, mixed>
     */
    protected function printQuery(): array
    {
        return [...$this->filterQuery(), 'columns' => implode(',', array_map(fn (ReportColumn $column): string => $column->key, $this->exportedColumns())), 'print' => 1];
    }

    /** The table as a document: the on-screen columns, every row and the total row. */
    public function document(): ReportDocument
    {
        $rows = [];

        foreach ($this->reportRows() as $row) {
            $rows[] = $row;
        }

        $total = static::$isPaginated ? $this->csvTotalRow() : $this->totalRow($rows);

        return $this->makeDocument([new ReportTable(null, $this->exportedColumns(), $rows, $total, $this->documentNote())]);
    }

    /** A line under the exported table, e.g. how a figure is worked out. */
    protected function documentNote(): ?string
    {
        return null;
    }

    /** Per-report touches to a column (widths, links, colours). */
    protected function configureColumn(ReportColumn $column, TextColumn $text): TextColumn
    {
        return $text;
    }

    /** A totals line under the table, for reports that page through SQL. */
    protected function tableFooter(): ?View
    {
        return null;
    }

    /**
     * A last CSV line with totals, for reports that page through SQL.
     *
     * @return array<string, mixed>|null
     */
    protected function csvTotalRow(): ?array
    {
        return null;
    }

    /**
     * A "Total" row adding up the columns marked as totals.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    protected function totalRow(array $rows): ?array
    {
        $columns = $this->visibleColumns();

        if ($rows === [] || ! collect($columns)->contains(fn (ReportColumn $column): bool => $column->total)) {
            return null;
        }

        $total = [$columns[0]->key => 'Total'];

        foreach ($columns as $column) {
            if ($column->total) {
                $sum = array_sum(array_map(fn (array $row): float|int => $row[$column->key] ?? 0, $rows));
                $total[$column->key] = $column->type === ReportColumn::QUANTITY ? round((float) $sum, 3) : (int) $sum;
            }
        }

        return $total;
    }

    /**
     * Rows for the on-screen table. List reports override this to page in SQL.
     *
     * @return array<int|string, array<string, mixed>>|LengthAwarePaginator<int, array<string, mixed>>
     */
    protected function tableRows(int $page, int $perPage, ?string $sortColumn, ?string $sortDirection): array|LengthAwarePaginator
    {
        $rows = iterator_to_array($this->reportRows(), false);

        if ($sortColumn !== null) {
            $rows = array_values(collect($rows)->sortBy($sortColumn, SORT_NATURAL, $sortDirection === 'desc')->all());
        }

        if (($total = $this->totalRow($rows)) !== null) {
            $rows[] = $total;
        }

        return $rows;
    }

    public function table(Table $table): Table
    {
        return $table
            // Only paginated reports ask for a page; unpaginated tables have none.
            ->records(fn (?string $sortColumn, ?string $sortDirection): array|LengthAwarePaginator => static::$isPaginated
                ? $this->tableRows((int) $this->getTablePage(), (int) $this->getTableRecordsPerPage(), null, null)
                : $this->tableRows(1, 0, $sortColumn, $sortDirection))
            ->columns(array_map(fn (ReportColumn $column): TextColumn => $this->tableColumn($column), $this->visibleColumns()))
            ->contentFooter(fn (): ?View => $this->tableFooter())
            ->paginated(static::$isPaginated ? [25, 50, 100] : false)
            ->emptyStateHeading('Nothing in this period')
            ->headerActions([
                Action::make('export')
                    ->label('CSV')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->action(fn (): StreamedResponse => $this->exportCsv()),
                ...$this->exportActions(),
            ]);
    }

    private function tableColumn(ReportColumn $column): TextColumn
    {
        $text = TextColumn::make($column->key)->label($column->label)->sortable(static::$isSortable && ! static::$isPaginated);

        if ($column->isNumeric()) {
            $text->alignEnd();
        }

        if ($column->toggleable) {
            $text->toggleable(isToggledHiddenByDefault: $column->hiddenByDefault);
        }

        $text = match ($column->type) {
            ReportColumn::MONEY => $text->formatStateUsing(fn ($state): ?string => $state === null ? null : ShopMoney::format((int) $state))->placeholder('-'),
            ReportColumn::QUANTITY => $text->formatStateUsing(fn ($state): string => self::quantity($state)),
            ReportColumn::PERCENT => $text->formatStateUsing(fn ($state): string => $state === null ? '-' : number_format((float) $state, 1).'%'),
            default => $text->placeholder('-'),
        };

        return $this->configureColumn($column, $text);
    }

    protected static function quantity(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
    }

    public function exportCsv(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        $columns = $this->exportedColumns();
        $decimals = ShopMoney::decimals();
        $slug = static::getSlug();
        $period = $this->period();
        $filename = static::$hasDateRange
            ? "{$slug}_{$period->fromDate()}_{$period->toDate()}.csv"
            : "{$slug}_".CarbonImmutable::now($period->timezone)->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($columns, $decimals): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fputcsv($out, array_map(fn (ReportColumn $column): string => $column->label, $columns), escape: '');

            $rows = [];

            foreach ($this->reportRows() as $row) {
                fputcsv($out, $this->csvLine($row, $columns, $decimals), escape: '');

                if (! static::$isPaginated) {
                    $rows[] = $row;
                }
            }

            if (($total = static::$isPaginated ? $this->csvTotalRow() : $this->totalRow($rows)) !== null) {
                fputcsv($out, $this->csvLine($total, $columns, $decimals), escape: '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<ReportColumn>  $columns
     * @return list<string>
     */
    private function csvLine(array $row, array $columns, int $decimals): array
    {
        return array_map(function (ReportColumn $column) use ($row, $decimals): string {
            $value = $row[$column->key] ?? null;

            return match (true) {
                $value === null => '',
                $column->type === ReportColumn::MONEY => Money::toInputString((int) $value, $decimals),
                $column->type === ReportColumn::QUANTITY => self::quantity($value),
                $column->type === ReportColumn::PERCENT => number_format((float) $value, 1, '.', ''),
                default => (string) $value,
            };
        }, $columns);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components($this->filterFields() !== []
            ? [EmbeddedSchema::make('filters'), EmbeddedTable::make()]
            : [EmbeddedTable::make()]);
    }
}
