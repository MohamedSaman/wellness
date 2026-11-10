<?php

namespace Tests\Feature\Pos;

use App\Enums\BillType;
use App\Enums\PaymentMethod;
use App\Enums\StockMovementType;
use App\Exceptions\PosException;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use App\Services\OfflineSaleSyncService;
use App\Services\ProductCatalogService;
use App\Services\StockService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * Prices changed at the till, checkout (docs/PHARMACY.md, branch 14): only
 * on items that allow it (a pharmacy's non-medicines, stock items too), by
 * whoever may (pos.price_override, every cashier), on the base unit, above
 * zero and within the item's limits. Online anything else is refused; an
 * offline sale keeps the till's price and is flagged. The line keeps the
 * list price and who typed the price; discounts apply on top as always.
 */
class TillPriceChangeCheckoutTest extends BatchTestCase
{
    use WorksWithCheckout;

    private ProductVariant $dressing;

    private ProductVariant $cbs;

    protected function setUp(): void
    {
        parent::setUp();

        // A dressing held in stock: 500.00, typed between 200.00 and 1,500.00.
        $this->dressing = $this->openPriceItem('Wound dressing', 50_000, max: 150_000, min: 20_000, tracksStock: true);
        app(StockService::class)->record($this->dressing, $this->general, StockMovementType::StockIn, 50, $this->owner, unitCost: 10_000);
        // A lab test (a service) asked for at the till: 1,000.00, up to 3,000.00, no minimum.
        $this->cbs = $this->openPriceItem('CBS', 100_000, max: 300_000, ask: true);
    }

    /**
     * @param  list<CartLine>  $lines
     * @param  array<string, mixed>  $extra  more CheckoutData arguments
     */
    private function checkoutWith(array $lines, array $extra = []): Sale
    {
        return app(CheckoutService::class)->checkout(new CheckoutData(...[
            'device' => $this->till,
            'cashier' => $this->cashier,
            'lines' => $lines,
            'payments' => [new PaymentInput(PaymentMethod::Cash, 10_000_000)],
            ...$extra,
        ]));
    }

    private function assertRefused(callable $attempt, string $message): void
    {
        try {
            $attempt();
            $this->fail("Accepted; expected: {$message}");
        } catch (PosException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }

    public function test_a_flagged_item_sells_at_the_typed_price_and_keeps_the_list_price_and_who_typed_it(): void
    {
        $sale = $this->checkoutWith([
            new CartLine($this->dressing->getKey(), 2, unitPriceOverride: 120_000),
            new CartLine($this->cbs->getKey(), 1, unitPriceOverride: 80_000),
        ]);

        [$dressing, $cbs] = $sale->items->all();
        $this->assertSame([120_000, 50_000, (int) $this->cashier->getKey()], [$dressing->unit_price, $dressing->list_price, $dressing->price_changed_by]);
        $this->assertSame([80_000, 100_000, (int) $this->cashier->getKey()], [$cbs->unit_price, $cbs->list_price, $cbs->price_changed_by]);
        $this->assertSame(320_000, $sale->grand_total);
        $this->assertSame(2, AuditLog::query()->where('event', 'price_overridden')->count());

        // Stock still leaves at the item's cost; the typed price changes only what was charged.
        $this->assertSame(10_000, $dressing->unit_cost);
        $this->assertSame(48.0, $this->dressing->stockAt($this->general));
    }

    public function test_a_line_at_its_list_price_records_no_price_change(): void
    {
        $sale = $this->checkoutWith([new CartLine($this->dressing->getKey(), 1), new CartLine($this->cbs->getKey(), 1)]);

        $this->assertSame([null, null], $sale->items->pluck('price_changed_by')->all());
        $this->assertSame([null, null], $sale->items->pluck('list_price')->all());
        $this->assertSame(150_000, $sale->grand_total);
    }

    public function test_the_limits_are_enforced_at_both_ends(): void
    {
        $this->assertRefused(fn () => $this->checkoutWith([new CartLine($this->dressing->getKey(), 1, unitPriceOverride: 150_001)]), "The price of {$this->dressing->sku} must be between LKR 200.00 and LKR 1,500.00.");
        $this->assertRefused(fn () => $this->checkoutWith([new CartLine($this->dressing->getKey(), 1, unitPriceOverride: 19_999)]), "The price of {$this->dressing->sku} must be between LKR 200.00 and LKR 1,500.00.");
        $this->assertRefused(fn () => $this->checkoutWith([new CartLine($this->cbs->getKey(), 1, unitPriceOverride: 300_001)]), "The price of {$this->cbs->sku} can't be more than LKR 3,000.00.");
        $this->assertRefused(fn () => $this->checkoutWith([new CartLine($this->cbs->getKey(), 1, unitPriceOverride: 0)]), "The price of {$this->cbs->sku} must be more than zero.");
        $this->assertSame(0, Sale::query()->count());

        // Exactly the limits sell, up and down from the list price.
        $sale = $this->checkoutWith([
            new CartLine($this->dressing->getKey(), 1, unitPriceOverride: 20_000),
            new CartLine($this->dressing->getKey(), 1, unitPriceOverride: 150_000),
            new CartLine($this->cbs->getKey(), 1, unitPriceOverride: 1),
        ]);
        $this->assertSame([20_000, 150_000, 1], $sale->items->pluck('unit_price')->all());
    }

    public function test_the_quote_refuses_what_checkout_refuses(): void
    {
        $quote = fn (int $price): array => app(CheckoutService::class)->quote([new CartLine($this->dressing->getKey(), 2, unitPriceOverride: $price)], 0, $this->cashier, $this->till);

        $this->assertSame(240_000, $quote(120_000)['grand_total']);
        $this->assertSame(50_000, $quote(120_000)['lines'][0]['list_price']);
        $this->assertArrayNotHasKey('price_changed_by', $quote(120_000)['lines'][0]);
        $this->assertRefused(fn () => $quote(200_000), "The price of {$this->dressing->sku} must be between LKR 200.00 and LKR 1,500.00.");
    }

    public function test_items_without_the_setting_and_medicines_never_take_a_typed_price(): void
    {
        $plain = app(ProductCatalogService::class)->create(['name' => 'Cotton wool', 'unit' => 'pcs', 'track_batches' => false], [['price' => 15_000, 'opening_stock' => 10]], user: $this->owner)->variants()->firstOrFail();
        $panadol = $this->medicine();
        $this->receive($panadol, 'P1', '03/2027', 50);
        // A medicine whose flag was switched on in the database: still never.
        $panadol->product->update(['allow_price_override' => true]);
        $panadol->update(['till_price_max' => 999_999]);

        $this->assertRefused(fn () => $this->checkoutWith([new CartLine($plain->getKey(), 1, unitPriceOverride: 10_000)]), "{$plain->sku}'s price can't be changed at the till.");
        $this->assertRefused(fn () => $this->checkoutWith([new CartLine($panadol->getKey(), 1, unitPriceOverride: 100)]), "{$panadol->sku}'s price can't be changed at the till.");
    }

    public function test_every_cashier_may_change_it_and_nobody_without_the_permission(): void
    {
        $other = $this->cashierAt($this->general);
        $this->assertSame(80_000, $this->checkoutWith([new CartLine($this->cbs->getKey(), 1, unitPriceOverride: 80_000)], ['cashier' => $other])->grand_total);

        Role::findByName('cashier')->revokePermissionTo('pos.price_override');
        $other = User::query()->findOrFail($other->getKey());

        $this->assertRefused(fn () => $this->checkoutWith([new CartLine($this->cbs->getKey(), 1, unitPriceOverride: 80_000)], ['cashier' => $other]), 'You are not allowed to change prices.');
    }

    public function test_only_a_single_units_price_can_be_changed(): void
    {
        $gauze = app(ProductCatalogService::class)->create(
            ['name' => 'Gauze swab', 'unit' => 'pcs', 'track_batches' => false, 'allow_price_override' => true],
            [['price' => 2_000, 'opening_stock' => 100, 'till_price_max' => 5_000, 'packs' => [['name' => 'Pack', 'factor' => 10, 'price' => 18_000]]]],
            user: $this->owner,
        )->variants()->firstOrFail();
        $pack = (int) $gauze->units()->where('name', 'Pack')->value('id');

        $this->assertRefused(fn () => $this->checkoutWith([new CartLine($gauze->getKey(), 1, unitId: $pack, unitPriceOverride: 15_000)]), "{$gauze->sku}'s price can only be changed for a single pcs, not a Pack.");

        // The pack sells at its list price; a single swab at a typed one.
        $sale = $this->checkoutWith([new CartLine($gauze->getKey(), 1, unitId: $pack), new CartLine($gauze->getKey(), 3, unitPriceOverride: 2_500)]);
        $this->assertSame([18_000, 2_500], $sale->items->pluck('unit_price')->all());
    }

    public function test_totals_take_line_and_sale_discounts_on_top_of_the_typed_price(): void
    {
        $sale = $this->checkoutWith([
            new CartLine($this->dressing->getKey(), 2, lineDiscount: 4_000, unitPriceOverride: 120_000),
            new CartLine($this->cbs->getKey(), 1, unitPriceOverride: 80_000),
        ], ['cashier' => $this->owner, 'saleDiscount' => 10_000]);

        // Gross 240,000 + 80,000; less the line's 4,000; the sale's 10,000 spread over 236,000 : 80,000.
        $this->assertSame(320_000, $sale->subtotal);
        $this->assertSame(14_000, $sale->discount_total);
        $this->assertSame(306_000, $sale->grand_total);
        $this->assertSame([4_000, 0], $sale->items->pluck('line_discount')->all());
        $this->assertSame(10_000, $sale->items->sum('sale_discount_share'));
        $this->assertSame($sale->grand_total, $sale->items->sum('line_total'));
    }

    public function test_on_an_opd_bill_a_stock_items_price_is_in_the_medicines_amount(): void
    {
        $doc = $this->docTill();
        $doctor = $this->cashierAt($this->doctors);
        app(StockService::class)->record($this->dressing, $this->doctors, StockMovementType::StockIn, 10, $this->owner, unitCost: 10_000);
        $opd = fn (array $lines, ?int $amount) => $this->checkoutWith($lines, ['device' => $doc, 'cashier' => $doctor, 'billType' => BillType::Opd, 'opdMedicinesAmount' => $amount]);

        $this->assertRefused(
            fn () => $opd([new CartLine($this->dressing->getKey(), 1, unitPriceOverride: 70_000)], 60_000),
            "{$this->dressing->sku} is in the Medicines amount on an OPD bill: its price can't be typed.",
        );

        // A service keeps its own typed price beside the Medicines amount.
        $sale = $opd([new CartLine($this->cbs->getKey(), 1, unitPriceOverride: 90_000), new CartLine($this->dressing->getKey(), 1)], 60_000);
        $this->assertSame(150_000, $sale->grand_total);
        $this->assertSame([(int) $doctor->getKey(), null], $sale->items->pluck('price_changed_by')->all());
        $this->assertTrue($sale->items[1]->is_opd_medicine);
    }

    public function test_a_tampered_price_is_kept_and_flagged_offline(): void
    {
        $payload = $this->offlineSale([
            // Above the maximum: the till's catalog was old, or the request was changed.
            ['variant_id' => $this->dressing->getKey(), 'quantity' => 1, 'unit_price' => 200_000, 'price_override' => true],
            // An item without the setting, sent as typed.
            ['variant_id' => $this->plainItem()->getKey(), 'quantity' => 1, 'unit_price' => 1_000, 'price_override' => true],
            // Within the limits: nothing to review.
            ['variant_id' => $this->cbs->getKey(), 'quantity' => 1, 'unit_price' => 150_000, 'price_override' => true],
        ], 351_000);

        $this->sync([$payload])->assertJsonPath('results.0.status', 'synced');

        $sale = Sale::query()->with('items')->where('uuid', $payload['uuid'])->firstOrFail();
        $this->assertTrue($sale->needs_review);
        $this->assertSame(['price_override', 'price_override'], array_column($sale->review_reasons ?? [], 'type'), 'No price review on top.');
        $this->assertSame(
            "{$this->dressing->sku} was sold at a typed price of LKR 2,000.00. The price of {$this->dressing->sku} must be between LKR 200.00 and LKR 1,500.00.",
            $sale->review_reasons[0]['message'],
        );
        $this->assertSame([200_000, 1_000, 150_000], $sale->items->pluck('unit_price')->all());
        $this->assertSame(351_000, $sale->grand_total);
        $this->assertSame(array_fill(0, 3, (int) $this->cashier->getKey()), $sale->items->pluck('price_changed_by')->all());
    }

    public function test_offline_a_price_not_marked_as_typed_on_a_flagged_item_is_a_price_difference(): void
    {
        $payload = $this->offlineSale([['variant_id' => $this->cbs->getKey(), 'quantity' => 1, 'unit_price' => 90_000]], 90_000);

        $this->sync([$payload])->assertJsonPath('results.0.status', 'synced');

        $sale = Sale::query()->with('items')->where('uuid', $payload['uuid'])->firstOrFail();
        $this->assertSame(['price'], array_column($sale->review_reasons ?? [], 'type'));
        $this->assertNull($sale->items->sole()->price_changed_by);
    }

    private function plainItem(): ProductVariant
    {
        return app(ProductCatalogService::class)->create(['name' => 'Cotton wool', 'unit' => 'pcs', 'track_batches' => false], [['price' => 15_000]], user: $this->owner)->variants()->firstOrFail();
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function offlineSale(array $lines, int $grandTotal): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'invoice_no' => 'T1-000001',
            'sold_at' => now()->subMinutes(20)->toIso8601String(),
            'cashier_id' => $this->cashier->getKey(),
            'lines' => $lines,
            'payments' => [['method' => 'cash', 'amount' => $grandTotal]],
            'grand_total' => $grandTotal,
        ];
    }

    /** @param  list<array<string, mixed>>  $sales */
    private function sync(array $sales, ?Device $till = null): TestResponse
    {
        $till ??= $this->till;
        app(OfflineSaleSyncService::class)->reserveInvoiceBlock($till);
        $this->app['auth']->forgetGuards();

        return $this->withToken($till->issueSyncToken('Counter browser')->plainTextToken)
            ->postJson(route('pos.sync.sales'), ['sales' => $sales, 'device_time' => now()->toIso8601String()]);
    }
}
