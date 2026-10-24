<?php

namespace Tests\Feature\Pos;

use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Filament\Resources\Sales\Pages\ListSales;
use App\Filament\Resources\Sales\Pages\ViewSale;
use App\Models\Sale;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use Livewire\Livewire;

class AdminSalesTest extends AdminTestCase
{
    private function sale(int $quantity = 3, ?User $cashier = null): Sale
    {
        return app(CheckoutService::class)->checkout($this->cart(
            [new CartLine($this->variant('CPR8EA-9')->getKey(), $quantity)],
            cashier: $cashier,
        ));
    }

    public function test_owner_voids_a_sale_with_a_reason_and_stock_returns(): void
    {
        $sale = $this->sale(3);
        $plug = $this->variant('CPR8EA-9');
        $this->assertSame(37.0, $this->stockOf($plug));

        $this->actingAs($this->owner);

        Livewire::test(ViewSale::class, ['record' => $sale->getRouteKey()])
            ->callAction('void', data: ['reason' => 'Customer changed their mind'])
            ->assertHasNoActionErrors();

        $sale->refresh();

        $this->assertSame(SaleStatus::Voided, $sale->status);
        $this->assertSame('Customer changed their mind', $sale->void_reason);
        $this->assertSame(40.0, $this->stockOf($plug));
        $this->assertTrue($plug->stockMovements()->where('type', StockMovementType::SaleVoid)->exists());
    }

    public function test_void_needs_a_reason(): void
    {
        $sale = $this->sale();
        $this->actingAs($this->owner);

        Livewire::test(ViewSale::class, ['record' => $sale->getRouteKey()])
            ->callAction('void', data: ['reason' => ''])
            ->assertHasActionErrors(['reason' => 'required']);

        $this->assertSame(SaleStatus::Completed, $sale->fresh()->status);
    }

    public function test_cashier_cannot_void_or_return(): void
    {
        $sale = $this->sale(2, $this->cashier);
        $this->actingAs($this->cashier);

        Livewire::test(ViewSale::class, ['record' => $sale->getRouteKey()])
            ->assertSuccessful()
            ->assertActionHidden('void')
            ->assertActionHidden('return');
    }

    public function test_owner_returns_part_of_a_sale_and_stock_goes_back(): void
    {
        $sale = $this->sale(3);
        $item = $sale->items->firstOrFail();
        $plug = $this->variant('CPR8EA-9');

        $this->actingAs($this->owner);

        Livewire::test(ViewSale::class, ['record' => $sale->getRouteKey()])
            ->callAction('return', data: [
                'items' => [['sale_item_id' => $item->getKey(), 'quantity' => 1, 'restock' => true]],
                'refund_method' => 'cash',
                'reason' => 'wrong_item',
            ])
            ->assertHasNoActionErrors();

        $sale->refresh();

        $this->assertSame(SaleStatus::PartiallyReturned, $sale->status);
        $this->assertSame(38.0, $this->stockOf($plug));
        $this->assertGreaterThan(0, $sale->refunded_total);
    }

    public function test_returning_nothing_is_refused(): void
    {
        $sale = $this->sale(3);
        $item = $sale->items->firstOrFail();
        $this->actingAs($this->owner);

        Livewire::test(ViewSale::class, ['record' => $sale->getRouteKey()])
            ->callAction('return', data: [
                'items' => [['sale_item_id' => $item->getKey(), 'quantity' => 0, 'restock' => true]],
                'refund_method' => 'cash',
                'reason' => 'changed_mind',
            ]);

        $this->assertSame(SaleStatus::Completed, $sale->fresh()->status);
        $this->assertSame(37.0, $this->stockOf($this->variant('CPR8EA-9')));
    }

    public function test_a_voided_sale_has_no_further_actions(): void
    {
        $sale = $this->sale();
        app(CheckoutService::class)->void($sale, $this->owner, 'Mistake');
        $this->actingAs($this->owner);

        Livewire::test(ViewSale::class, ['record' => $sale->getRouteKey()])
            ->assertActionHidden('void')
            ->assertActionHidden('return');
    }

    public function test_cashier_only_sees_their_own_sales_unless_they_may_view_all(): void
    {
        $mine = $this->sale(1, $this->cashier);
        $owners = $this->sale(1, $this->owner);

        // An admin user with only sales.view (e.g. a future manager role) sees just their own sales.
        $this->cashier->givePermissionTo('admin.access');
        $this->actingAs($this->cashier);

        Livewire::test(ListSales::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$owners]);

        $this->get(route('filament.admin.resources.sales.view', $owners))->assertNotFound();

        $this->actingAs($this->owner);

        Livewire::test(ListSales::class)->assertCanSeeTableRecords([$mine, $owners]);
    }

    public function test_sale_screens_never_expose_cost_prices(): void
    {
        $sale = $this->sale();
        $this->actingAs($this->owner);

        $html = $this->get(route('filament.admin.resources.sales.view', $sale))->assertSuccessful()->getContent();

        $unitCost = $sale->items->firstOrFail()->unit_cost;

        $this->assertGreaterThan(0, $unitCost);
        $this->assertStringNotContainsStringIgnoringCase('unit_cost', $html);
        // The navigation may link to the profit report; the sale itself must not show cost or profit figures.
        $this->assertStringNotContainsString($this->shop->formatMoney($unitCost), $html);
        $this->assertStringNotContainsString(number_format($unitCost / 100, 2), $html);
        $this->assertStringNotContainsStringIgnoringCase('Gross profit', $html);
    }
}
