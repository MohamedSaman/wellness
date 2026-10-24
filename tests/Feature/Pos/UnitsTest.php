<?php

namespace Tests\Feature\Pos;

use App\Enums\AdjustmentReason;
use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Enums\SaleStatus;
use App\Enums\StockMovementType;
use App\Exceptions\PosException;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\VariantUnit;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use App\Services\ProductCatalogService;
use App\Services\ReportService;
use App\Services\ReturnService;
use App\Services\StockService;
use App\Support\ReportPeriod;
use Illuminate\Support\Str;

/**
 * Units and packs (docs/PHARMACY.md §4.2): one stock of tablets sold loose,
 * by the strip or by the box, each at its own price.
 */
class UnitsTest extends PharmacyTestCase
{
    /** Panadol: Tablet (base, 15.00), Strip of 10 (140.00), Box of 30 (400.00); 300 tablets at 10.00. */
    private function panadol(array $product = [], array $variant = []): ProductVariant
    {
        return app(ProductCatalogService::class)->create(
            ['name' => 'Panadol 500 mg Tablets', 'unit' => 'Tablet', 'whole_units_only' => true, ...$product],
            [[
                'price' => 1_500,
                'cost' => 1_000,
                'opening_stock' => 300,
                'packs' => [
                    ['name' => 'Strip', 'factor' => 10, 'price' => 14_000, 'barcode' => '4790000000101'],
                    ['name' => 'Box', 'factor' => 30, 'price' => 40_000, 'barcode' => '4790000000102'],
                ],
                ...$variant,
            ]],
            user: $this->owner,
        )->variants()->firstOrFail();
    }

    private function unit(ProductVariant $variant, string $name): VariantUnit
    {
        return $variant->units()->where('name', $name)->firstOrFail();
    }

