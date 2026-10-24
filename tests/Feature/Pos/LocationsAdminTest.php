<?php

namespace Tests\Feature\Pos;

use App\Exceptions\PosException;
use App\Filament\Pages\Reports\LowStockReport;
use App\Filament\Pages\Reports\StockMovementsReport;
use App\Filament\Pages\Reports\StockValuationReport;
use App\Filament\Resources\Locations\Pages\ManageLocations;
use App\Filament\Resources\StockLevels\Pages\ListStockLevels;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\LocationService;
use App\Services\ProductCatalogService;
use App\Services\StockService;
use Filament\Facades\Filament;
use Livewire\Livewire;

/** multi_location on the demo clinic: the Locations screen, users' default location and location-aware stock screens. */
class LocationsAdminTest extends PharmacyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);
    }

    private function item(float $atGeneral = 100, float $reorderLevel = 0): ProductVariant
    {
        return app(ProductCatalogService::class)->create(
            ['name' => 'Cotton wool 100 g', 'unit' => 'Pack'],
            [['price' => 50_000, 'cost' => 30_000, 'opening_stock' => $atGeneral, 'reorder_level' => $reorderLevel]],
            user: $this->owner,
        )->variants()->firstOrFail();
    }

    /** @param  array<string, mixed>  $changes */
    private function save(Location $location, array $changes): Location
    {
        return app(LocationService::class)->update($location, [
            'name' => $location->name,
            'code' => $location->code,
            'is_default' => $location->is_default,
            'is_active' => $location->is_active,
            'is_sellable' => $location->is_sellable,
            ...$changes,
        ], $this->owner);
    }

    private function refused(callable $attempt, string $message): void
    {
        try {
            $attempt();
            $this->fail("Expected: {$message}");
        } catch (PosException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    public function test_the_seeded_clinic_locations_are_listed_as_they_are(): void
    {
        $this->assertTrue($this->general->is_default);
        $this->assertFalse($this->doctors->is_default);
        $quarantine = Location::query()->where('code', 'QUAR')->sole();
        $this->assertSame([$this->general->getKey() => 'General Pharmacy', $this->doctors->getKey() => "Doctor's Medicines", $quarantine->getKey() => 'Quarantine / Expired'], Location::options(), 'In the profile\'s order.');

        Livewire::test(ManageLocations::class)
            ->assertCanSeeTableRecords([$this->general, $this->doctors])
            ->assertTableColumnStateSet('devices_count', 1, $this->general);
    }

    public function test_the_owner_adds_a_location_and_moves_the_default(): void
    {
        Livewire::test(ManageLocations::class)
            ->callAction('create', data: ['name' => 'Recall shelf', 'code' => 'recall', 'is_sellable' => false, 'is_active' => true, 'is_default' => false, 'sort_order' => 9])
            ->assertHasNoActionErrors();

        $quarantine = Location::query()->where('code', 'RECALL')->sole();
        $this->assertFalse($quarantine->is_sellable);
        $this->assertSame(9, $quarantine->sort_order);

        Livewire::test(ManageLocations::class)
            ->callTableAction('edit', $this->doctors, data: ['name' => "Doctor's Medicines", 'code' => 'DOC', 'is_default' => true, 'is_active' => true, 'is_sellable' => true])
            ->assertHasNoTableActionErrors();

        $this->assertTrue($this->doctors->fresh()->is_default);
        $this->assertFalse($this->general->fresh()->is_default);
        $this->assertSame(1, Location::query()->where('is_default', true)->count(), 'Exactly one default per shop.');
        $this->assertTrue($this->shop->defaultLocation()->is($this->doctors));

        // A duplicate code is refused with a message and saves nothing.
        Livewire::test(ManageLocations::class)
            ->callAction('create', data: ['name' => 'Second general', 'code' => 'GEN', 'is_active' => true, 'is_sellable' => true, 'is_default' => false])
            ->assertNotified('Another location already has the code GEN.');
        $this->assertSame(4, Location::query()->count(), 'GEN, DOC and QUAR from the profile, and the recall shelf.');
    }

    public function test_locations_holding_stock_or_a_till_stay_active(): void
    {
        $empty = app(LocationService::class)->create(['name' => 'Back store', 'code' => 'STORE'], $this->owner);
        $item = $this->item(10);
        app(StockService::class)->stockIn($item, $this->doctors, 5, $this->owner, 30_000);

        $this->refused(fn () => $this->save($this->general, ['is_default' => false]), 'Make another location the default');
        $this->refused(fn () => $this->save($this->general, ['is_active' => false]), 'must be active');
        $this->refused(fn () => $this->save($this->doctors, ['is_active' => false]), 'still holds stock of 1 item(s)');
        $this->refused(fn () => $this->save($this->general, ['is_sellable' => false]), 'Till T1 sells from General Pharmacy');
        $this->refused(fn () => app(LocationService::class)->create(['name' => 'Bad', 'code' => 'no spaces'], $this->owner), 'letters, digits or dashes');
        $this->refused(fn () => app(LocationService::class)->create(['name' => 'X', 'code' => 'X'], $this->cashier), 'permission');

        $this->assertFalse($this->save($empty, ['is_active' => false])->is_active, 'An empty location with no till can be deactivated.');
        $this->assertArrayNotHasKey($empty->getKey(), Location::options());
    }

    public function test_a_user_has_a_default_location_that_stock_screens_start_at(): void
    {
        $store = app(LocationService::class)->create(['name' => 'Back store', 'code' => 'STORE'], $this->owner);

        Livewire::test(EditUser::class, ['record' => $this->cashier->getRouteKey()])
            ->assertFormFieldIsVisible('default_location_id')
            ->fillForm(['default_location_id' => $store->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->cashier->refresh();
        $this->assertSame($store->getKey(), $this->cashier->default_location_id);
        $this->assertTrue($this->cashier->workLocation($this->shop)->is($store));

        // An inactive default falls back to the shop's.
        $this->save($store, ['is_active' => false]);
        $this->assertTrue($this->cashier->fresh()->workLocation($this->shop)->is($this->general));

        // The owner works at DOC: opening stock and Stock In start there.
        $this->owner->forceFill(['default_location_id' => $this->doctors->getKey()])->save();
        $item = $this->item(10);
        $this->assertSame(10.0, $item->stockAt($this->doctors));

        Livewire::test(ListStockLevels::class)
            ->mountTableAction('stockIn', $item)
            ->assertTableActionDataSet(['location_id' => $this->doctors->getKey()]);

        Livewire::test(ListStockLevels::class)
            ->callTableAction('stockIn', $item, data: ['location_id' => $this->doctors->getKey(), 'quantity' => 4, 'unit_cost' => '360.00'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(14.0, $item->stockAt($this->doctors));
        $this->assertSame(0.0, $item->stockAt($this->general));
        // (10 x 30,000 + 4 x 36,000) / 14, at 4 decimal places.
        $this->assertSame('31714.2857', app(StockService::class)->averageCost($item, $this->doctors));
    }

    public function test_stock_levels_filter_by_location_and_actions_pick_a_location(): void
    {
        $item = $this->item(10, reorderLevel: 3);
        app(StockService::class)->stockIn($item, $this->doctors, 5, $this->owner, 36_000);

        Livewire::test(ListStockLevels::class)
            ->assertTableFilterVisible('location')
            ->assertTableColumnStateSet('on_hand', 15, $item)
            ->assertTableColumnStateSet('cost_price', 32_000, $item) // (10 x 30,000 + 5 x 36,000) / 15
            ->filterTable('location', $this->doctors->getKey())
            ->assertTableColumnStateSet('on_hand', 5, $item)
            ->assertTableColumnStateSet('cost_price', 36_000, $item)
            ->assertTableColumnStateSet('stock_value', $this->shop->formatMoney(180_000), $item);

        // Adjust at DOC; GEN is untouched.
        Livewire::test(ListStockLevels::class)
            ->callTableAction('adjust', $item, data: ['location_id' => $this->doctors->getKey(), 'counted_quantity' => 2, 'reason' => 'damaged'])
            ->assertHasNoTableActionErrors();
        $this->assertSame(2.0, $item->stockAt($this->doctors));
        $this->assertSame(10.0, $item->stockAt($this->general));

        // DOC's own reorder level makes it low there, and only there.
        Livewire::test(ListStockLevels::class)
            ->callTableAction('reorderLevel', $item, data: ['location_id' => $this->doctors->getKey(), 'reorder_level' => 4])
            ->assertHasNoTableActionErrors();
        $this->assertSame('4.000', $item->stockLevels()->where('location_id', $this->doctors->getKey())->value('reorder_level'));

        Livewire::test(ListStockLevels::class)
            ->filterTable('location', $this->doctors->getKey())
            ->filterTable('low_stock')
            ->assertCanSeeTableRecords([$item]);
        Livewire::test(ListStockLevels::class)
            ->filterTable('location', $this->general->getKey())
            ->filterTable('low_stock')
            ->assertCanNotSeeTableRecords([$item]);

        // Revalue at DOC only.
        Livewire::test(ListStockLevels::class)
            ->callTableAction('revalue', $item, data: ['location_id' => $this->doctors->getKey(), 'new_cost' => '350', 'reason' => 'Credit note'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('35000.0000', app(StockService::class)->averageCost($item, $this->doctors));
        $this->assertSame('30000.0000', app(StockService::class)->averageCost($item, $this->general));
    }

    public function test_stock_history_and_stock_reports_filter_by_location(): void
    {
        $item = $this->item(10, reorderLevel: 8);
        app(StockService::class)->stockIn($item, $this->doctors, 5, $this->owner, 36_000);
        $atDoctors = StockMovement::query()->where('location_id', $this->doctors->getKey())->get();
        $atGeneral = StockMovement::query()->where('location_id', $this->general->getKey())->get();

        Livewire::test(ListStockMovements::class)
            ->assertTableColumnVisible('location.name')
            ->filterTable('location', $this->doctors->getKey())
            ->assertCanSeeTableRecords($atDoctors)
            ->assertCanNotSeeTableRecords($atGeneral);

        $movements = Livewire::test(StockMovementsReport::class)
            ->assertTableColumnExists('location')
            ->set('location', (string) $this->doctors->getKey());
        $this->assertSame(['quantity' => 5.0, 'value' => 180_000, 'count' => 1], $movements->instance()->totals());

        Livewire::test(StockValuationReport::class)
            ->set('location', (string) $this->doctors->getKey())
            ->assertSee($this->shop->formatMoney(180_000))
            ->assertDontSee($this->shop->formatMoney(480_000));

        Livewire::test(LowStockReport::class)
            ->assertTableColumnExists('location')
            ->assertSee("Doctor's Medicines");
    }
}
