<?php

namespace Tests\Feature\Pos;

use App\Enums\AdjustmentReason;
use App\Enums\StockMovementType;
use App\Exceptions\PosException;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Services\StockService;

class StockServiceTest extends PosTestCase
{
    private StockService $stock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stock = app(StockService::class);
    }

    public function test_opening_stock_is_recorded_in_the_ledger(): void
    {
        $plug = $this->variant('CPR8EA-9');

        $this->assertSame(40.0, $this->stockOf($plug));
        $this->assertSame(1, $plug->stockMovements()->where('type', StockMovementType::Opening)->count());
    }

    public function test_stock_in_and_sale_movements_update_the_balance(): void
    {
        $plug = $this->variant('CPR8EA-9');

        $this->stock->stockIn($plug, $this->location, 10, $this->owner, note: 'Delivery from distributor');
        $movement = $this->stock->record($plug, $this->location, StockMovementType::Sale, 3, $this->cashier);

        $this->assertSame(47.0, $this->stockOf($plug));
        $this->assertSame('-3.000', $movement->quantity);
        $this->assertSame('47.000', $movement->balance_after);
    }

    public function test_cannot_sell_more_than_is_in_stock(): void
    {
        $plug = $this->variant('CPR8EA-9');

        $this->expectException(PosException::class);

        $this->stock->record($plug, $this->location, StockMovementType::Sale, 41, $this->cashier);
    }

    public function test_adjustments_require_a_reason(): void
    {
        $this->expectException(PosException::class);

        $this->stock->record($this->variant('CPR8EA-9'), $this->location, StockMovementType::AdjustmentOut, 1, $this->owner);
    }

    public function test_stock_take_adjusts_to_the_counted_quantity(): void
    {
        $plug = $this->variant('CPR8EA-9');

        $movement = $this->stock->adjustTo($plug, $this->location, 37, AdjustmentReason::CountCorrection, $this->owner, 'Monthly stock take');

        $this->assertSame(StockMovementType::AdjustmentOut, $movement->type);
        $this->assertSame(37.0, $this->stockOf($plug));
        $this->assertNull($this->stock->adjustTo($plug, $this->location, 37, AdjustmentReason::CountCorrection, $this->owner));
    }

    public function test_ledger_entries_are_immutable(): void
    {
        $movement = StockMovement::query()->firstOrFail();

        $this->expectException(\LogicException::class);

        $movement->update(['note' => 'tampered']);
    }

    public function test_rebuild_repairs_a_tampered_stock_level(): void
    {
        $plug = $this->variant('CPR8EA-9');
        StockLevel::query()->where('product_variant_id', $plug->getKey())->update(['quantity' => 999]);

        $fixed = $this->stock->rebuildLevels();

        $this->assertSame(1, $fixed);
        $this->assertSame(40.0, $this->stockOf($plug));
    }
}
