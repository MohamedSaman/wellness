<?php

namespace Tests\Feature\Pos;

use App\Enums\AdjustmentReason;
use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Enums\StockMovementType;
use App\Exceptions\PosException;
use App\Filament\Resources\StockLevels\Pages\ListStockLevels;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Models\AuditLog;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use App\Services\ProductCatalogService;
use App\Services\ReturnService;
use App\Services\StockService;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

/** Weighted average cost: Stock In re-averages, nothing else does, the owner can revalue. */
class WeightedAverageCostTest extends AdminTestCase
{
    /** A fresh item with an opening stock of $quantity at $unitCost (minor units). */
    private function item(float $quantity, int $unitCost): ProductVariant
    {
        return app(ProductCatalogService::class)
            ->create(['name' => 'Brake Lever'], [['price' => 250_000, 'cost' => $unitCost, 'opening_stock' => $quantity]], user: $this->owner)
            ->variants()->firstOrFail();
    }

    private function stockIn(ProductVariant $variant, float $quantity, ?int $unitCost): ProductVariant
    {
        app(StockService::class)->stockIn($variant, $this->location, $quantity, $this->owner, $unitCost);

        return $variant->fresh();
    }

    public function test_the_worked_example_10_at_950_plus_10_at_1100_averages_1025(): void
    {
        $lever = $this->item(10, 95_000);
        $this->assertSame(95_000, $lever->cost_price);
        $this->assertSame(95_000, $lever->last_cost_price);

        $lever = $this->stockIn($lever, 10, 110_000);

        $this->assertSame(102_500, $lever->cost_price);
        $this->assertSame(110_000, $lever->last_cost_price);
        $this->assertSame(102_500, StockService::weightedAverageCost(10, 95_000, 10, 110_000));

        // The receipt is recorded at what it cost, and the cost change is in the audit log.
        $this->assertSame(110_000, StockMovement::query()->where('type', StockMovementType::StockIn)->latest('id')->value('unit_cost'));
        $this->assertTrue(AuditLog::query()
            ->where('auditable_type', $lever->getMorphClass())->where('auditable_id', $lever->getKey())
            ->where('event', 'updated')->get()
            ->contains(fn (AuditLog $log): bool => ($log->new_values['cost_price'] ?? null) === 102_500));
    }

    public function test_rounding_is_half_up_and_works_with_part_quantities(): void
    {
        // 3 x 1,000 + 1 x 1,002 = 4,002 over 4 = 1,000.5 -> 1,001 (half up)
        $this->assertSame(1_001, StockService::weightedAverageCost(3, 1_000, 1, 1_002));
        // 3 x 1,000 + 1 x 1,001 = 4,001 over 4 = 1,000.25 -> 1,000
        $this->assertSame(1_000, StockService::weightedAverageCost(3, 1_000, 1, 1_001));
        // 1.5 litres at 2,000 + 0.5 at 3,000 = 4,500 over 2 = 2,250
        $this->assertSame(2_250, StockService::weightedAverageCost(1.5, 2_000, 0.5, 3_000));
        // 2 x 999 + 1 x 1,000 = 2,998 over 3 = 999.33 -> 999
        $this->assertSame(999, StockService::weightedAverageCost(2, 999, 1, 1_000));
    }

    public function test_with_nothing_on_hand_the_new_cost_is_the_unit_cost(): void
    {
        $lever = $this->item(2, 95_000);
        app(CheckoutService::class)->checkout($this->cart([new CartLine($lever->getKey(), 2)]));
        $this->assertSame(0.0, $this->stockOf($lever));

        $this->assertSame(120_000, $this->stockIn($lever, 5, 120_000)->cost_price);
    }