    /** @param  list<CartLine>  $lines */
    private function sell(array $lines, bool $offline = false): Sale
    {
        return app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->till,
            cashier: $this->cashier,
            lines: $lines,
            payments: [new PaymentInput(PaymentMethod::Cash, 10_000_000)],
            uuid: (string) Str::uuid(),
            isOfflineSync: $offline,
            soldAt: $offline ? now()->subMinutes(5) : null,
        ));
    }

    private function stock(ProductVariant $variant): float
    {
        return $variant->stockAt($this->general);
    }

    public function test_a_box_a_strip_and_loose_tablets_sell_at_their_own_prices_and_stock_drops_in_tablets(): void
    {
        $panadol = $this->panadol();

        $sale = $this->sell([
            new CartLine($panadol->getKey(), 1, unitId: $this->unit($panadol, 'Box')->getKey()),
            new CartLine($panadol->getKey(), 2, unitId: $this->unit($panadol, 'Strip')->getKey()),
            new CartLine($panadol->getKey(), 5),
        ]);

        $lines = $sale->items->keyBy('unit_name');

        $this->assertSame(40_000, $lines['Box']->unit_price);
        $this->assertSame(14_000, $lines['Strip']->unit_price);
        $this->assertSame(1_500, $lines['Tablet']->unit_price);
        $this->assertSame(40_000 + 28_000 + 7_500, $sale->grand_total);

        $this->assertSame(30.0, (float) $lines['Box']->base_quantity);
        $this->assertSame(20.0, (float) $lines['Strip']->base_quantity);
        $this->assertSame(5.0, (float) $lines['Tablet']->base_quantity);
        $this->assertSame(30.0, (float) $lines['Box']->unit_factor);
        $this->assertSame('Panadol 500 mg Tablets (Box)', $lines['Box']->description);
        $this->assertSame('Panadol 500 mg Tablets', $lines['Tablet']->description);

        // Cost per sold unit on the line (a box is 30 tablets' average); per tablet on the ledger.
        $this->assertSame(30_000, $lines['Box']->unit_cost);
        $this->assertSame(1_000, $lines['Tablet']->unit_cost);

        $this->assertSame(300.0 - 30 - 20 - 5, $this->stock($panadol));
        $movements = StockMovement::query()->where('type', StockMovementType::Sale)->orderBy('id')->get();
        $this->assertSame([-30.0, -20.0, -5.0], $movements->map(fn (StockMovement $m) => (float) $m->quantity)->all());
        $this->assertSame([1_000], $movements->pluck('unit_cost')->unique()->values()->all());
    }

    public function test_the_quote_and_checkout_endpoints_take_a_unit(): void
    {
        $panadol = $this->panadol();
        $box = $this->unit($panadol, 'Box');

        $payload = [
            'device' => $this->till->uuid,
            'uuid' => (string) Str::uuid(),
            'lines' => [['variant_id' => $panadol->getKey(), 'unit_id' => $box->getKey(), 'quantity' => 2]],
            'payments' => [['method' => 'cash', 'amount' => '1000']],
        ];

        $this->actingAs($this->cashier)->postJson(route('pos.quote'), $payload)
            ->assertOk()
            ->assertJsonPath('totals.grand_total', 80_000)
            ->assertJsonPath('lines.0.unit_name', 'Box')
            ->assertJsonPath('lines.0.base_quantity', 60);

        $this->actingAs($this->cashier)->postJson(route('pos.checkout'), $payload)->assertOk();

        $this->assertSame(240.0, $this->stock($panadol));
    }

    public function test_quantities_must_be_whole_in_the_unit_sold_received_or_counted(): void
    {
        $panadol = $this->panadol();
        $stock = app(StockService::class);
        $box = $this->unit($panadol, 'Box')->getKey();
        $strip = $this->unit($panadol, 'Strip')->getKey();

        $refused = [
            'half a tablet' => [fn () => $this->sell([new CartLine($panadol->getKey(), 0.5)]), '0.5 Tablet'],
            // 15 whole tablets, but not a whole number of boxes.
            'half a box' => [fn () => $this->sell([new CartLine($panadol->getKey(), 0.5, unitId: $box)]), '0.5 Box'],
            'a strip and a half' => [fn () => $this->sell([new CartLine($panadol->getKey(), 1.5, unitId: $strip)]), '1.5 Strip'],
            'stock in' => [fn () => $stock->stockIn($panadol, $this->general, 2.5, $this->owner), '2.5 Tablet'],
            'stock count' => [fn () => $stock->adjustTo($panadol, $this->general, 10.5, AdjustmentReason::CountCorrection, $this->owner), '10.5 Tablet'],
        ];

        foreach ($refused as $what => [$attempt, $quantity]) {
            try {
                $attempt();
                $this->fail("{$what} was accepted.");
            } catch (PosException $exception) {
                $this->assertStringContainsString('whole units only', $exception->getMessage(), $what);
                $this->assertStringContainsString("{$quantity} is not allowed", $exception->getMessage(), $what);
            }
        }

        $this->assertSame(300.0, $this->stock($panadol));

        $this->sell([
            new CartLine($panadol->getKey(), 1, unitId: $box),
            new CartLine($panadol->getKey(), 1, unitId: $strip),
            new CartLine($panadol->getKey(), 3),
        ]);
        $this->assertSame(300.0 - 30 - 10 - 3, $this->stock($panadol));
    }

    public function test_the_quote_refuses_half_a_box(): void
    {
        $panadol = $this->panadol();

        $this->actingAs($this->cashier)->postJson(route('pos.quote'), [
            'device' => $this->till->uuid,
            'lines' => [['variant_id' => $panadol->getKey(), 'unit_id' => $this->unit($panadol, 'Box')->getKey(), 'quantity' => 0.5]],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, '0.5 Box is not allowed'));
    }

    public function test_a_part_tablet_sold_offline_is_recorded_and_flagged_not_rejected(): void
    {
        $panadol = $this->panadol();

        $sale = $this->sell([
            new CartLine($panadol->getKey(), 2.5, tillUnitPrice: 1_500),
            new CartLine($panadol->getKey(), 0.5, tillUnitPrice: 40_000, unitId: $this->unit($panadol, 'Box')->getKey()),
        ], offline: true);

        $this->assertTrue($sale->needs_review);
        $flagged = array_values(array_filter($sale->review_reasons, fn (array $reason): bool => $reason['type'] === 'quantity'));
        $this->assertCount(2, $flagged);
        $this->assertStringContainsString('2.5 Tablet', $flagged[0]['message']);
        $this->assertStringContainsString('0.5 Box', $flagged[1]['message']);
        $this->assertSame(300 - 2.5 - 15, $this->stock($panadol));

        // The count afterwards is whole again; the correction itself is a part tablet.
        app(StockService::class)->adjustTo($panadol, $this->general, 282, AdjustmentReason::CountCorrection, $this->owner);
        $this->assertSame(282.0, $this->stock($panadol));

        // The half tablet can still go back as the last of its line.
        $tablets = $sale->items->firstWhere('unit_name', 'Tablet');
        app(ReturnService::class)->process($sale, [$tablets->getKey() => 2.5], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);
        $this->assertSame(284.5, $this->stock($panadol));
    }

    public function test_a_product_whose_only_unit_is_the_bottle_always_sells_and_allow_unit_sale_only_governs_larger_packs(): void
    {
        // Syrup: allow_unit_sale off by default, no carton yet (Q16).
        $syrup = app(ProductCatalogService::class)->create(
            ['name' => 'Piriton Syrup 60 ml', 'unit' => 'Bottle', 'whole_units_only' => true],
            [['price' => 45_000, 'opening_stock' => 48, 'allow_unit_sale' => false]],
            user: $this->owner,
        )->variants()->firstOrFail();

        $this->sell([new CartLine($syrup->getKey(), 1)]);
        $this->assertSame(47.0, $this->stock($syrup));

        // A carton is set up: now allow_unit_sale (off) holds back the single bottle.
        $this->editVariant($syrup, ['packs' => [['name' => 'Carton', 'factor' => 12, 'price' => 500_000]]]);
        $carton = $this->unit($syrup, 'Carton');

        $this->assertRefused(fn () => $this->sell([new CartLine($syrup->getKey(), 1)]), 'not sold by the Bottle');
        $this->sell([new CartLine($syrup->getKey(), 1, unitId: $carton->getKey())]);
        $this->assertSame(35.0, $this->stock($syrup));

        // Carton deactivated: the bottle is the only unit again, so it sells.
        $this->editVariant($syrup, ['packs' => [['id' => $carton->getKey(), 'name' => 'Carton', 'factor' => 12, 'is_active' => false]]]);
        $this->sell([new CartLine($syrup->getKey(), 1)]);
        $this->assertRefused(fn () => $this->sell([new CartLine($syrup->getKey(), 1, unitId: $carton->getKey())]), 'no longer sold by the Carton');
    }

    public function test_single_vials_sell_alongside_the_box(): void
    {
        $vial = app(ProductCatalogService::class)->create(
            ['name' => 'Ceftriaxone 1 g Injection', 'unit' => 'Vial', 'whole_units_only' => true],
            [['price' => 60_000, 'opening_stock' => 20, 'allow_unit_sale' => true, 'packs' => [['name' => 'Box', 'factor' => 10, 'price' => 550_000]]]],
            user: $this->owner,
        )->variants()->firstOrFail();

        $this->sell([
            new CartLine($vial->getKey(), 1, unitId: $this->unit($vial, 'Box')->getKey()),
            new CartLine($vial->getKey(), 3),
        ]);

        $this->assertSame(7.0, $this->stock($vial));
    }

    public function test_a_pack_without_a_price_cannot_be_sold(): void
    {
        $panadol = $this->panadol();
        $this->editVariant($panadol, ['packs' => [['name' => 'Jar', 'factor' => 100]]]);

        $this->assertRefused(
            fn () => $this->sell([new CartLine($panadol->getKey(), 1, unitId: $this->unit($panadol, 'Jar')->getKey())]),
            'no price for a Jar',
        );
    }

    public function test_a_unit_of_another_item_is_refused(): void
    {
        $panadol = $this->panadol();
        $other = app(ProductCatalogService::class)->create(['name' => 'Other', 'unit' => 'Tablet'], [['price' => 100]], user: $this->owner)->variants()->firstOrFail();

        $this->assertRefused(
            fn () => $this->sell([new CartLine($other->getKey(), 1, unitId: $this->unit($panadol, 'Box')->getKey())]),
            'not one of',
        );
    }

    public function test_changing_a_pack_factor_leaves_sold_lines_alone_and_a_void_puts_back_what_was_taken(): void
    {
        $panadol = $this->panadol();
        $box = $this->unit($panadol, 'Box');

        $sale = $this->sell([new CartLine($panadol->getKey(), 2, unitId: $box->getKey())]);
        $this->assertSame(240.0, $this->stock($panadol));

        // The supplier now packs 28 to a box.
        $this->editVariant($panadol, ['packs' => [['id' => $box->getKey(), 'name' => 'Box', 'factor' => 28, 'price' => 38_000]]]);

        $line = $sale->items()->firstOrFail();
        $this->assertSame(30.0, (float) $line->unit_factor);
        $this->assertSame(60.0, (float) $line->base_quantity);

        app(CheckoutService::class)->void($sale, $this->owner, 'Wrong item');

        $this->assertSame(300.0, $this->stock($panadol));
        $void = StockMovement::query()->where('type', StockMovementType::SaleVoid)->firstOrFail();
        $this->assertSame(60.0, (float) $void->quantity);
        $this->assertSame(1_000, $void->unit_cost);
    }

    public function test_tablets_can_be_returned_from_a_box_and_the_refunds_add_up_to_the_box(): void
    {
        $panadol = $this->panadol();
        $sale = $this->sell([new CartLine($panadol->getKey(), 1, unitId: $this->unit($panadol, 'Box')->getKey())]);
        $line = $sale->items()->firstOrFail();
        $returns = app(ReturnService::class);
        $return = fn (float $tablets) => $returns->process($sale, [$line->getKey() => $tablets], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner, inBaseUnits: true);

        $this->assertRefused(fn () => $return(1.5), '1.5 Tablet is not allowed');
        $this->assertRefused(fn () => $return(31), 'Only 30 Tablet');

        $this->assertSame(13_333, $return(10)->refund_total);
        $this->assertSame(13_333, $return(10)->refund_total);
        $this->assertSame(40_000 - 26_666, $return(10)->refund_total);

        $line->refresh();
        $this->assertSame(1.0, (float) $line->returned_quantity);
        $this->assertSame(SaleStatus::Returned, $sale->fresh()->status);
        $this->assertSame(300.0, $this->stock($panadol));
        $this->assertSame([1_000], StockMovement::query()->where('type', StockMovementType::Return)->pluck('unit_cost')->unique()->values()->all());
    }

    public function test_returns_are_whole_in_the_unit_entered_restocked_or_not(): void
    {
        $panadol = $this->panadol();
        $sale = $this->sell([new CartLine($panadol->getKey(), 2, unitId: $this->unit($panadol, 'Box')->getKey())]);
        $line = $sale->items()->firstOrFail();
        $returns = app(ReturnService::class);
        $return = fn (float $quantity, bool $tablets = false, bool $restock = true) => $returns->process(
            $sale, [$line->getKey() => $quantity], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner, restock: $restock, inBaseUnits: $tablets,
        );

        $this->assertRefused(fn () => $return(0.5), '0.5 Box is not allowed');
        $this->assertRefused(fn () => $return(0.5, restock: false), '0.5 Box is not allowed');

        $return(1);
        $return(10, tablets: true);

        // 20 tablets of the second box are left: as boxes that is 0.667, so it goes back in tablets.
        $this->assertRefused(fn () => $return(0.667), '0.667 Box is not allowed');
        $return(20, tablets: true);

        $this->assertSame(SaleStatus::Returned, $sale->fresh()->status);
        $this->assertSame(2.0, (float) $line->fresh()->returned_quantity);
        $this->assertSame(300.0, $this->stock($panadol));
    }

    public function test_a_line_can_still_be_returned_in_the_unit_it_was_sold_in(): void
    {
        $panadol = $this->panadol();
        $sale = $this->sell([new CartLine($panadol->getKey(), 2, unitId: $this->unit($panadol, 'Strip')->getKey())]);
        $line = $sale->items()->firstOrFail();

        $return = app(ReturnService::class)->process($sale, [$line->getKey() => 1], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);

        $this->assertSame(14_000, $return->refund_total);
        $this->assertSame(10.0, (float) $return->items->first()->base_quantity);
        $this->assertSame(290.0, $this->stock($panadol));
    }

    public function test_the_base_unit_is_locked_once_the_product_has_a_stock_movement(): void
    {
        $catalog = app(ProductCatalogService::class);
        $product = $catalog->create(['name' => 'Amoxil 250 mg', 'unit' => 'Tablet'], [['price' => 2_000]], user: $this->owner);
        $variant = $product->variants()->firstOrFail();

        // No movement yet: the base unit can still be corrected, and every base row follows.
        $catalog->update($product, ['unit' => 'Capsule'], [], [], null, $this->owner);
        $this->assertSame('Capsule', $variant->baseUnit()->firstOrFail()->name);

        app(StockService::class)->stockIn($variant, $this->general, 100, $this->owner);

        $this->assertRefused(fn () => $catalog->update($product->fresh(), ['unit' => 'Tablet'], [], [], null, $this->owner), "base unit (Capsule) can't be changed");
        $this->assertSame('Capsule', $product->fresh()->unit);
        $this->assertSame('Capsule', $variant->baseUnit()->firstOrFail()->name);

        // Packs stay editable.
        $catalog->update($product->fresh(), ['unit' => 'Capsule'], [['id' => $variant->getKey(), 'packs' => [['name' => 'Box', 'factor' => 21, 'price' => 40_000]]]], [], null, $this->owner);
        $this->assertSame(21.0, (float) $this->unit($variant, 'Box')->factor);
    }

    public function test_pack_factors_of_a_whole_units_product_must_be_whole(): void
    {
        $this->assertRefused(fn () => $this->panadol(variant: ['packs' => [['name' => 'Strip', 'factor' => 10.5, 'price' => 100]]]), 'whole number');
        $this->assertFalse(Product::query()->where('name', 'Panadol 500 mg Tablets')->exists());
    }

    public function test_barcodes_are_unique_across_variants_and_packs(): void
    {
        $panadol = $this->panadol();
        $catalog = app(ProductCatalogService::class);

        $this->assertRefused(fn () => $catalog->create(['name' => 'Copy', 'unit' => 'Tablet'], [['barcode' => '4790000000101']], user: $this->owner), 'already used');
        $this->assertRefused(fn () => $catalog->create(['name' => 'Copy', 'unit' => 'Tablet'], [['packs' => [['name' => 'Box', 'factor' => 10, 'barcode' => $panadol->barcode]]]], user: $this->owner), 'already used');
        $this->assertRefused(fn () => $catalog->create(['name' => 'Copy', 'unit' => 'Tablet'], [['packs' => [
            ['name' => 'Strip', 'factor' => 10, 'barcode' => '111'],
            ['name' => 'Box', 'factor' => 30, 'barcode' => '111'],
        ]]], user: $this->owner), 'same Barcode');
        $this->assertRefused(fn () => $catalog->create(['name' => 'Copy', 'unit' => 'Tablet'], [['packs' => [['name' => 'tablet', 'factor' => 10]]]], user: $this->owner), 'two units called');
    }

    public function test_pack_prices_need_prices_manage(): void
    {
        $this->cashier->givePermissionTo('products.manage');

        $variant = app(ProductCatalogService::class)->create(
            ['name' => 'Cetirizine 10 mg', 'unit' => 'Tablet'],
            [['price' => 900, 'packs' => [['name' => 'Strip', 'factor' => 10, 'price' => 8_000]]]],
            user: $this->cashier,
        )->variants()->firstOrFail();

        $this->assertSame(0, $variant->priceOn());
        $this->assertSame([], $this->unit($variant, 'Strip')->prices()->pluck('price')->all());
    }

    public function test_a_consultation_fee_never_touches_stock(): void
    {
        $consultation = app(ProductCatalogService::class)->create(
            ['name' => 'Consultation', 'unit' => 'Visit', 'track_stock' => false],
            [['price' => 250_000, 'opening_stock' => 5]],
            user: $this->owner,
        )->variants()->firstOrFail();

        $panadol = $this->panadol();
        $sale = $this->sell([new CartLine($consultation->getKey(), 1), new CartLine($panadol->getKey(), 10)]);

        $this->assertSame(250_000 + 15_000, $sale->grand_total);
        $this->assertFalse(StockMovement::query()->where('product_variant_id', $consultation->getKey())->exists());
        $this->assertSame(0.0, $this->stock($consultation));
        $this->assertSame(290.0, $this->stock($panadol));
    }

    public function test_reports_count_quantities_in_base_units(): void
    {
        $panadol = $this->panadol();
        $this->sell([
            new CartLine($panadol->getKey(), 1, unitId: $this->unit($panadol, 'Box')->getKey()),
            new CartLine($panadol->getKey(), 5),
        ]);

        $best = app(ReportService::class)->bestSellers(ReportPeriod::today($this->shop));

        $this->assertSame(35.0, $best[0]['quantity']);
    }

    /** @param  array<string, mixed>  $changes */
    private function editVariant(ProductVariant $variant, array $changes): void
    {
        app(ProductCatalogService::class)->update($variant->product, [], [['id' => $variant->getKey(), ...$changes]], [], null, $this->owner);
    }

    private function assertRefused(callable $attempt, string $message): void
    {
        try {
            $attempt();
        } catch (PosException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());

            return;
        }

        $this->fail("Expected a refusal containing \"{$message}\".");
    }

    public function test_a_pack_is_described_by_its_size_once(): void
    {
        $this->assertSame('Strip of 10', VariantUnit::describe('Strip', '10.000'));
        $this->assertSame('Box of 30', VariantUnit::describe('Box of 30', '30.000'));
        $this->assertSame('Drum of 20.5', VariantUnit::describe('Drum', 20.5));
    }
}
