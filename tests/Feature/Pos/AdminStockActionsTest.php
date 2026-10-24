<?php

namespace Tests\Feature\Pos;

use App\Enums\StockMovementType;
use App\Exceptions\PosException;
use App\Filament\Resources\StockLevels\Pages\ListStockLevels;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Models\StockMovement;
use App\Services\StockService;
use Livewire\Livewire;

class AdminStockActionsTest extends AdminTestCase
{
    public function test_owner_stock_in_goes_through_the_ledger(): void
    {
        $this->actingAs($this->owner);
        $plug = $this->variant('CPR8EA-9');

        Livewire::test(ListStockLevels::class)
            ->callTableAction('stockIn', $plug, data: ['quantity' => 10, 'unit_cost' => '900.50', 'note' => 'Delivery 42'])
            ->assertHasNoTableActionErrors();

        $movement = StockMovement::query()->latest('id')->firstOrFail();

        $this->assertSame(50.0, $this->stockOf($plug));
        $this->assertSame(StockMovementType::StockIn, $movement->type);
        $this->assertSame('10.000', $movement->quantity);
        $this->assertSame(90_050, $movement->unit_cost);
        $this->assertSame($this->owner->getKey(), $movement->user_id);
        $this->assertSame('Delivery 42', $movement->note);
    }

    public function test_stock_in_rejects_a_zero_quantity(): void
    {
        $this->actingAs($this->owner);
        $plug = $this->variant('CPR8EA-9');

        Livewire::test(ListStockLevels::class)
            ->callTableAction('stockIn', $plug, data: ['quantity' => 0])
            ->assertHasTableActionErrors(['quantity']);

        $this->assertSame(40.0, $this->stockOf($plug));
    }

    public function test_adjustment_records_the_difference_with_its_reason(): void
    {
        $this->actingAs($this->owner);
        $plug = $this->variant('CPR8EA-9');

        Livewire::test(ListStockLevels::class)
            ->callTableAction('adjust', $plug, data: ['counted_quantity' => 37, 'reason' => 'damaged', 'note' => 'Dropped in storage'])
            ->assertHasNoTableActionErrors();

        $movement = StockMovement::query()->latest('id')->firstOrFail();

        $this->assertSame(37.0, $this->stockOf($plug));
        $this->assertSame(StockMovementType::AdjustmentOut, $movement->type);
        $this->assertSame('-3.000', $movement->quantity);
        $this->assertSame('damaged', $movement->reason_code);
        $this->assertSame('Damaged', $movement->reasonLabel());
        $this->assertSame('Dropped in storage', $movement->note);
    }

    public function test_adjustment_without_a_reason_is_refused_and_changes_nothing(): void
    {
        $this->actingAs($this->owner);
        $plug = $this->variant('CPR8EA-9');
        $movements = StockMovement::query()->count();

        Livewire::test(ListStockLevels::class)
            ->callTableAction('adjust', $plug, data: ['counted_quantity' => 30, 'reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);

        $this->assertSame(40.0, $this->stockOf($plug));
        $this->assertSame($movements, StockMovement::query()->count());
    }

    public function test_adjusting_to_the_current_count_records_nothing(): void
    {
        $this->actingAs($this->owner);
        $plug = $this->variant('CPR8EA-9');
        $movements = StockMovement::query()->count();

        Livewire::test(ListStockLevels::class)
            ->callTableAction('adjust', $plug, data: ['counted_quantity' => 40, 'reason' => 'count_correction'])
            ->assertHasNoTableActionErrors();

        $this->assertSame($movements, StockMovement::query()->count());
    }

    public function test_cashier_can_view_stock_but_has_no_stock_actions(): void
    {
        $this->actingAs($this->cashier);
        $plug = $this->variant('CPR8EA-9');

        Livewire::test(ListStockLevels::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$plug])
            ->assertTableActionHidden('stockIn', $plug)
            ->assertTableActionHidden('adjust', $plug);
    }

    public function test_the_stock_service_itself_refuses_users_without_the_permission(): void
    {
        $this->actingAs($this->cashier);
        $plug = $this->variant('CPR8EA-9');

        $this->expectException(PosException::class);

        app(StockService::class)->stockIn($plug, $this->location, 5, $this->cashier);
    }

    public function test_cashier_never_sees_cost_or_stock_value(): void
    {
        $this->actingAs($this->cashier);

        Livewire::test(ListStockLevels::class)
            ->assertTableColumnHidden('cost_price')
            ->assertTableColumnHidden('stock_value');

        Livewire::test(ListStockMovements::class)->assertTableColumnHidden('unit_cost');
    }

    public function test_owner_sees_cost_and_stock_value(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ListStockLevels::class)
            ->assertTableColumnVisible('cost_price')
            ->assertTableColumnVisible('stock_value');
    }

    public function test_low_stock_filter_shows_only_items_at_or_below_their_reorder_level(): void
    {
        $this->actingAs($this->owner);
        $plug = $this->variant('CPR8EA-9');
        $oil = $this->variant('MOTUL-3000');

        $plug->update(['reorder_level' => 100]);
        $oil->update(['reorder_level' => 1]);

        Livewire::test(ListStockLevels::class)
            ->filterTable('low_stock')
            ->assertCanSeeTableRecords([$plug])
            ->assertCanNotSeeTableRecords([$oil]);
    }

    public function test_movement_history_can_be_filtered_to_one_variant(): void
    {
        $this->actingAs($this->owner);
        $plug = $this->variant('CPR8EA-9');
        $oil = $this->variant('MOTUL-3000');

        $plugMovement = $plug->stockMovements()->firstOrFail();
        $oilMovement = $oil->stockMovements()->firstOrFail();

        Livewire::test(ListStockMovements::class)
            ->filterTable('variant', $plug->getKey())
            ->assertCanSeeTableRecords([$plugMovement])
            ->assertCanNotSeeTableRecords([$oilMovement]);
    }
}
