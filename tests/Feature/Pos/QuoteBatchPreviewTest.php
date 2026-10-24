<?php

namespace Tests\Feature\Pos;

use App\Enums\BatchStatus;
use App\Exceptions\PosException;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\TransferLine;
use App\Services\ProductCatalogService;
use App\Services\StockTransferService;
use Illuminate\Support\Str;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * The till's batch preview (docs/PHARMACY.md §6.2): a quote with the till
 * shows which batches first expiry first out would take (a pick first,
 * refused as checkout refuses it) and what the sale could not cover, the
 * same batches the sale then takes. Never a cost.
 */
class QuoteBatchPreviewTest extends BatchTestCase
{
    use WorksWithCheckout;

    private ProductVariant $panadol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->panadol = $this->medicine();
        $this->foundExpired($this->panadol, 'GONE', '09/2026', 7);
        $this->receive($this->panadol, 'SOON', '11/2026', 12);
        $this->receive($this->panadol, 'LATER', '03/2027', 100);
    }

    /** @return array<string, mixed> */
    private function payload(array $lines): array
    {
        return [
            'device' => $this->till->uuid,
            'uuid' => (string) Str::uuid(),
            'lines' => $lines,
            'payments' => [['method' => 'cash', 'amount' => '1000']],
        ];
    }

    public function test_the_quote_shows_the_batches_first_expiry_first_out_takes(): void
    {
        $box = $this->boxOf($this->panadol);

        $quote = $this->actingAs($this->cashier)->postJson(route('pos.quote'), $this->payload([
            ['variant_id' => $this->panadol->getKey(), 'unit_id' => $box, 'quantity' => 1],
            ['variant_id' => $this->panadol->getKey(), 'quantity' => 5],
        ]))->assertOk();

        // The box takes SOON's first 10; the loose tablets the 2 left, then LATER. GONE has expired.
        $this->assertSame([
            ['batch_id' => $this->batch($this->panadol, 'SOON')->getKey(), 'batch_no' => 'SOON', 'expiry' => '2026-11-30', 'quantity' => 10],
        ], $quote->json('lines.0.batches'));
        $this->assertSame([
            ['batch_id' => $this->batch($this->panadol, 'SOON')->getKey(), 'batch_no' => 'SOON', 'expiry' => '2026-11-30', 'quantity' => 2],
            ['batch_id' => $this->batch($this->panadol, 'LATER')->getKey(), 'batch_no' => 'LATER', 'expiry' => '2027-03-31', 'quantity' => 3],
        ], $quote->json('lines.1.batches'));
        $this->assertSame(0, $quote->json('lines.0.shortfall'));
        $this->assertSame(['Box', 'Tablet'], array_column($quote->json('lines'), 'unit_name'));
        $this->assertSame([10, 1], array_column($quote->json('lines'), 'unit_factor'));
        $this->assertSame([10, 5], array_column($quote->json('lines'), 'base_quantity'));
        $this->assertSame([$box, (int) $this->panadol->units()->where('is_base', true)->value('id')], array_column($quote->json('lines'), 'variant_unit_id'));
        $this->assertSame(['otc', 'otc'], array_column($quote->json('lines'), 'drug_schedule'));
        $this->assertSame([null, null], array_column($quote->json('lines'), 'list_price'));
        $this->assertSame([false, false], array_column($quote->json('lines'), 'is_opd_medicine'));
        $this->assertStringNotContainsString('cost', strtolower((string) $quote->getContent()));

        // The sale takes exactly what was previewed.
        $this->actingAs($this->cashier)->postJson(route('pos.checkout'), $this->payload([
            ['variant_id' => $this->panadol->getKey(), 'unit_id' => $box, 'quantity' => 1],
            ['variant_id' => $this->panadol->getKey(), 'quantity' => 5],
        ]))->assertOk();
        $this->assertSame(['GONE' => 7.0, 'LATER' => 97.0], $this->batchQuantities($this->panadol));
    }

    public function test_a_valid_pick_is_honoured_and_one_checkout_would_refuse_is_refused_by_the_quote_too(): void
    {
        $later = $this->batch($this->panadol, 'LATER');
        $soon = $this->batch($this->panadol, 'SOON');

        $quote = $this->actingAs($this->owner)->postJson(route('pos.quote'), $this->payload([
            ['variant_id' => $this->panadol->getKey(), 'quantity' => 4, 'batches' => [['batch_id' => $later->getKey(), 'quantity' => 3]]],
        ]))->assertOk();
        $this->assertSame([[$later->getKey(), 3], [$soon->getKey(), 1]], array_map(fn (array $b): array => [$b['batch_id'], $b['quantity']], $quote->json('lines.0.batches')));

        $insulin = $this->medicine('Insulin', [], minDaysToExpiry: 60);
        $this->receive($insulin, 'NEAR', '11/2026', 5);
        $this->receive($insulin, 'FAR', '06/2027', 5);

        // What an online sale refuses, the quote refuses with the same message: never a clean plan.
        $refused = [
            'Only 12 Tablet left in batch SOON · exp 11/2026.' => [$this->panadol, 20, $soon->getKey(), 20],
            'Batch GONE · exp 09/2026 had expired.' => [$this->panadol, 2, $this->batch($this->panadol, 'GONE')->getKey(), 2],
            'Batch NEAR · exp 11/2026 is within 60 days of expiry and cannot be sold.' => [$insulin, 3, $this->batch($insulin, 'NEAR')->getKey(), 3],
        ];

        foreach ($refused as $message => [$variant, $quantity, $batchId, $picked]) {
            $payload = $this->payload([['variant_id' => $variant->getKey(), 'quantity' => $quantity, 'batches' => [['batch_id' => $batchId, 'quantity' => $picked]]]]);

            $this->actingAs($this->owner)->postJson(route('pos.quote'), $payload)->assertUnprocessable()->assertJsonPath('message', $message);
            $this->actingAs($this->owner)->postJson(route('pos.checkout'), $payload)->assertUnprocessable()->assertJsonPath('message', $message);
        }

        // Two lines picking the same batch: the second gets what the first left.
        $this->actingAs($this->owner)->postJson(route('pos.quote'), $this->payload([
            ['variant_id' => $this->panadol->getKey(), 'quantity' => 10, 'batches' => [['batch_id' => $soon->getKey(), 'quantity' => 10]]],
            ['variant_id' => $this->panadol->getKey(), 'unit_id' => $this->boxOf($this->panadol), 'quantity' => 1, 'batches' => [['batch_id' => $soon->getKey(), 'quantity' => 10]]],
        ]))->assertUnprocessable()->assertJsonPath('message', 'Only 2 Tablet left in batch SOON · exp 11/2026.');

        $this->assertSame(0, Sale::query()->count());

        // A cashier without pos.batch_override may not pick, in the quote as at checkout.
        $this->actingAs($this->cashier)->postJson(route('pos.quote'), $this->payload([
            ['variant_id' => $this->panadol->getKey(), 'quantity' => 4, 'batches' => [['batch_id' => $later->getKey(), 'quantity' => 3]]],
        ]))->assertUnprocessable();
    }

    public function test_the_preview_counts_what_the_stock_level_cannot_cover_as_shortfall(): void
    {
        $tabs = $this->medicine('Cetirizine 10 mg Tablets');
        $this->sell([new CartLine($tabs->getKey(), 3)], offline: true); // nothing on record at GEN

        // A transfer does not settle: GEN holds 5 in DOC1 but only 2 on hand.
        $this->receive($tabs, 'DOC1', '03/2027', 5, $this->doctors);
        app(StockTransferService::class)->create($this->doctors, $this->general, [new TransferLine($tabs->getKey(), 5)], $this->owner);
        $this->assertSame(['unallocated' => -3.0, 'DOC1' => 5.0], $this->batchQuantities($tabs));

        $quote = fn (array $quantities): array => array_column(app(CheckoutService::class)->quote(
            array_map(fn (int $quantity): CartLine => new CartLine($tabs->getKey(), $quantity), $quantities), 0, $this->cashier, $this->till,
        )['lines'], 'shortfall');

        $this->assertSame([2.0], $quote([4]));
        $this->assertSame([0.0], $quote([2]));
        // Lines of the same item share the level, as the sale's lines do.
        $this->assertSame([0.0, 2.0], $quote([1, 3]));

        try {
            $this->sell([new CartLine($tabs->getKey(), 4)]);
            $this->fail('Checkout sold beyond what is on hand.');
        } catch (PosException $exception) {
            $this->assertStringContainsString('2 on hand, 4 requested.', $exception->getMessage());
        }

        // A shop that allows negative stock sells it, so the preview shows no shortfall.
        $this->setShopSetting('allow_negative_stock', true);
        $this->assertSame([0.0], $quote([4]));
    }

    public function test_a_shortfall_quarantined_batches_and_items_without_batches(): void
    {
        $this->batch($this->panadol, 'LATER')->update(['status' => BatchStatus::Quarantined]);
        $service = app(ProductCatalogService::class)->create(['name' => 'Dressing', 'unit' => 'Each', 'track_stock' => false], [['price' => 50_000]], user: $this->owner)->variants()->firstOrFail();

        $quote = app(CheckoutService::class)->quote([
            new CartLine($this->panadol->getKey(), 20),
            new CartLine($service->getKey(), 1),
        ], 0, $this->cashier, $this->till);

        $this->assertSame([['batch_id' => $this->batch($this->panadol, 'SOON')->getKey(), 'batch_no' => 'SOON', 'expiry' => '2026-11-30', 'quantity' => 12.0]], $quote['lines'][0]['batches']);
        $this->assertSame(8.0, $quote['lines'][0]['shortfall']);
        $this->assertSame([], $quote['lines'][1]['batches']);
        $this->assertSame(0.0, $quote['lines'][1]['shortfall']);

        // Without the till: no batches (the old quote).
        $this->assertSame([], app(CheckoutService::class)->quote([new CartLine($this->panadol->getKey(), 2)], 0, $this->cashier)['lines'][0]['batches']);
    }

    public function test_the_medicines_minimum_days_to_expiry_hold_a_batch_back(): void
    {
        $short = $this->medicine('Insulin', [], minDaysToExpiry: 60);
        $this->receive($short, 'NEAR', '11/2026', 5);
        $this->receive($short, 'FAR', '06/2027', 5);

        $quote = app(CheckoutService::class)->quote([new CartLine($short->getKey(), 3), new CartLine($this->panadol->getKey(), 1)], 0, $this->cashier, $this->till);

        $this->assertSame(['FAR'], array_column($quote['lines'][0]['batches'], 'batch_no'));
        $this->assertSame(['SOON'], array_column($quote['lines'][1]['batches'], 'batch_no'));
    }
}
