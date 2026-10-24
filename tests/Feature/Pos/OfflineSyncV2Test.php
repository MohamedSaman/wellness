<?php

namespace Tests\Feature\Pos;

use App\Enums\BillType;
use App\Enums\DrugSchedule;
use App\Models\Device;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\OfflineSaleSyncService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * Offline payload v2 (docs/PHARMACY.md §7.1, §9 branch 8) replayed over
 * /pos/sync/sales: units and locations, the till's batch proposal, the
 * prescriber, a typed price, OPD bills. Every v2 key is optional, so a sale
 * an older till kept in its outbox still replays exactly as before.
 */
class OfflineSyncV2Test extends BatchTestCase
{
    use WorksWithCheckout;

    /** @var array<string, int> next invoice number per till code */
    private array $invoices = [];

    /**
     * One offline sale as the till uploads it.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function offlineSale(Device $till, User $cashier, array $lines, int $grandTotal, array $override = []): array
    {
        $this->invoices[$till->code] = ($this->invoices[$till->code] ?? 0) + 1;

        return [
            'uuid' => (string) Str::uuid(),
            'invoice_no' => sprintf('%s-%06d', $till->code, $this->invoices[$till->code]),
            'sold_at' => now()->subMinutes(20)->toIso8601String(),
            'cashier_id' => $cashier->getKey(),
            'lines' => $lines,
            'payments' => [['method' => 'cash', 'amount' => $grandTotal]],
            'grand_total' => $grandTotal,
            ...$override,
        ];
    }

    /** @param  list<array<string, mixed>>  $sales */
    private function sync(Device $till, array $sales): TestResponse
    {
        app(OfflineSaleSyncService::class)->reserveInvoiceBlock($till);
        $this->app['auth']->forgetGuards();

        return $this->withToken($till->issueSyncToken('Counter browser')->plainTextToken)
            ->postJson(route('pos.sync.sales'), ['sales' => $sales, 'device_time' => now()->toIso8601String()]);
    }

    /** @param  array<string, mixed>  $payload */
    private function recorded(array $payload): Sale
    {
        return Sale::query()->with('items')->where('uuid', $payload['uuid'])->firstOrFail();
    }

    private function item(Sale $sale, ProductVariant $variant, ?int $unitId = null): SaleItem
    {
        return $sale->items
            ->where('product_variant_id', $variant->getKey())
            ->when($unitId !== null, fn ($items) => $items->where('variant_unit_id', $unitId))
            ->firstOrFail();
    }

    /** @return list<string> */
    private function reviewTypes(Sale $sale): array
    {
        return array_column($sale->review_reasons ?? [], 'type');
    }

    private function unitOf(ProductVariant $variant, string $name): int
    {
        return (int) $variant->units()->where('name', $name)->firstOrFail()->getKey();
    }

    public function test_a_sale_from_an_older_till_replays_unchanged(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'SOON', '11/2026', 3);
        $this->receive($panadol, 'LATER', '03/2027', 10);
        // Exactly what a v1 till wrote: no unit, location, batches, prescriber, bill type or clock.
        $payload = $this->offlineSale($this->genTill(), $this->cashier, [
            ['variant_id' => $panadol->getKey(), 'quantity' => 5, 'unit_price' => 2_000, 'line_discount' => 0],
        ], 10_000, ['sale_discount' => 0]);
        $this->app['auth']->forgetGuards();
        app(OfflineSaleSyncService::class)->reserveInvoiceBlock($this->genTill());

