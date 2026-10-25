<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\BackupStats;
use App\Filament\Widgets\Clinic\ActivityTimeline;
use App\Filament\Widgets\Clinic\ClinicKpis;
use App\Filament\Widgets\Clinic\GreetingHero;
use App\Filament\Widgets\Clinic\LocationPerformance;
use App\Filament\Widgets\Clinic\SalesTrendChart;
use App\Filament\Widgets\Clinic\StockAlerts;
use App\Filament\Widgets\Clinic\StockValueDonut;
use App\Filament\Widgets\Clinic\TodaysInsights;
use App\Filament\Widgets\Clinic\TodaysOpdBills;
use App\Filament\Widgets\ItemsToReview;
use App\Filament\Widgets\SupplierPayablesDue;
use App\Filament\Widgets\UnallocatedStockTable;
use App\Support\CurrentShop;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

/**
 * The owner's main dashboard (/admin).
 *
 * A retail shop: today's figures, the sales trend, best sellers and low
 * stock, the discovered widgets in their order; one column on phones, two
 * from large screens.
 *
 * A clinic (pharmacy module, feat/clinic-theme-dashboard): a 12-column
 * layout from extra-large screens, one column below, so it stacks on
 * tablets and phones:
 *   1. greeting (8) | today's insights (4)
 *   2–3. the KPI cards, then the sales trend (5) | stock value (3), all in
 *      8 columns | location performance (4) beside them
 *   4. stock alerts (4) | today's OPD bills (8); activity (12)
 *   5. what needs action and shows only then: items to review, stock sold
 *      beyond its batches, supplier payments due, backups.
 * Each widget checks its own permission (canView), so a widget the user may
 * not see leaves its space to the others.
 */
class Dashboard extends BaseDashboard
{
    public function getColumns(): int|array
    {
        return ['default' => 1, 'lg' => 2];
    }

    public function content(Schema $schema): Schema
    {
        if (! (app(CurrentShop::class)->get()?->hasModule('pharmacy') ?? false)) {
            return parent::content($schema);
        }

        $columns = ['default' => 1, 'xl' => 12];

        return $schema->components([
            Grid::make($columns)
                ->schema(fn (): array => [
                    ...$this->getWidgetsSchemaComponents([GreetingHero::class, TodaysInsights::class]),
                    Grid::make(['default' => 1, 'xl' => 8])
                        ->columnSpan(['default' => 'full', 'xl' => 8])
                        ->schema(fn (): array => $this->getWidgetsSchemaComponents([ClinicKpis::class, SalesTrendChart::class, StockValueDonut::class])),
                    Grid::make(1)
                        ->columnSpan(['default' => 'full', 'xl' => 4])
                        ->schema(fn (): array => $this->getWidgetsSchemaComponents([LocationPerformance::class])),
                    ...$this->getWidgetsSchemaComponents([StockAlerts::class, TodaysOpdBills::class, ActivityTimeline::class]),
                    ...$this->getWidgetsSchemaComponents([ItemsToReview::class, UnallocatedStockTable::class, SupplierPayablesDue::class, BackupStats::class]),
                ]),
        ]);
    }
}
