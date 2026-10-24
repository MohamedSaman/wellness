<?php

namespace Tests\Feature\Pos;

use App\Enums\BillType;
use App\Enums\DrugSchedule;
use App\Models\Device;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\OfflineSaleSyncService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * Sales an older till build (before branch 8) left in its outbox, replayed
 * on a clinic running branch 8 (docs/PHARMACY.md, as built (branch 8),
 * "Older tills"). Payload v1 is exactly what resources/js/lib/offline/db.ts
 * wrote then: uuid, invoice_no, sold_at, cashier_id, lines (variant_id,
 * quantity, unit_price, line_discount), sale_discount, payments, grand_total,
 * tax_rate and prices_include_tax, uploaded without device_time. Nothing
 * branch 8 added refuses it: allowed locations, units at a location, a
 * required prescriber, the controlled-drug register, a bank transfer
 * reference, OPD tills and the till's clock all flag the sale instead. A
 * stray lines.*.price is ignored offline; only online requests refuse it.
 */
class LegacyOfflinePayloadTest extends BatchTestCase
{
    use WorksWithCheckout;

    /** @var array<string, int> next invoice number per till code */
    private array $invoices = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Every branch 8 rule that refuses online is on.
        $this->setShopSetting('rx_prescriber', 'required');
        $this->setShopSetting('controlled_register', true);
        $this->setShopSetting('bank_transfer_reference_required', true);
    }

    /**
     * One sale exactly as a v1 till stored it, plus the stray keys given per line.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function v1Sale(Device $till, User $cashier, array $lines, int $grandTotal, array $override = []): array
    {
        $this->invoices[$till->code] = ($this->invoices[$till->code] ?? 0) + 1;

        return [
            'uuid' => (string) Str::uuid(),
            'invoice_no' => sprintf('%s-%06d', $till->code, $this->invoices[$till->code]),
            'sold_at' => now()->subMinutes(20)->toIso8601String(),
            'cashier_id' => $cashier->getKey(),
            'lines' => array_map(fn (array $line): array => ['line_discount' => 0, ...$line], $lines),
            'sale_discount' => 0,
            'payments' => [['method' => 'cash', 'amount' => $grandTotal, 'reference' => null]],
            'grand_total' => $grandTotal,
            'tax_rate' => 0,
            'prices_include_tax' => true,
            ...$override,
        ];
    }

    /**
     * Uploaded as a v1 till did: {sales: [...]}, no device_time.
     *
     * @param  list<array<string, mixed>>  $sales
     */
    private function upload(Device $till, array $sales): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($till->issueSyncToken('Older till')->plainTextToken)
            ->postJson(route('pos.sync.sales'), ['sales' => $sales]);
    }

    /** @param  array<string, mixed>  $payload */
    private function recorded(array $payload): Sale
    {
        return Sale::query()->with('items')->where('uuid', $payload['uuid'])->firstOrFail();
    }

    /** @return list<string> */
    private function reviewTypes(Sale $sale): array
    {
        return array_column($sale->review_reasons ?? [], 'type');
    }

    /** @return array<string, float> batch number => quantity sold from it */
    private function soldBatches(SaleItem $item): array
    {
        return $item->batches()->orderBy('id')->get()->mapWithKeys(fn ($row): array => [(string) $row->batch_no => (float) $row->quantity])->all();
    }

    public function test_a_v1_sale_breaking_every_new_rule_is_recorded_and_flagged_never_refused(): void
    {
        $amoxicillin = $this->medicine('Amoxicillin 500 mg Capsules', schedule: DrugSchedule::PrescriptionOnly);
        $this->receive($amoxicillin, 'SOON', '11/2026', 3);
        $this->receive($amoxicillin, 'LATER', '03/2027', 10);
        $syrup = $this->syrup();
        $this->receive($syrup, 'SY1', '03/2027', 120);
        app(OfflineSaleSyncService::class)->reserveInvoiceBlock($this->genTill());

        // At General Pharmacy by the clinic cashier (no allowed locations: the default only):
        // a prescription-only medicine without a prescriber, 30 ml of syrup in its base unit
        // (loose ml are sold at Doctor's Medicines only), a bank transfer without a reference,
        // a stray lines.*.price on each line, and a sale time 30 minutes ahead with no device_time.
        $soldAt = now()->addMinutes(30)->startOfSecond();
        $payload = $this->v1Sale($this->genTill(), $this->cashier, [
            ['variant_id' => $amoxicillin->getKey(), 'quantity' => 5, 'unit_price' => 2_000, 'price' => 1],
            ['variant_id' => $syrup->getKey(), 'quantity' => 30, 'unit_price' => 500, 'price' => 9],
        ], 25_000, [
            'sold_at' => $soldAt->toIso8601String(),
            'payments' => [['method' => 'bank_transfer', 'amount' => 25_000, 'reference' => null]],
        ]);
        $this->assertSame([], $this->cashier->allowedLocationIds());

        $this->upload($this->genTill(), [$payload])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'synced')
            ->assertJsonPath('results.0.invoice_no', 'T1-000001')
            ->assertJsonPath('results.0.needs_review', true);

        $sale = $this->recorded($payload);
        $this->assertSame(BillType::Standard, $sale->bill_type);
        $this->assertSame(25_000, $sale->grand_total);
        $this->assertSame(25_000, $sale->paid_total);
        $this->assertTrue($sale->sold_at->equalTo($soldAt), 'The sale keeps the time the till printed.');
        $this->assertNull($sale->clock_offset_seconds);
        $this->assertTrue($sale->reported_at->equalTo($sale->synced_at), 'Reports date it by when it arrived.');

        // Flagged, in order: the prescriber, the transfer reference, the clock. No 'unit'
        // (v1 names no unit: the line is the base unit, replayed as before), no 'location'
        // (General Pharmacy is the cashier's default), no 'price_override' (the stray price
        // is not read offline), no 'price' (the till's prices are the list's).
        $this->assertSame(['rx_missing', 'payment', 'clock'], $this->reviewTypes($sale));
        $this->assertSame('A bank transfer was taken without a reference.', $sale->review_reasons[1]['message']);
        $this->assertSame("The sale time was 30 minutes ahead of the server's clock. Reports date it by when it reached the server.", $sale->review_reasons[2]['message']);

        /** @var SaleItem $capsules */
        $capsules = $sale->items->firstWhere('product_variant_id', $amoxicillin->getKey());
        /** @var SaleItem $ml */
        $ml = $sale->items->firstWhere('product_variant_id', $syrup->getKey());
        $this->assertSame([2_000, 10_000, null], [$capsules->unit_price, $capsules->line_total, $capsules->prescriber]);
        $this->assertSame(['ml', 30.0, 500, 15_000], [$ml->unit_name, (float) $ml->base_quantity, $ml->unit_price, $ml->line_total]);
        $this->assertSame((int) $this->general->getKey(), (int) $ml->location_id);

        // Server FEFO, as before: the batch expiring first goes first.
        $this->assertSame(['SOON' => 3.0, 'LATER' => 2.0], $this->soldBatches($capsules));
        $this->assertSame(['LATER' => 8.0], $this->batchQuantities($amoxicillin));
        $this->assertSame(['SY1' => 90.0], $this->batchQuantities($syrup));
        $this->assertLedgerMatches();
    }

    public function test_a_v1_sale_of_a_controlled_drug_is_recorded_and_flagged_for_the_register(): void
    {
        $morphine = $this->medicine('Morphine 10 mg Tablets', schedule: DrugSchedule::Controlled);
        $this->receive($morphine, 'M1', '03/2027', 10);
        app(OfflineSaleSyncService::class)->reserveInvoiceBlock($this->genTill());
        $payload = $this->v1Sale($this->genTill(), $this->cashier, [['variant_id' => $morphine->getKey(), 'quantity' => 2, 'unit_price' => 2_000]], 4_000);

        $this->upload($this->genTill(), [$payload])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'synced')
            ->assertJsonPath('results.0.needs_review', true);

        $this->assertSame(['rx_missing', 'controlled_offline'], $this->reviewTypes($this->recorded($payload)));
        $this->assertSame(['M1' => 8.0], $this->batchQuantities($morphine));
    }

    public function test_a_v1_sale_at_a_counter_the_cashier_may_no_longer_use_is_recorded_and_flagged(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'D1', '03/2027', 10, $this->doctors);
        // The OPD counter: a v1 till never sent bill_type, so this is a standard bill.
        $doc = $this->docTill();
        app(OfflineSaleSyncService::class)->reserveInvoiceBlock($doc);
        $payload = $this->v1Sale($doc, $this->cashier, [['variant_id' => $panadol->getKey(), 'quantity' => 2, 'unit_price' => 2_000, 'price' => 2_000]], 4_000);

        $this->upload($doc, [$payload])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'synced')
            ->assertJsonPath('results.0.needs_review', true);

        $sale = $this->recorded($payload);
        $this->assertSame(BillType::Standard, $sale->bill_type);
        $this->assertSame([['type' => 'location', 'message' => "{$this->cashier->name} is not allowed to sell at Doctor's Medicines."]], $sale->review_reasons);
        $this->assertSame(['D1' => 8.0], $this->batchQuantities($panadol, $this->doctors));
    }

    public function test_the_same_v1_sale_uploaded_twice_is_a_duplicate(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'G1', '03/2027', 10);
        app(OfflineSaleSyncService::class)->reserveInvoiceBlock($this->genTill());
        $payload = $this->v1Sale($this->genTill(), $this->cashier, [['variant_id' => $panadol->getKey(), 'quantity' => 3, 'unit_price' => 2_000, 'price' => 1]], 6_000);

        $this->upload($this->genTill(), [$payload])
            ->assertJsonPath('results.0.status', 'synced')
            ->assertJsonPath('results.0.needs_review', false);
        $this->upload($this->genTill(), [$payload])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'duplicate')
            ->assertJsonPath('results.0.invoice_no', 'T1-000001');

        $this->assertSame(1, Sale::query()->count());
        $this->assertSame(['G1' => 7.0], $this->batchQuantities($panadol));
    }

    public function test_online_the_same_line_with_a_price_is_refused(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'G1', '03/2027', 10);

        $this->actingAs($this->cashier)->postJson(route('pos.checkout'), [
            'device' => $this->genTill()->uuid,
            'uuid' => (string) Str::uuid(),
            'lines' => [['variant_id' => $panadol->getKey(), 'quantity' => 1, 'price' => '1']],
            'payments' => [['method' => 'cash', 'amount' => '20']],
        ])->assertUnprocessable()->assertJsonPath('message', "{$panadol->sku}'s price can't be changed at the till.");

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(['G1' => 10.0], $this->batchQuantities($panadol));
    }
}