        $this->withToken($this->genTill()->issueSyncToken('Old browser')->plainTextToken)
            ->postJson(route('pos.sync.sales'), ['sales' => [$payload]])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'synced')
            ->assertJsonPath('results.0.needs_review', false);

        $sale = $this->recorded($payload);
        $line = $sale->items->sole();
        $this->assertSame(BillType::Standard, $sale->bill_type);
        $this->assertNull($sale->opd_medicines_amount);
        $this->assertNull($sale->clock_offset_seconds);
        $this->assertTrue($sale->reported_at?->equalTo($sale->sold_at));
        $this->assertSame(10_000, $sale->grand_total);
        $this->assertSame((int) $panadol->baseUnit()->firstOrFail()->getKey(), (int) $line->variant_unit_id);
        $this->assertSame((int) $this->general->getKey(), (int) $line->location_id);
        $this->assertSame(2_000, $line->unit_price);
        $this->assertNull($line->list_price);
        $this->assertFalse($line->is_opd_medicine);
        $this->assertNull($line->prescriber);
        $this->assertNull($line->prescription_ref);
        $this->assertSame(['LATER' => 8.0], $this->batchQuantities($panadol), 'First expiry first: SOON is used up.');
    }

    public function test_packs_and_loose_ml_replay_in_their_units_at_doctors_medicines(): void
    {
        $syrup = $this->syrup();
        $batch = $this->receive($syrup, 'S1', '03/2027', 600, $this->doctors);
        $bottle = $this->unitOf($syrup, 'Bottle');
        $ml = $this->unitOf($syrup, 'ml');
        $doc = $this->docTill(false);
        $at = (int) $this->doctors->getKey();

        $payload = $this->offlineSale($doc, $this->cashierAt($this->doctors), [
            ['variant_id' => $syrup->getKey(), 'unit_id' => $bottle, 'location_id' => $at, 'quantity' => 2, 'unit_price' => 25_000,
                'batches' => [['batch_id' => $batch->getKey(), 'quantity' => 120]]],
            ['variant_id' => $syrup->getKey(), 'unit_id' => $ml, 'location_id' => $at, 'quantity' => 15, 'unit_price' => 500,
                'batches' => [['batch_id' => $batch->getKey(), 'quantity' => 15]]],
        ], 57_500);

        $this->sync($doc, [$payload])
            ->assertJsonPath('results.0.status', 'synced')
            ->assertJsonPath('results.0.needs_review', false);

        $sale = $this->recorded($payload);
        $this->assertSame(57_500, $sale->grand_total);
        $this->assertSame(120.0, (float) $this->item($sale, $syrup, $bottle)->base_quantity);
        $this->assertSame(15.0, (float) $this->item($sale, $syrup, $ml)->base_quantity);
        $this->assertSame(DrugSchedule::Otc, $this->item($sale, $syrup, $ml)->drug_schedule);
        $this->assertSame(['S1' => 465.0], $this->batchQuantities($syrup, $this->doctors));
    }

    public function test_loose_ml_sold_where_the_syrup_is_not_sold_loose_is_recorded_and_flagged(): void
    {
        $syrup = $this->syrup();
        $this->receive($syrup, 'S1', '03/2027', 600);

        $payload = $this->offlineSale($this->genTill(), $this->cashier, [
            ['variant_id' => $syrup->getKey(), 'unit_id' => $this->unitOf($syrup, 'ml'), 'quantity' => 10, 'unit_price' => 500],
        ], 5_000);

        $this->sync($this->genTill(), [$payload])->assertJsonPath('results.0.status', 'synced');

        $sale = $this->recorded($payload);
        $this->assertSame(['unit'], $this->reviewTypes($sale));
        $this->assertSame(['S1' => 590.0], $this->batchQuantities($syrup));
    }

    public function test_an_opd_bill_rung_up_offline_replays_with_its_medicines_amount_shared_over_the_medicines(): void
    {
        $consultation = $this->consultation(150_000);
        $syrup = $this->syrup();
        $panadol = $this->medicine(schedule: DrugSchedule::PrescriptionOnly);
        $syrupBatch = $this->receive($syrup, 'S1', '03/2027', 600, $this->doctors);
        $panadolBatch = $this->receive($panadol, 'P1', '03/2027', 50, $this->doctors);
        $doc = $this->docTill();
        $at = (int) $this->doctors->getKey();

        // Fee typed at 1,200; medicines 450 over 10 ml of syrup (list 50) and 5 tablets (list 100),
        // each sent with the list price the till weighed it by.
        $payload = $this->offlineSale($doc, $this->cashierAt($this->doctors), [
            ['variant_id' => $consultation->getKey(), 'quantity' => 1, 'unit_price' => 120_000, 'price_override' => true],
            ['variant_id' => $syrup->getKey(), 'unit_id' => $this->unitOf($syrup, 'ml'), 'location_id' => $at, 'quantity' => 10, 'unit_price' => 500,
                'batches' => [['batch_id' => $syrupBatch->getKey(), 'quantity' => 10]]],
            ['variant_id' => $panadol->getKey(), 'location_id' => $at, 'quantity' => 5, 'unit_price' => 2_000,
                'batches' => [['batch_id' => $panadolBatch->getKey(), 'quantity' => 5]],
                'prescriber' => '  Dr. S. Perera ', 'prescription_ref' => 'OPD-118'],
        ], 165_000, ['bill_type' => 'opd', 'medicines_amount' => 45_000, 'customer_name' => 'A. Silva']);

        $this->sync($doc, [$payload])
            ->assertJsonPath('results.0.status', 'synced')
            ->assertJsonPath('results.0.needs_review', false);

        $sale = $this->recorded($payload);
        $this->assertSame(BillType::Opd, $sale->bill_type);
        $this->assertSame(45_000, $sale->opd_medicines_amount);
        $this->assertSame(165_000, $sale->grand_total);
        $this->assertSame('A. Silva', $sale->customer_name);

        $fee = $this->item($sale, $consultation);
        $this->assertSame([120_000, 150_000, false], [$fee->unit_price, $fee->list_price, $fee->is_opd_medicine]);

        $syrupLine = $this->item($sale, $syrup);
        $this->assertSame([15_000, 500, 500, true], [$syrupLine->line_total, $syrupLine->unit_price, $syrupLine->list_price, $syrupLine->is_opd_medicine]);

        $panadolLine = $this->item($sale, $panadol);
        $this->assertSame([30_000, 2_000, true], [$panadolLine->line_total, $panadolLine->list_price, $panadolLine->is_opd_medicine]);
        $this->assertSame(['Dr. S. Perera', 'OPD-118'], [$panadolLine->prescriber, $panadolLine->prescription_ref]);
        $this->assertSame(DrugSchedule::PrescriptionOnly, $panadolLine->drug_schedule);

        $this->assertSame(['S1' => 590.0], $this->batchQuantities($syrup, $this->doctors));
        $this->assertSame(['P1' => 45.0], $this->batchQuantities($panadol, $this->doctors));
    }

    public function test_an_opd_bill_from_a_till_that_does_not_take_them_is_recorded_and_flagged(): void
    {
        $consultation = $this->consultation(150_000);

        $payload = $this->offlineSale($this->genTill(), $this->cashier, [
            ['variant_id' => $consultation->getKey(), 'quantity' => 1, 'unit_price' => 150_000],
        ], 150_000, ['bill_type' => 'opd', 'medicines_amount' => 0]);

        $this->sync($this->genTill(), [$payload])->assertJsonPath('results.0.status', 'synced');

        $this->assertContains('opd', $this->reviewTypes($this->recorded($payload)));
    }

    public function test_a_typed_price_on_an_item_that_does_not_allow_it_is_kept_and_flagged(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'P1', '03/2027', 50);

        $payload = $this->offlineSale($this->genTill(), $this->cashier, [
            ['variant_id' => $panadol->getKey(), 'quantity' => 2, 'unit_price' => 1_500, 'price_override' => true],
        ], 3_000);

        $this->sync($this->genTill(), [$payload])->assertJsonPath('results.0.status', 'synced');

        $sale = $this->recorded($payload);
        $this->assertSame(['price_override'], $this->reviewTypes($sale), 'No price review on top.');
        $this->assertSame(1_500, $sale->items->sole()->unit_price);
        $this->assertSame(3_000, $sale->grand_total);
    }

    public function test_a_blank_prescriber_is_stored_as_none(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'P1', '03/2027', 50);

        $payload = $this->offlineSale($this->genTill(), $this->cashier, [
            ['variant_id' => $panadol->getKey(), 'quantity' => 1, 'unit_price' => 2_000, 'prescriber' => '   ', 'prescription_ref' => ''],
        ], 2_000);

        $this->sync($this->genTill(), [$payload])->assertJsonPath('results.0.status', 'synced');

        $line = $this->recorded($payload)->items->sole();
        $this->assertSame([null, null], [$line->prescriber, $line->prescription_ref]);
    }

    public function test_malformed_v2_keys_reject_only_that_sale(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'P1', '03/2027', 50);
        $line = ['variant_id' => $panadol->getKey(), 'quantity' => 1, 'unit_price' => 2_000];
        $sale = fn (array $override, array $lineOverride = []): array => $this->offlineSale($this->genTill(), $this->cashier, [[...$line, ...$lineOverride]], 2_000, $override);

        $this->sync($this->genTill(), [
            $sale(['bill_type' => 'credit']),
            $sale(['medicines_amount' => -1]),
            $sale([], ['price_override' => 'perhaps']),
            $sale([], ['prescriber' => str_repeat('x', 151)]),
            $sale([], ['prescription_ref' => str_repeat('x', 101)]),
            $sale([]),
        ])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'rejected')
            ->assertJsonPath('results.1.status', 'rejected')
            ->assertJsonPath('results.2.status', 'rejected')
            ->assertJsonPath('results.3.status', 'rejected')
            ->assertJsonPath('results.4.status', 'rejected')
            ->assertJsonPath('results.5.status', 'synced');

        $this->assertSame(1, Sale::query()->count());
    }
}
