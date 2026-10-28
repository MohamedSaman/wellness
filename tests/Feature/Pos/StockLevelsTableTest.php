<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Filament\Resources\StockLevels\Pages\ListStockLevels;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Resources\StockLevels\Widgets\StockOverview;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use App\Services\ReportService;
use App\Support\Money;
use Livewire\Livewire;

/** The admin Stock levels table: stock value, negative stock and row actions. */
class StockLevelsTableTest extends AdminTestCase
{
    /** Sell more than is on the shelf, as an offline till may: the plug ends at -3. */
    private function plugBelowZero(): ProductVariant
    {
        $plug = $this->variant('CPR8EA-9'); // 40 on hand

        app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->till,
            cashier: $this->cashier,
            lines: [new CartLine($plug->getKey(), 43, tillUnitPrice: 145_000)],
            payments: [new PaymentInput(PaymentMethod::Cash, 43 * 145_000)],
            isOfflineSync: true,
            soldAt: now(),
        ));

        $this->assertSame(-3.0, $this->stockOf($plug));

        return $plug->fresh();
    }

    /** max(on_hand, 0) x cost over every stock-tracked variant, worked out here independently. */
    private function expectedStockValue(): int
    {
        return (int) ProductVariant::query()
            ->withSum('stockLevels as on_hand', 'quantity')
            ->get()
            ->sum(fn (ProductVariant $variant): int => Money::multiply((int) $variant->cost_price, max(0.0, (float) $variant->getAttribute('on_hand'))));
    }

    public function test_stock_value_never_counts_stock_below_zero(): void
    {
        $reports = app(ReportService::class);
        $before = $reports->stockValueAtCost();
        $plug = $this->variant('CPR8EA-9');
        $plugValue = 40 * $plug->cost_price;

        $plug = $this->plugBelowZero();

        // The plug's own value drops to zero; it does not pull the total below the other stock.
        $this->assertSame($before - $plugValue, $reports->stockValueAtCost());
        $this->assertSame($this->expectedStockValue(), $reports->stockValueAtCost());

        $this->actingAs($this->owner);

        Livewire::test(ListStockLevels::class)
            ->assertTableColumnStateSet('stock_value', $this->shop->formatMoney(0), $plug);

        Livewire::test(StockOverview::class)
            ->assertSee($this->shop->formatMoney($reports->stockValueAtCost()));
    }

    public function test_the_stock_valuation_report_uses_the_same_rule(): void
    {
        $this->plugBelowZero();

        $valuation = app(ReportService::class)->stockValuation(true);

        $this->assertSame($this->expectedStockValue(), array_sum(array_column($valuation, 'cost_value')));
        $this->assertGreaterThanOrEqual(0, min(array_column($valuation, 'quantity')));
    }

    public function test_the_negative_stock_filter_and_card_show_only_items_below_zero(): void
    {
        $plug = $this->plugBelowZero();
        $oil = $this->variant('MOTUL');
        $this->actingAs($this->owner);

        Livewire::test(ListStockLevels::class)
            ->filterTable('negative_stock')
            ->assertCanSeeTableRecords([$plug])
            ->assertCanNotSeeTableRecords([$oil])
            ->assertCountTableRecords(1);

        $this->assertSame(1, app(ReportService::class)->negativeStockCount());

        Livewire::test(StockOverview::class)
            ->assertSee('Items below zero')
            ->assertSee('Do a stock count');
    }

    public function test_negative_on_hand_is_red_with_a_warning(): void
    {
        $this->plugBelowZero();
        $this->actingAs($this->owner);

        Livewire::test(ListStockLevels::class)
            ->filterTable('negative_stock')
            ->assertSeeHtml('Stock below zero — do a stock count')
            ->assertSeeHtml('fi-color-danger');
    }

    public function test_owner_gets_stock_in_and_a_menu_with_adjust_and_history_and_rows_open_the_history(): void
    {
        $plug = $this->variant('CPR8EA-9');
        $this->actingAs($this->owner);

        Livewire::test(ListStockLevels::class)
            ->assertTableActionVisible('stockIn', $plug)
            ->assertTableActionVisible('adjust', $plug)
            ->assertTableActionVisible('history', $plug)
            ->assertSeeHtml(e(StockLevelResource::historyUrl($plug)));
    }

    public function test_users_who_cannot_change_stock_see_neither_stock_in_nor_adjust(): void
    {
        $plug = $this->variant('CPR8EA-9');

        // A cashier is kept out of the panel altogether, and has no stock actions anyway.
        $this->actingAs($this->cashier)->get(StockLevelResource::getUrl())->assertForbidden();

        Livewire::test(ListStockLevels::class)
            ->assertTableActionHidden('stockIn', $plug)
            ->assertTableActionHidden('adjust', $plug);

        // An admin user who may only look at stock gets the history but no changes.
        $viewer = User::factory()->create(['email' => 'stock-viewer@pos.test']);
        $viewer->forceFill(['shop_id' => $this->shop->getKey()])->save();
        $viewer->givePermissionTo(['admin.access', 'stock.view']);
        $this->actingAs($viewer);

        $this->get(StockLevelResource::getUrl())->assertOk()->assertDontSee('Stock in');

        Livewire::test(ListStockLevels::class)
            ->assertTableActionHidden('stockIn', $plug)
            ->assertTableActionHidden('adjust', $plug)
            ->assertTableActionVisible('history', $plug);
    }

    /**
     * Stacking itself needs a browser (tests/e2e/admin-tables.spec.ts); this
     * guards the pieces: the ⋮ menu floats (fixed, flips, anchored right), and
     * no style of ours pins the actions cell over the other columns.
     */
    public function test_the_row_menu_floats_above_the_table(): void
    {
        $this->actingAs($this->owner);

        $html = $this->get(StockLevelResource::getUrl())->assertOk()->getContent();

        $this->assertStringContainsString('x-float.placement.bottom-end.flip.teleport', $html);
        $this->assertStringNotContainsString('td:has(> .fi-ta-actions', $html);
    }
}
