<?php

namespace Tests\Feature\Pos;

use App\Enums\AdjustmentReason;
use App\Enums\DosageForm;
use App\Enums\DrugSchedule;
use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Enums\StockMovementType;
use App\Exceptions\PosException;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Services\CheckoutService;
use App\Services\Data\BatchInput;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\DrugData;
use App\Services\Data\PaymentInput;
use App\Services\LocationService;
use App\Services\ProductCatalogService;
use App\Services\ReportService;
use App\Services\ReturnService;
use App\Services\StockService;
use App\Support\UnitCost;

/**
 * Weighted average cost per variant per location (docs/PHARMACY.md §5), held
 * at 4 decimal places (Q9), and sale lines that take stock from their own
 * location, on the demo clinic (General Pharmacy and Doctor's Medicines).
 */
class PerLocationCostTest extends PharmacyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A clinic cashier with no allowed locations works at the default location only
        // (docs/PHARMACY.md §4.4): this one may use both counters.
        app(LocationService::class)->setAllowedLocations($this->cashier, [(int) $this->general->getKey(), (int) $this->doctors->getKey()], $this->owner);
    }

    /** Paracetamol tablets with a box of 10, opening stock at General Pharmacy. */
    private function item(int $cost = 1_000, float $atGeneral = 100, float $reorderLevel = 0): ProductVariant
    {
        return app(ProductCatalogService::class)->create(
            ['name' => 'Panadol 500 mg Tablets', 'unit' => 'Tablet'],
            [[
                'price' => 2_000,
                'cost' => $cost,
                'opening_stock' => $atGeneral,
                'opening_batch' => new BatchInput('PN-GEN', '12/2028'),
                'opening_location_id' => $this->general->getKey(),
                'reorder_level' => $reorderLevel,
                'packs' => [['name' => 'Box', 'factor' => 10, 'price' => 18_000]],
            ]],
            user: $this->owner,
            drug: new DrugData(['Paracetamol'], DosageForm::Tablet, DrugSchedule::Otc),
        )->variants()->firstOrFail();
    }

    private function stockIn(ProductVariant $variant, Location $location, float $quantity, int|string $unitCost): void
    {
        app(StockService::class)->stockIn($variant, $location, $quantity, $this->owner, $unitCost, batch: new BatchInput('PN-'.$location->code, '12/2028'));
    }

    private function average(ProductVariant $variant, Location $location): string
    {
        return app(StockService::class)->averageCost($variant, $location);
    }

    /**
     * @param  list<CartLine>  $lines
     */
    private function sell(array $lines, bool $offline = false): Sale
    {
        return app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->till,
            cashier: $this->cashier,
            lines: $lines,
            payments: [new PaymentInput(PaymentMethod::Cash, 10_000_000)],
            isOfflineSync: $offline,
            soldAt: $offline ? now() : null,
        ));
    }

    private function boxOf(ProductVariant $variant): int
    {
        return (int) $variant->units()->where('name', 'Box')->value('id');
    }

    public function test_each_location_keeps_its_own_average_cost(): void
    {
        $panadol = $this->item(1_000, 100);
        $this->stockIn($panadol, $this->doctors, 50, 1_300);

        $this->assertSame('1000.0000', $this->average($panadol, $this->general), 'A receipt at DOC leaves GEN alone.');
        $this->assertSame('1300.0000', $this->average($panadol, $this->doctors), 'Nothing on hand at DOC: its average is the unit cost.');

        $this->stockIn($panadol, $this->general, 100, 1_100);

        $this->assertSame('1050.0000', $this->average($panadol, $this->general));
        $this->assertSame('1300.0000', $this->average($panadol, $this->doctors));

        // The variant keeps the blend: (200 x 1,050 + 50 x 1,300) / 250 = 1,100, and the latest receipt's cost.
        $panadol->refresh();
        $this->assertSame(1_100, $panadol->cost_price);
        $this->assertSame(1_100, $panadol->last_cost_price);
        $this->assertSame('1100.0000', $panadol->stockLevels()->where('location_id', $this->general->getKey())->value('last_cost'));
        $this->assertSame('1300.0000', $panadol->stockLevels()->where('location_id', $this->doctors->getKey())->value('last_cost'));
    }

    public function test_averages_keep_four_decimal_places_and_sale_lines_use_them(): void
    {
        $panadol = $this->item(1_000, 10);
        $this->stockIn($panadol, $this->general, 20, 1_001); // (10,000 + 20,020) / 30 = 1,000.6667

        $this->assertSame('1000.6667', $this->average($panadol, $this->general));
        $this->assertSame(1_001, $panadol->fresh()->cost_price, 'The variant shows whole minor units.');

        // A box costs 10 x the exact average (10,006.667 -> 10,007), not 10 x a rounded 1,001.
        $sale = $this->sell([new CartLine($panadol->getKey(), 1, unitId: $this->boxOf($panadol))]);
        $this->assertSame(10_007, $sale->items->sole()->unit_cost);

        // A decimal receipt cost is kept exactly for the average (a box of 30 at 100,000).
        $this->stockIn($panadol, $this->doctors, 30, '3333.3333');
        $this->assertSame('3333.3333', $this->average($panadol, $this->doctors));

        $this->assertSame(3_334, UnitCost::toMinor('3333.5'));
        $this->assertSame(100_000, UnitCost::times('3333.3333', 30));
        $this->assertSame('1000.5000', UnitCost::weightedAverage(3, 1_000, 1, 1_002));
        $this->assertSame('1000.0000', UnitCost::weightedAverage(-2, 900, 5, 1_000), 'Below zero on hand: the unit cost.');
        $this->assertSame('1100.0000', UnitCost::blend([[200, '1050'], [50, '1300'], [-3, '9999']]));
        $this->assertNull(UnitCost::blend([[0, '1000'], [-1, '900']]));
    }

    public function test_one_bill_takes_stock_from_two_locations_at_each_ones_cost(): void
    {
        $panadol = $this->item(1_000, 100);
        $this->stockIn($panadol, $this->doctors, 50, 1_300);

        $sale = $this->sell([
            new CartLine($panadol->getKey(), 2),                                         // the till's location (GEN)
            new CartLine($panadol->getKey(), 3, locationId: $this->doctors->getKey()),
        ]);

        $this->assertSame($this->general->getKey(), $sale->location_id, 'The bill is rung up at the till\'s location.');
        $lines = $sale->items->keyBy('location_id');
        $this->assertSame(1_000, $lines[$this->general->getKey()]->unit_cost);
        $this->assertSame(1_300, $lines[$this->doctors->getKey()]->unit_cost);

        $this->assertSame(98.0, $panadol->stockAt($this->general));
        $this->assertSame(47.0, $panadol->stockAt($this->doctors));

        $movements = StockMovement::query()->where('type', StockMovementType::Sale)->get()->keyBy('location_id');
        $this->assertSame(-2.0, (float) $movements[$this->general->getKey()]->quantity);
        $this->assertSame(1_000, $movements[$this->general->getKey()]->unit_cost);
        $this->assertSame(-3.0, (float) $movements[$this->doctors->getKey()]->quantity);
        $this->assertSame(1_300, $movements[$this->doctors->getKey()]->unit_cost);

        // Voiding puts each line back where it came from, at its own cost.
        app(CheckoutService::class)->void($sale, $this->owner, 'Wrong patient');

        $this->assertSame(100.0, $panadol->stockAt($this->general));
        $this->assertSame(50.0, $panadol->stockAt($this->doctors));
        $voids = StockMovement::query()->where('type', StockMovementType::SaleVoid)->get()->keyBy('location_id');
        $this->assertSame(1_300, $voids[$this->doctors->getKey()]->unit_cost);
        $this->assertSame('1300.0000', $this->average($panadol, $this->doctors), 'Voids never change an average.');
    }

    public function test_returns_go_back_to_the_lines_location(): void
    {
        $panadol = $this->item(1_000, 100);
        $this->stockIn($panadol, $this->doctors, 50, 1_300);

        $sale = $this->sell([new CartLine($panadol->getKey(), 3, locationId: $this->doctors->getKey())]);
        app(ReturnService::class)->process($sale, [$sale->items->sole()->getKey() => 2], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);

        $this->assertSame(49.0, $panadol->stockAt($this->doctors));
        $this->assertSame(100.0, $panadol->stockAt($this->general));
        $return = StockMovement::query()->where('type', StockMovementType::Return)->sole();
        $this->assertSame($this->doctors->getKey(), $return->location_id);
        $this->assertSame(1_300, $return->unit_cost);
    }

    public function test_a_line_cannot_sell_from_a_location_that_does_not_sell(): void
    {
        $panadol = $this->item(1_000, 100);
        // The pharmacy profile's Quarantine / Expired location sells nothing.
        $quarantine = Location::query()->where('code', 'QUAR')->sole();

        try {
            $this->sell([new CartLine($panadol->getKey(), 1, locationId: $quarantine->getKey())]);
            $this->fail('A line was sold from a location that does not sell.');
        } catch (PosException $exception) {
            $this->assertStringContainsString('Quarantine', $exception->getMessage());
        }

        $this->assertSame(0, Sale::query()->count());

        // An offline sale already happened: recorded at the till's location and flagged.
        $sale = $this->sell([new CartLine($panadol->getKey(), 1, tillUnitPrice: 2_000, locationId: $quarantine->getKey())], offline: true);

        $this->assertSame($this->general->getKey(), $sale->items->sole()->location_id);
        $this->assertTrue($sale->needs_review);
        $this->assertContains('location', array_column($sale->review_reasons ?? [], 'type'));
        $this->assertSame(99.0, $panadol->stockAt($this->general));
    }

    public function test_revaluing_sets_one_locations_average(): void
    {
        $panadol = $this->item(1_000, 100);
        $this->stockIn($panadol, $this->doctors, 100, 1_300);

        app(ProductCatalogService::class)->revalueCost($panadol, 1_200, 'Supplier credit note', $this->owner, $this->doctors);

        $this->assertSame('1000.0000', $this->average($panadol, $this->general));
        $this->assertSame('1200.0000', $this->average($panadol, $this->doctors));
        $this->assertSame(1_100, $panadol->fresh()->cost_price, '(100 x 1,000 + 100 x 1,200) / 200');

        $log = AuditLog::query()->where('event', 'cost_revalued')->sole();
        $this->assertSameIgnoringKeyOrder(['cost_price' => 1_150, 'location' => 'DOC', 'average_cost' => '1300.0000'], $log->old_values);
        $this->assertSame('1200.0000', $log->new_values['average_cost']);
        $this->assertSame('DOC', $log->new_values['location']);
        $this->assertSame('Supplier credit note', $log->new_values['reason']);
    }

    public function test_a_location_that_never_held_the_item_starts_at_its_blended_cost(): void
    {
        $panadol = $this->item(1_000, 100);

        app(StockService::class)->adjustBatchTo($panadol, $this->doctors, new BatchInput('PN-FOUND', '12/2028'), 5, AdjustmentReason::CountCorrection, $this->owner);

        $this->assertSame('1000.0000', $this->average($panadol, $this->doctors));
        $this->assertSame(1_000, StockMovement::query()->where('type', StockMovementType::AdjustmentIn)->sole()->unit_cost);
    }

    public function test_low_stock_is_per_location_with_each_locations_own_reorder_level(): void
    {
        $panadol = $this->item(1_000, 100, reorderLevel: 20);
        $reports = app(ReportService::class);
        $stock = app(StockService::class);

        $this->assertSame([], $reports->lowStock(), 'GEN has plenty; DOC never carried it, so it is not watched there.');

        $this->stockIn($panadol, $this->doctors, 5, 1_000);
        $rows = $reports->lowStock();
        $this->assertCount(1, $rows);
        $this->assertSame([$this->doctors->getKey(), "Doctor's Medicines", 5.0, 20.0], [$rows[0]['location_id'], $rows[0]['location'], $rows[0]['on_hand'], $rows[0]['reorder_level']]);
        $this->assertSame(1, $reports->lowStockCount());
        $this->assertSame(1, ProductVariant::query()->lowStock($this->doctors->getKey())->count());
        $this->assertSame(0, ProductVariant::query()->lowStock($this->general->getKey())->count());

        // A location's own reorder level overrides the item's; 0 means not watched there.
        $stock->setReorderLevel($panadol, $this->doctors, 0);
        $stock->setReorderLevel($panadol, $this->general, 150);

        $rows = $reports->lowStock();
        $this->assertSame([$this->general->getKey()], array_column($rows, 'location_id'));
        $this->assertSame(150.0, $rows[0]['reorder_level']);
        $this->assertSame([], $reports->lowStock(locationId: $this->doctors->getKey()));
    }

    public function test_stock_values_use_each_locations_average(): void
    {
        $panadol = $this->item(1_000, 100);
        $this->stockIn($panadol, $this->doctors, 50, 1_300);
        $reports = app(ReportService::class);

        $this->assertSame(100_000 + 65_000, $reports->stockValueAtCost());
        $this->assertSame(100_000, $reports->stockValueAtCost($this->general->getKey()));
        $this->assertSame(65_000, $reports->stockValueAtCost($this->doctors->getKey()));

        $atDoctors = $reports->stockValuation(true, $this->doctors->getKey());
        $this->assertSame(50.0, $atDoctors[0]['quantity']);
        $this->assertSame(65_000, $atDoctors[0]['cost_value']);
        $this->assertSame(100_000, $atDoctors[0]['retail_value']);

        // Stock below zero at one location is a count to fix; it does not cancel the other's value.
        $this->sell([new CartLine($panadol->getKey(), 53, tillUnitPrice: 2_000, locationId: $this->doctors->getKey())], offline: true);
        $this->assertSame(-3.0, $panadol->stockAt($this->doctors));
        $this->assertSame(100_000, $reports->stockValueAtCost());
        $this->assertSame(1, $reports->negativeStockCount($this->doctors->getKey()));
        $this->assertSame(0, $reports->negativeStockCount($this->general->getKey()));
    }
}