    public function test_with_stock_below_zero_the_new_cost_is_the_unit_cost(): void
    {
        $lever = $this->item(2, 95_000);
        app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->till,
            cashier: $this->cashier,
            lines: [new CartLine($lever->getKey(), 5, tillUnitPrice: 250_000)],
            payments: [new PaymentInput(PaymentMethod::Cash, 1_250_000)],
            isOfflineSync: true,
            soldAt: now(),
        ));
        $this->assertSame(-3.0, $this->stockOf($lever));

        $this->assertSame(130_000, $this->stockIn($lever, 10, 130_000)->cost_price);
    }

    public function test_sales_adjustments_returns_and_voids_never_change_the_average(): void
    {
        $lever = $this->stockIn($this->item(10, 95_000), 10, 110_000); // average 1,025
        $checkout = app(CheckoutService::class);

        $sale = $checkout->checkout($this->cart([new CartLine($lever->getKey(), 4)]));
        $this->assertSame(102_500, $sale->items->first()->unit_cost, 'The sale line snapshots the average at the time of sale.');

        // A later delivery moves the average on; the old sale keeps its cost.
        $lever = $this->stockIn($lever, 4, 130_000); // (16 x 1,025 + 4 x 1,300) / 20 = 1,080
        $this->assertSame(108_000, $lever->cost_price);
        $this->assertSame(102_500, $sale->items->first()->fresh()->unit_cost);

        app(ReturnService::class)->process($sale, [$sale->items->first()->getKey() => 2], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);
        $this->assertSame(108_000, $lever->fresh()->cost_price);
        $this->assertSame(102_500, StockMovement::query()->where('type', StockMovementType::Return)->latest('id')->value('unit_cost'), 'Restocked at the sale line\'s cost.');

        app(StockService::class)->adjustTo($lever, $this->location, 30, AdjustmentReason::CountCorrection, $this->owner);
        app(StockService::class)->adjustTo($lever, $this->location, 25, AdjustmentReason::Damaged, $this->owner);

        $voided = $checkout->checkout($this->cart([new CartLine($lever->getKey(), 1)]));
        $checkout->void($voided, $this->owner, 'Mistake');

        $this->assertSame(108_000, $lever->fresh()->cost_price);
        $this->assertSame(130_000, $lever->fresh()->last_cost_price);
    }

    public function test_a_stock_in_without_a_unit_cost_leaves_the_average_alone(): void
    {
        $lever = $this->item(10, 95_000);

        $this->assertSame(95_000, $this->stockIn($lever, 5, null)->cost_price);
    }

    public function test_the_owner_revalues_the_cost_with_a_reason_in_the_audit_log(): void
    {
        $lever = $this->item(10, 95_000);

        app(ProductCatalogService::class)->revalueCost($lever, 90_000, 'Supplier credit note CN-77', $this->owner);

        $this->assertSame(90_000, $lever->fresh()->cost_price);
        $this->assertSame(95_000, $lever->fresh()->last_cost_price, 'Revaluing does not touch the last cost.');

        $log = AuditLog::query()->where('event', 'cost_revalued')->sole();
        $this->assertSame($this->owner->getKey(), $log->user_id);
        $this->assertSame($lever->getKey(), $log->auditable_id);
        $this->assertSame(['cost_price' => 95_000], $log->old_values);
        $this->assertSame(90_000, $log->new_values['cost_price']);
        $this->assertSame('Supplier credit note CN-77', $log->new_values['reason']);

        // No second, reason-less "updated" entry for the same change.
        $this->assertFalse(AuditLog::query()->where('auditable_id', $lever->getKey())->where('event', 'updated')
            ->get()->contains(fn (AuditLog $entry): bool => ($entry->new_values['cost_price'] ?? null) === 90_000));
    }

    public function test_revaluing_needs_cost_manage_and_a_reason(): void
    {
        $lever = $this->item(10, 95_000);
        $catalog = app(ProductCatalogService::class);

        $manager = User::factory()->create(['email' => 'manager@pos.test']);
        $manager->forceFill(['shop_id' => $this->shop->getKey()])->save();
        $manager->givePermissionTo(['admin.access', 'stock.view', 'cost.view']);

        foreach ([[$manager, 'Fix'], [$this->owner, '  ']] as [$user, $reason]) {
            try {
                $catalog->revalueCost($lever, 90_000, $reason, $user);
                $this->fail('The cost was revalued without permission or reason.');
            } catch (PosException) {
                $this->assertSame(95_000, $lever->fresh()->cost_price);
            }
        }

        $this->actingAs($this->owner);
        Livewire::test(ListStockLevels::class)
            ->callTableAction('revalue', $lever, data: ['new_cost' => '900', 'reason' => ''])
            ->assertHasTableActionErrors(['reason' => 'required']);
        Livewire::test(ListStockLevels::class)
            ->callTableAction('revalue', $lever, data: ['new_cost' => '900', 'reason' => 'Costing mistake'])
            ->assertHasNoTableActionErrors();
        $this->assertSame(90_000, $lever->fresh()->cost_price);

        // Someone who may see costs but not change them gets no Revalue action.
        $this->actingAs($manager);
        Livewire::test(ListStockLevels::class)->assertTableActionHidden('revalue', $lever);
    }

    public function test_the_stock_in_form_starts_at_the_last_cost_and_shows_the_new_average(): void
    {
        $lever = $this->stockIn($this->item(10, 95_000), 10, 110_000); // average 1,025, last 1,100
        $this->actingAs($this->owner);

        Livewire::test(ListStockLevels::class)
            ->mountTableAction('stockIn', $lever)
            ->assertTableActionDataSet(['unit_cost' => '1100.00']);

        // The live hint under Unit cost, worked out the same way StockService will:
        // (20 x 1,025 + 20 x 1,100) / 40 = 1,062.50
        $lever = ProductVariant::query()->withSum('stockLevels as on_hand', 'quantity')->findOrFail($lever->getKey());
        $this->assertSame(
            'New average will be: '.$this->shop->formatMoney(106_250).' (now '.$this->shop->formatMoney(102_500).')',
            StockLevelResource::newAverageHint($lever, '20', '1100'),
        );
        $this->assertStringStartsWith('Enter the quantity and unit cost', StockLevelResource::newAverageHint($lever, '', '1100'));

        app(StockService::class)->stockIn($lever, $this->location, 20, $this->owner, 110_000);
        $this->assertSame(106_250, $lever->fresh()->cost_price, 'The hint matched what Stock In actually did.');
    }

    public function test_both_costs_show_in_stock_levels_for_owners_and_never_for_others(): void
    {
        $lever = $this->stockIn($this->item(10, 95_000), 10, 110_000);
        $this->actingAs($this->owner);

        Livewire::test(ListStockLevels::class)
            ->assertTableColumnVisible('cost_price')
            ->assertTableColumnStateSet('cost_price', 102_500, $lever)
            ->assertTableColumnStateSet('last_cost_price', 110_000, $lever);

        // An admin user without cost.view.
        $viewer = User::factory()->create(['email' => 'viewer@pos.test']);
        $viewer->forceFill(['shop_id' => $this->shop->getKey()])->save();
        $viewer->givePermissionTo(['admin.access', 'stock.view']);
        $this->actingAs($viewer);

        Livewire::test(ListStockLevels::class)
            ->assertTableColumnHidden('cost_price')
            ->assertTableColumnHidden('last_cost_price');
    }

    public function test_cashiers_never_receive_either_cost(): void
    {
        $lever = $this->stockIn($this->item(10, 95_000), 10, 110_000);
        Feature::for($this->shop)->activate('module:offline_sync');

        $this->assertArrayNotHasKey('cost_price', $lever->toArray());
        $this->assertArrayNotHasKey('last_cost_price', $lever->toArray());

        $search = $this->actingAs($this->cashier)
            ->getJson(route('pos.search', ['device' => $this->till->uuid, 'q' => 'Brake Lever']))
            ->assertOk();
        $this->assertSame($lever->getKey(), $search->json('results.0.variant_id'));
        $this->assertStringNotContainsString('cost', strtolower((string) $search->getContent()));

        $this->app['auth']->forgetGuards();
        $catalog = $this->withToken($this->till->issueSyncToken('till')->plainTextToken)->getJson(route('pos.sync.catalog'))->assertOk();
        $this->assertStringNotContainsString('cost', strtolower((string) $catalog->getContent()));

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->cashier)->get(route('filament.admin.resources.stock-levels.index'))->assertForbidden();
    }
}
