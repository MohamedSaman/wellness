<?php

namespace App\Filament\Widgets\Summary;

use App\Filament\Widgets\Concerns\FollowsListTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * A summary above a list page's table that follows its filters and search:
 * a title saying what it covers, cards and charts, in a section that folds
 * away (remembered per user, in their browser). Built for the Patients list
 * (PatientsSummary); another list adds its own by extending this, with
 * ExposesListFilters on its page and its figures read once per render.
 */
abstract class ListSummaryWidget extends StatsOverviewWidget
{
    use FollowsListTable;

    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    /** What the figures cover: "Today · Doctor's Medicines". */
    abstract protected function title(): string;

    /** Names the fold in the browser's storage, with the user's id. */
    abstract protected function foldName(): string;

    /** @return list<array{name: string, columns: int, heading: string, description: string|null, labels: list<string>, series: list<array{label: string, color: string, data: list<int>}>}> */
    abstract protected function charts(): array;

    /** A line under the cards, if any. */
    protected function note(): ?string
    {
        return null;
    }

    /** Three to a row beside the sidebar, so amounts are not cut off; four on a wide page when that fills the rows better. */
    protected function getColumns(): array
    {
        $wide = count($this->getCachedStats()) % 3 === 0 ? 3 : 4;

        return ['default' => 1, '@md' => 2, '@3xl' => 3, '@6xl' => $wide, '!@md' => 2, '!@3xl' => 3];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make($this->title())
                ->key('summary')
                ->id($this->foldName().'-'.auth()->id())
                ->collapsible()
                ->persistCollapsed()
                ->compact()
                ->gridContainer()
                ->extraAttributes(['class' => 'pos-list-summary', 'data-test' => $this->foldName()])
                ->columns(1)
                ->schema([
                    Grid::make($this->getColumns())->schema($this->getCachedStats()),
                    ...($this->note() === null ? [] : [Text::make($this->note())->color('gray')->size('xs')]),
                    Grid::make(['default' => 1, '@4xl' => 3, '!@4xl' => 3])->schema(array_map(
                        fn (array $chart): Livewire => Livewire::make(SummaryChart::class, ['chart' => array_diff_key($chart, ['name' => true, 'columns' => true])])
                            ->key($chart['name'])
                            ->columnSpan(['default' => 1, '@4xl' => $chart['columns'], '!@4xl' => $chart['columns']]),
                        $this->charts(),
                    )),
                ]),
        ]);
    }

    /** "12 · 30": figures that belong together on one card. */
    protected static function pair(int ...$figures): string
    {
        return implode(' · ', array_map(number_format(...), $figures));
    }

    protected static function stat(string $label, string $value, ?string $description = null): Stat
    {
        return Stat::make($label, $value)->description($description)->extraAttributes(['data-test' => 'summary-'.str($label)->slug()]);
    }
}
