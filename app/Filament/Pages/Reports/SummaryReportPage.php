<?php

namespace App\Filament\Pages\Reports;

use App\Filament\Pages\Reports\Concerns\HasReportFilters;
use App\Filament\Pages\Reports\Export\ReportDocument;
use App\Filament\Pages\Reports\Export\ReportExporter;
use App\Filament\Pages\Reports\Export\ReportTable;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Base for the reports made of several tables (docs/PHARMACY.md §9,
 * branch 11: the daily summary, monthly sales, profit, purchases): the
 * filters of every report, then each table of tables() with its total
 * row, and Excel, PDF and print in the page header. The screen, the
 * exports and the print page show the same tables (document()).
 */
abstract class SummaryReportPage extends Page
{
    use HasReportFilters;

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static bool $hasDateRange = true;

    protected static bool $requiresProfitPermission = false;

    protected static bool $hasLocationFilter = true;

    protected static ?string $defaultRange = 'this_month';

    /** @return list<ReportTable> */
    abstract protected function tables(): array;

    public function document(): ReportDocument
    {
        return $this->makeDocument($this->tables());
    }

    protected function getHeaderActions(): array
    {
        return $this->exportActions();
    }

    /**
     * A table's total row: "Total" in the first column, then the columns
     * marked as totals added up.
     *
     * @param  list<ReportColumn>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    protected function totalOf(array $columns, array $rows): ?array
    {
        if ($rows === []) {
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
     * Columns this user may see (profit and cost permissions), as on table reports.
     *
     * @param  list<ReportColumn>  $columns
     * @return list<ReportColumn>
     */
    protected function allowed(array $columns): array
    {
        $canSeeProfit = $this->canSeeProfit();
        $canSeeCost = $this->canSeeCost();

        return array_values(array_filter($columns, fn (ReportColumn $column): bool => ($canSeeProfit || ! $column->profitOnly) && ($canSeeCost || ! $column->costOnly)));
    }

    /**
     * A table of the report with the user's columns and a total row.
     *
     * @param  list<ReportColumn>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    protected function makeTable(?string $title, array $columns, array $rows, bool $withTotal = true, ?string $note = null): ReportTable
    {
        $columns = $this->allowed($columns);

        return new ReportTable($title, $columns, $rows, $withTotal ? $this->totalOf($columns, $rows) : null, $note);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            ...($this->filterFields() !== [] ? [EmbeddedSchema::make('filters')] : []),
            View::make('filament.reports.summary')->viewData(fn (): array => [
                'tables' => $this->tables(),
                'exporter' => app(ReportExporter::class),
            ]),
        ]);
    }
}
