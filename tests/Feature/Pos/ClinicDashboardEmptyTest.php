<?php

namespace Tests\Feature\Pos;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\Clinic\ActivityTimeline;
use App\Filament\Widgets\Clinic\ClinicKpis;
use App\Filament\Widgets\Clinic\GreetingHero;
use App\Filament\Widgets\Clinic\LocationPerformance;
use App\Filament\Widgets\Clinic\SalesTrendChart;
use App\Filament\Widgets\Clinic\StockAlerts;
use App\Filament\Widgets\Clinic\StockValueDonut;
use App\Filament\Widgets\Clinic\TodaysInsights;
use App\Filament\Widgets\Clinic\TodaysOpdBills;
use App\Services\ClinicDashboardService;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The clinic dashboard on a fresh install (or after the go-live reset):
 * nothing sold, nothing in stock, nothing received. Every widget renders,
 * with its empty state.
 */
class ClinicDashboardEmptyTest extends PharmacyTestCase
{
    public function test_every_widget_renders_with_nothing_to_show(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);
        Livewire::withoutLazyLoading();

        $this->get(Dashboard::getUrl())->assertOk();

        foreach ([GreetingHero::class, TodaysInsights::class, ClinicKpis::class, LocationPerformance::class, SalesTrendChart::class, StockValueDonut::class, StockAlerts::class, TodaysOpdBills::class, ActivityTimeline::class] as $widget) {
            Livewire::test($widget)->assertOk();
        }

        $figures = app(ClinicDashboardService::class);
        $this->assertSame(0, $figures->today()['sales']);
        $this->assertSame([], $figures->activity());
        $this->assertSame(0, $figures->stockValue()['total']);

        Livewire::test(TodaysOpdBills::class)->assertSee('No OPD bills yet today');
        Livewire::test(TodaysInsights::class)->assertSee('No sales yet today.');
        Livewire::test(ClinicKpis::class)->assertSee('No sales yet today')->assertDontSeeHtml('pos-kpi-alert');
        Livewire::test(StockAlerts::class)->assertSee('nothing out of stock');
        Livewire::test(StockValueDonut::class)->assertSee('No stock yet');
        Livewire::test(LocationPerformance::class)->assertSee('No sales this month yet.');
        Livewire::test(ActivityTimeline::class)->assertSee('Nothing has happened yet');
        Livewire::test(GreetingHero::class)->assertSee('0 sales today · 0 OPD bills · 0 items low on stock');
    }
}
