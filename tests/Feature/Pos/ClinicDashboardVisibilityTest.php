<?php

namespace Tests\Feature\Pos;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\BestSellersTable;
use App\Filament\Widgets\Clinic\ActivityTimeline;
use App\Filament\Widgets\Clinic\ClinicKpis;
use App\Filament\Widgets\Clinic\GreetingHero;
use App\Filament\Widgets\Clinic\LocationPerformance;
use App\Filament\Widgets\Clinic\SalesTrendChart;
use App\Filament\Widgets\Clinic\StockAlerts;
use App\Filament\Widgets\Clinic\StockValueDonut;
use App\Filament\Widgets\Clinic\TodaysInsights;
use App\Filament\Widgets\Clinic\TodaysOpdBills;
use App\Filament\Widgets\NetSalesChart;
use App\Filament\Widgets\TodayStats;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithBatches;

/**
 * Who sees what on the clinic dashboard, by the permissions the panel
 * already uses (no new role or permission): sales figures need
 * reports.view, stock alerts stock.view, anything at cost cost.view. The
 * cashier has no admin panel at all.
 */
class ClinicDashboardVisibilityTest extends PharmacyTestCase
{
    use WorksWithBatches;

    /** @var list<class-string> */
    private const CLINIC_WIDGETS = [GreetingHero::class, TodaysInsights::class, ClinicKpis::class, LocationPerformance::class, SalesTrendChart::class, StockValueDonut::class, StockAlerts::class, TodaysOpdBills::class, ActivityTimeline::class];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    /** A user with the given permissions and no role. */
    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create(['shop_id' => $this->shop->getKey()]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    public function test_the_owner_sees_every_clinic_widget_and_none_of_the_retail_ones(): void
    {
        $page = $this->actingAs($this->owner)->get(Dashboard::getUrl())->assertOk();

        foreach (self::CLINIC_WIDGETS as $widget) {
            $page->assertSeeLivewire($widget);
        }

        foreach ([TodayStats::class, NetSalesChart::class, BestSellersTable::class] as $widget) {
            $page->assertDontSeeLivewire($widget);
        }

        $this->assertSame(['default' => 1, 'xl' => 5], Livewire::test(SalesTrendChart::class)->instance()->getColumnSpan());
    }

    public function test_a_manager_without_cost_view_sees_no_stock_value_and_no_cost(): void
    {
        $medicine = $this->medicine('Amoxil 250 mg Capsules');
        $this->receive($medicine, 'E5', now()->addDays(5)->format('Y-m-d'), 10);
        $manager = $this->userWith('admin.access', 'reports.view');

        $page = $this->actingAs($manager)->get(Dashboard::getUrl())->assertOk();

        foreach ([GreetingHero::class, TodaysInsights::class, ClinicKpis::class, LocationPerformance::class, SalesTrendChart::class, TodaysOpdBills::class, ActivityTimeline::class] as $widget) {
            $page->assertSeeLivewire($widget);
        }

        $page->assertDontSeeLivewire(StockValueDonut::class)->assertDontSeeLivewire(StockAlerts::class);
        $this->assertFalse(StockValueDonut::canView());
        Livewire::test(StockValueDonut::class)->assertForbidden();

        // The batches expiring are counted, never valued; the trend takes the donut's place.
        Livewire::withoutLazyLoading();
        Livewire::test(TodaysInsights::class)->assertSee('1 batch expires within 30 days.')->assertDontSee('at cost');
        $this->assertSame('full', Livewire::test(SalesTrendChart::class)->instance()->getColumnSpan());
    }

    public function test_cost_view_brings_the_stock_value_and_the_expiring_value(): void
    {
        $medicine = $this->medicine('Amoxil 250 mg Capsules');
        $this->receive($medicine, 'E5', now()->addDays(5)->format('Y-m-d'), 10);
        $this->actingAs($this->userWith('admin.access', 'reports.view', 'cost.view', 'stock.view'));
        Livewire::withoutLazyLoading();

        $this->assertTrue(StockValueDonut::canView());
        $this->assertTrue(StockAlerts::canView());
        Livewire::test(StockValueDonut::class)->assertOk()->assertSee('LKR 100.00');
        Livewire::test(TodaysInsights::class)->assertSee('1 batch expires within 30 days (LKR 100.00 at cost).');
    }

    public function test_a_user_with_the_panel_but_no_reports_sees_only_the_greeting_without_sales(): void
    {
        $this->actingAs($this->userWith('admin.access'));
        Livewire::withoutLazyLoading();

        $this->assertSame([GreetingHero::class], array_values(array_filter(self::CLINIC_WIDGETS, fn (string $widget): bool => $widget::canView())));
        Livewire::test(GreetingHero::class)->assertOk()->assertDontSee('sales today')->assertDontSee('Open POS');
    }

    public function test_the_cashier_has_no_admin_dashboard(): void
    {
        $this->actingAs($this->cashier)->get(Dashboard::getUrl())->assertForbidden();
    }
}
