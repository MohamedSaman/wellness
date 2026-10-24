<?php

namespace Tests\Feature\Pos;

use App\Enums\BillType;
use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Enums\TaxCategory;
use App\Exceptions\PosException;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use App\Services\ReceiptService;
use App\Services\ReturnService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * OPD bills at the doctor's counter (docs/PHARMACY.md §9, branch 8): the
 * consultation fee (typed when it varies) plus one Medicines amount spread
 * over the medicines dispensed by list price, else by cost, so every line's
 * share adds up to exactly the amount. The shares never reach the till (a
 * share could give a cost away): it sees the bill's Medicines figures only.
 * Replayed offline, by the prices the till weighed them with. Stock still
 * goes first expiry first out, item by item.
 */
class OpdBillTest extends BatchTestCase
{
    use WorksWithCheckout;

    private Device $doc;

    private User $doctor;

    private ProductVariant $consult;

    private ProductVariant $syrup;

    private ProductVariant $tabs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->doc = $this->docTill();
        $this->doctor = $this->cashierAt($this->doctors);
        $this->consult = $this->consultation(150_000);
        $this->syrup = $this->syrup();
        $this->tabs = $this->medicine();

        $this->receive($this->syrup, 'S1', '03/2027', 600, $this->doctors, 300);
        $this->receive($this->tabs, 'SOON', '11/2026', 10, $this->doctors);
        $this->receive($this->tabs, 'LATER', '03/2027', 100, $this->doctors);
    }

    /**
     * @param  list<CartLine>  $lines
     * @param  array<string, mixed>  $extra  more CheckoutData arguments
     */
    private function opd(array $lines, ?int $medicines, array $extra = []): Sale
    {
        return app(CheckoutService::class)->checkout(new CheckoutData(...[
            'device' => $this->doc,
            'cashier' => $this->doctor,
            'lines' => $lines,
            'payments' => [new PaymentInput(PaymentMethod::Cash, 10_000_000)],
            'billType' => BillType::Opd,
            'opdMedicinesAmount' => $medicines,
            ...$extra,
        ]));
    }

    /** Consultation, 40 ml of syrup (list 20,000) and 15 tablets (list 30,000). */
    private function visit(?int $fee = null): array
    {
        return [
            new CartLine($this->consult->getKey(), 1, unitPriceOverride: $fee),
            new CartLine($this->syrup->getKey(), 40),
            new CartLine($this->tabs->getKey(), 15),
        ];
    }

    /** A tablet medicine without a price on the list, at Doctor's Medicines at this cost per tablet. */
    private function unpriced(string $name, int|string $cost): ProductVariant
    {
        $variant = $this->medicine($name, []);
        $variant->units()->where('is_base', true)->firstOrFail()->prices()->delete();
        $this->receive($variant, 'U1', '03/2027', 100, $this->doctors, $cost);

        return $variant;
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

    public function test_an_opd_bill_is_the_fee_plus_the_medicines_amount_spread_by_list_price(): void
    {
        $sale = $this->opd($this->visit(), 100_001);

        $this->assertSame(BillType::Opd, $sale->bill_type);
        $this->assertSame(100_001, $sale->opd_medicines_amount);
        $this->assertSame(250_001, $sale->subtotal);
        $this->assertSame(250_001, $sale->grand_total);
        $this->assertSame(0, $sale->discount_total);
        $this->assertFalse($sale->needs_review);

        [$fee, $syrup, $tabs] = $sale->items->all();

        $this->assertFalse($fee->is_opd_medicine);
        $this->assertSame(150_000, $fee->line_total);
        $this->assertNull($fee->list_price);

        // 20,000 : 30,000 of 100,001: 40,000.4 and 60,000.6, the odd unit to the larger.
        $this->assertTrue($syrup->is_opd_medicine);
        $this->assertSame(40_000, $syrup->line_total);
        $this->assertSame(500, $syrup->unit_price);
        $this->assertSame(500, $syrup->list_price);
        $this->assertSame(0, $syrup->line_discount);
        $this->assertSame(60_001, $tabs->line_total);
        $this->assertSame(2_000, $tabs->list_price);

        // Stock: first expiry first out per item, with the cost snapshot as on any sale.
        $this->assertSame(['SOON' => 10.0, 'LATER' => 5.0], $tabs->batches()->pluck('quantity', 'batch_no')->map(fn ($q): float => (float) $q)->all());
        $this->assertSame(1_000, $tabs->unit_cost);
        $this->assertSame(300, $syrup->unit_cost);
        $this->assertSame(['S1' => 560.0], $this->batchQuantities($this->syrup, $this->doctors));
        $this->assertSame(['LATER' => 95.0], $this->batchQuantities($this->tabs, $this->doctors));
        $this->assertLedgerMatches();
    }

    public function test_the_medicines_amount_always_adds_up_exactly(): void
    {
        $amox = $this->unpriced('Amoxicillin 250 mg Capsules', '1234.5678');
        $lines = [
            new CartLine($this->consult->getKey(), 1),
            new CartLine($this->syrup->getKey(), 7),   // list 3,500
            new CartLine($this->tabs->getKey(), 3),    // list 6,000
            new CartLine($amox->getKey(), 4),          // no price: cost 1,235 x 4 = 4,940
        ];
        $weights = [3_500, 6_000, 4_940];
        $quote = fn (int $amount): array => app(CheckoutService::class)->quote($lines, 0, $this->doctor, $this->doc, BillType::Opd, $amount);

        // The shares as a sale records them (each sale undone, so the stock lasts).
        $shares = function (int $amount) use ($lines): array {
            DB::beginTransaction();

            try {
                $paid = [new PaymentInput(PaymentMethod::Cash, 150_000 + $amount)];

                return $this->opd($lines, $amount, ['payments' => $paid])->items->slice(1)->pluck('line_total')->values()->all();
            } finally {
                DB::rollBack();
            }
        };

        // Weighed exactly: the amount equal to the weights gives each line its own weight.
        $this->assertSame($weights, $shares(14_440));

        $amounts = [0, 1, 2, 3, 5, 7, 10, 99, 100, 101, 997, 1_000, 14_439, 14_441, 99_999, 123_457, 1_000_003, 987_654_321];

        foreach ([...$amounts, ...range(11, 60)] as $amount) {
            // The quote: the bill's Medicines figures only, never a line's share.
            $result = $quote($amount);
            $this->assertSame($amount, $result['medicines_total'], "Amount {$amount}");
            $this->assertSame(0, $result['medicines_tax'], "Amount {$amount}");
            $this->assertSame(150_000 + $amount, $result['grand_total'], "Amount {$amount}");
            $this->assertSame(150_000 + $amount, $result['subtotal'], "Amount {$amount}");
            $this->assertSame([150_000, null, null, null], array_column($result['lines'], 'line_total'), "Amount {$amount}");
        }

        foreach ([...$amounts, ...range(11, 25)] as $amount) {
            $recorded = $shares($amount);
            $this->assertSame($amount, array_sum($recorded), "Amount {$amount}");

            foreach ($recorded as $n => $share) {
                $exact = $amount * $weights[$n] / array_sum($weights);
                $this->assertLessThan(3, abs($share - $exact), "Amount {$amount}, line {$n}: {$share} is not about {$exact}");
                $this->assertGreaterThanOrEqual(0, $share);
            }
        }

        // 3,500 : 6,000 : 4,940 of 99,999, the odd unit to the largest.
        $sale = $this->opd($lines, 99_999);
        $this->assertSame([24_237, 41_552, 34_210], $sale->items->slice(1)->pluck('line_total')->values()->all());
        $this->assertNull($sale->items[3]->list_price);
        $this->assertSame(0, $sale->items[3]->unit_price);
        $this->assertSame(1_235, $sale->items[3]->unit_cost);
    }

    public function test_an_unpriced_medicine_weighs_by_its_cost_online(): void
    {
        // Received at very different costs; neither has a price on the list.
        $secret = $this->unpriced('Secret Tablets', 1_337);
        $cheap = $this->unpriced('Cheap Tablets', 3);

        // Next to a priced medicine (list 2,000): 2,000 : 1,337 of 10,000.00, the odd unit to the larger.
        $sale = $this->opd([
            new CartLine($this->consult->getKey(), 1),
            new CartLine($this->tabs->getKey(), 1),
            new CartLine($secret->getKey(), 1),
        ], 1_000_000);
        $this->assertSame([150_000, 599_341, 400_659], $sale->items->pluck('line_total')->all());
        $this->assertSame(1_000_000, $sale->items->slice(1)->sum('line_total'));
        $this->assertSame(1_150_000, $sale->grand_total);
        $this->assertSame(1_337, $sale->items[2]->unit_cost);

        // Only unpriced medicines: 1,337 : 3.
        $sale = $this->opd([new CartLine($secret->getKey(), 1), new CartLine($cheap->getKey(), 1)], 1_000_000);
        $this->assertSame([997_762, 2_238], $sale->items->pluck('line_total')->all());
    }

    public function test_the_till_never_receives_an_opd_medicine_lines_share(): void
    {
        // Tax on, exclusive: every line standard-rated.
        $this->shop->update(['tax_enabled' => true, 'tax_rate' => 18, 'prices_include_tax' => false]);
        $secret = $this->unpriced('Secret Tablets', 1_337);

        foreach ([$this->consult, $this->tabs, $secret] as $variant) {
            $variant->product->update(['tax_category' => TaxCategory::Standard]);
        }

        $payload = [
            'device' => $this->doc->uuid,
            'uuid' => (string) Str::uuid(),
            'bill_type' => 'opd',
            'medicines_amount' => '10000',
            'lines' => [
                ['variant_id' => $this->consult->getKey(), 'quantity' => 1],
                ['variant_id' => $this->tabs->getKey(), 'quantity' => 1],
                ['variant_id' => $secret->getKey(), 'quantity' => 1],
            ],
            'payments' => [['method' => 'cash', 'amount' => '20000']],
        ];
        // As recorded: 599,341 and 400,659 (weighed 2,000 : 1,337 by the cost), taxed 107,881 and 72,119.
        $shares = ['599341', '400659'];

        // The quote: unit and list prices, no share, no tax, no discount share per medicine line.
        $quote = $this->actingAs($this->doctor)->postJson(route('pos.quote'), $payload)->assertOk();
        $this->assertSame([177_000, null, null], array_column($quote->json('lines'), 'line_total'));
        $this->assertSame([27_000, null, null], array_column($quote->json('lines'), 'tax_amount'));
        $this->assertSame([0, null, null], array_column($quote->json('lines'), 'sale_discount_share'));
        $this->assertSame([150_000, 2_000, 0], array_column($quote->json('lines'), 'unit_price'));
        $this->assertSame([null, 2_000, null], array_column($quote->json('lines'), 'list_price'));
        $this->assertSame(1_180_000, $quote->json('totals.medicines_total'));
        $this->assertSame(180_000, $quote->json('totals.medicines_tax'));
        $this->assertSame(207_000, $quote->json('totals.tax_total'));
        $this->assertSame(1_357_000, $quote->json('totals.grand_total'));

        foreach ($shares as $share) {
            $this->assertStringNotContainsString($share, (string) $quote->getContent());
        }

        // A standard bill's quote has no Medicines figures.
        $standard = $this->actingAs($this->doctor)->postJson(route('pos.quote'), [
            ...$payload, 'bill_type' => 'standard', 'medicines_amount' => null, 'lines' => array_slice($payload['lines'], 0, 2),
        ])->assertOk();
        $this->assertNull($standard->json('totals.medicines_total'));
        $this->assertNull($standard->json('totals.medicines_tax'));
        $this->assertSame([177_000, 2_360], array_column($standard->json('lines'), 'line_total'));

        // The sale keeps the shares (for profit and returns); the till hears only the total.
        $checkout = $this->actingAs($this->doctor)->postJson(route('pos.checkout'), $payload)->assertOk();
        $sale = Sale::query()->with('items')->sole();
        $this->assertSame([150_000, 599_341, 400_659], $sale->items->map(fn (SaleItem $item): int => $item->line_total - $item->tax_amount)->all());
        $this->assertSame(1_357_000, $checkout->json('sale.grand_total'));

        // The receipt (and a reprint): the Medicines amount, no price or share per medicine.
        $receipt = app(ReceiptService::class)->sale($sale);
        $this->assertSame(1_180_000, $receipt['medicinesTotal']);
        $this->assertSame([177_000, null, null], array_column($receipt['items'], 'lineTotal'));
        $this->assertSame([150_000, null, null], array_column($receipt['items'], 'unitPrice'));
        $this->assertSame([0, null, null], array_column($receipt['items'], 'discount'));

        $pages = [
            $this->actingAs($this->doctor)->get(route('pos.receipt', $sale))->assertOk(),
            $this->get((string) $this->actingAs($this->doctor)->postJson(route('pos.receipt.reprint', $sale), ['device' => $this->doc->uuid])->assertOk()->json('receipt_url'))->assertOk(),
            $this->actingAs($this->doctor)->getJson(route('pos.recent-sales', ['device' => $this->doc->uuid]))->assertOk(),
            $checkout,
        ];

        foreach ($pages as $response) {
            foreach ($shares as $share) {
                $this->assertStringNotContainsString($share, (string) $response->getContent());
            }
        }
    }

    public function test_medicines_that_weigh_nothing_share_the_amount_equally(): void
    {
        $free = $this->unpriced('Sample Tablets', 0);
        $other = $this->unpriced('Other Sample Tablets', 0);

        $sale = $this->opd([
            new CartLine($this->consult->getKey(), 1),
            new CartLine($free->getKey(), 2),
            new CartLine($other->getKey(), 5),
        ], 101);

        $shares = $sale->items->slice(1)->pluck('line_total')->values()->all();
        $this->assertSame(101, array_sum($shares));
        $this->assertEqualsCanonicalizing([50, 51], $shares);
        $this->assertSame(150_101, $sale->grand_total);
    }

    public function test_the_fee_can_be_typed_by_whoever_may_and_is_audited(): void
    {
        $sale = $this->opd($this->visit(fee: 120_000), 50_000);

        $fee = $sale->items->first();
        $this->assertInstanceOf(SaleItem::class, $fee);
        $this->assertSame(120_000, $fee->unit_price);
        $this->assertSame(150_000, $fee->list_price);
        $this->assertSame(170_000, $sale->grand_total);

        $audit = AuditLog::query()->where('event', 'price_overridden')->sole();
        $this->assertSame((int) $this->doctor->getKey(), (int) $audit->user_id);
        $this->assertSame((int) $sale->getKey(), (int) $audit->auditable_id);
        $this->assertSameIgnoringKeyOrder(['unit_price' => 150_000], $audit->old_values);
        $this->assertSameIgnoringKeyOrder(['unit_price' => 120_000, 'sku' => $this->consult->sku, 'invoice_no' => $sale->invoice_no], $audit->new_values);
    }

    public function test_a_typed_price_is_refused_without_permission_for_stock_items_and_at_zero(): void
    {
        $this->assertRefused(fn () => $this->opd([new CartLine($this->consult->getKey(), 1, unitPriceOverride: 0)], null), "The price of {$this->consult->sku} must be more than zero.");
        $this->assertRefused(fn () => $this->opd([new CartLine($this->syrup->getKey(), 60, unitPriceOverride: 100)], 100), "{$this->syrup->sku}'s price can't be changed at the till.");
        $this->assertRefused(
            fn () => app(CheckoutService::class)->checkout(new CheckoutData($this->doc, $this->doctor, [new CartLine($this->tabs->getKey(), 1, unitPriceOverride: 100)], [new PaymentInput(PaymentMethod::Cash, 100_000)])),
            "{$this->tabs->sku}'s price can't be changed at the till.",
        );

        Role::findByName('cashier')->revokePermissionTo('pos.price_override');
        $this->doctor = User::query()->findOrFail($this->doctor->getKey());

        $this->assertRefused(fn () => $this->opd($this->visit(fee: 120_000), 50_000), 'You are not allowed to change prices.');
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(0, AuditLog::query()->where('event', 'price_overridden')->count());

        // The listed fee still sells.
        $this->assertSame(200_000, $this->opd($this->visit(), 50_000)->grand_total);
    }

    public function test_opd_bills_are_refused_off_an_opd_till_without_permission_with_discounts_or_a_wrong_amount(): void
    {
        $this->assertRefused(fn () => $this->opd($this->visit(), 50_000, ['device' => $this->docTill(opd: false)]), 'This till does not take OPD bills.');
        $this->docTill();

        $this->assertRefused(fn () => $this->opd($this->visit(), 50_000, ['saleDiscount' => 100]), 'OPD bills take no discounts.');
        $this->assertRefused(fn () => $this->opd([new CartLine($this->consult->getKey(), 1, lineDiscount: 100), new CartLine($this->tabs->getKey(), 1)], 50_000), 'OPD bills take no discounts.');
        $this->assertRefused(fn () => $this->opd($this->visit(), null), 'Enter the Medicines amount.');
        $this->assertRefused(fn () => $this->opd([new CartLine($this->consult->getKey(), 1)], 5_000), 'Add the medicines dispensed.');

        Role::findByName('cashier')->revokePermissionTo('pos.opd_bill');
        $this->doctor = User::query()->findOrFail($this->doctor->getKey());
        $this->assertRefused(fn () => $this->opd($this->visit(), 50_000), 'You are not allowed to ring up OPD bills.');

        $this->assertSame(0, Sale::query()->count());

        // A consultation alone is an OPD bill of nothing for medicines (the owner may).
        $sale = $this->opd([new CartLine($this->consult->getKey(), 1)], null, ['cashier' => $this->owner]);
        $this->assertSame(150_000, $sale->grand_total);
        $this->assertSame(0, $sale->opd_medicines_amount);
    }

    public function test_a_standard_bill_at_the_opd_till_prices_every_line(): void
    {
        $sale = $this->opd($this->visit(), null, ['billType' => BillType::Standard]);

        $this->assertSame(BillType::Standard, $sale->bill_type);
        $this->assertNull($sale->opd_medicines_amount);
        $this->assertSame(150_000 + 20_000 + 30_000, $sale->grand_total);
        $this->assertFalse($sale->items->contains(fn (SaleItem $item): bool => $item->is_opd_medicine));
        $this->assertTrue($sale->items->every(fn (SaleItem $item): bool => $item->list_price === null));
    }

    public function test_the_quote_endpoint_matches_what_checkout_records(): void
    {
        $payload = [
            'device' => $this->doc->uuid,
            'uuid' => (string) Str::uuid(),
            'bill_type' => 'opd',
            'medicines_amount' => '1000.01',
            'lines' => [
                ['variant_id' => $this->consult->getKey(), 'quantity' => 1, 'price' => '1250'],
                ['variant_id' => $this->syrup->getKey(), 'quantity' => 40],
                ['variant_id' => $this->tabs->getKey(), 'quantity' => 15],
            ],
            'payments' => [['method' => 'cash', 'amount' => '3000']],
        ];

        $quote = $this->actingAs($this->doctor)->postJson(route('pos.quote'), $payload)->assertOk();
        $this->assertSame(125_000 + 100_001, $quote->json('totals.grand_total'));
        $this->assertSame([false, true, true], array_column($quote->json('lines'), 'is_opd_medicine'));
        $this->assertSame([150_000, 500, 2_000], array_column($quote->json('lines'), 'list_price'));
        $this->assertSame([125_000, null, null], array_column($quote->json('lines'), 'line_total'));
        $this->assertSame(100_001, $quote->json('totals.medicines_total'));
        $this->assertSame(0, $quote->json('totals.medicines_tax'));
        $this->assertStringNotContainsString('cost', strtolower((string) $quote->getContent()));

        $checkout = $this->actingAs($this->doctor)->postJson(route('pos.checkout'), $payload)->assertOk();
        $this->assertSame($quote->json('totals.grand_total'), $checkout->json('sale.grand_total'));

        $sale = Sale::query()->with('items')->sole();
        $this->assertSame([125_000, 40_000, 60_001], $sale->items->pluck('line_total')->all());
        $this->assertSame(100_001, $sale->opd_medicines_amount);
        $this->assertSame(1, AuditLog::query()->where('event', 'price_overridden')->count());

        // Refused over HTTP as in the service.
        $this->actingAs($this->doctor)->postJson(route('pos.checkout'), [...$payload, 'uuid' => (string) Str::uuid(), 'sale_discount' => '10'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'OPD bills take no discounts.');
        $this->actingAs($this->doctor)->postJson(route('pos.quote'), [...$payload, 'medicines_amount' => '10', 'lines' => [$payload['lines'][0]]])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Add the medicines dispensed.');
    }

    public function test_an_opd_bill_replayed_offline_records_the_same_shares_and_stock(): void
    {
        $online = $this->opd($this->visit(fee: 120_000), 100_001);

        $offline = $this->opd([
            new CartLine($this->consult->getKey(), 1, tillUnitPrice: 120_000, tillPriceOverride: true),
            // Each medicine line with the list price the till weighed it by.
            new CartLine($this->syrup->getKey(), 40, tillUnitPrice: 500),
            new CartLine($this->tabs->getKey(), 15, tillUnitPrice: 2_000),
        ], 100_001, [
            'uuid' => (string) Str::uuid(),
            'isOfflineSync' => true,
            'soldAt' => now()->subMinutes(5),
            'tillGrandTotal' => 220_001,
        ]);

        $this->assertFalse($offline->needs_review, json_encode($offline->review_reasons) ?: '');
        $this->assertSame($online->grand_total, $offline->grand_total);
        $this->assertSame($online->items->pluck('line_total')->all(), $offline->items->pluck('line_total')->all());
        $this->assertSame($online->items->pluck('list_price')->all(), $offline->items->pluck('list_price')->all());
        $this->assertSame(120_000, $offline->items[0]->unit_price);
        $this->assertSame(2, AuditLog::query()->where('event', 'price_overridden')->count());

        // SOON went on the first visit: the second takes LATER.
        $this->assertSame(['LATER' => 15.0], $offline->items[2]->batches()->pluck('quantity', 'batch_no')->map(fn ($q): float => (float) $q)->all());
        $this->assertSame(['S1' => 520.0], $this->batchQuantities($this->syrup, $this->doctors));
        $this->assertLedgerMatches();
    }

    public function test_a_replayed_opd_bill_keeps_the_tills_split_tax_and_total(): void
    {
        // Tax on, exclusive; the tablets are exempt, the syrup standard-rated.
        $this->shop->update(['tax_enabled' => true, 'tax_rate' => 18, 'prices_include_tax' => false]);
        $this->tabs->product->update(['tax_category' => TaxCategory::Exempt]);
        $this->syrup->product->update(['tax_category' => TaxCategory::Standard]);
        $this->consult->product->update(['tax_category' => TaxCategory::Exempt]);

        // The till had the tablets at 2,000 and the syrup with no price for its ml (since priced
        // at 500 on the server): it weighed 2,000 x 15 : 0, so the tablets took all 1,500.00 and
        // there was no tax. 1,500.00 + 1,500.00 was charged and paid.
        $sale = $this->opd([
            new CartLine($this->consult->getKey(), 1, tillUnitPrice: 150_000),
            new CartLine($this->syrup->getKey(), 40, tillUnitPrice: 0),
            new CartLine($this->tabs->getKey(), 15, tillUnitPrice: 2_000),
        ], 150_000, [
            'uuid' => (string) Str::uuid(),
            'isOfflineSync' => true,
            'soldAt' => now()->subMinutes(5),
            'tillGrandTotal' => 300_000,
            'payments' => [new PaymentInput(PaymentMethod::Cash, 300_000)],
        ]);

        $this->assertFalse($sale->needs_review, json_encode($sale->review_reasons) ?: '');
        $this->assertSame([150_000, 0, 150_000], $sale->items->pluck('line_total')->all());
        $this->assertSame(0, $sale->tax_total);
        $this->assertSame(300_000, $sale->grand_total);
        $this->assertSame(300_000, $sale->paid_total);
        // The line keeps the server's list price; only the split followed the till.
        $this->assertSame(500, $sale->items[1]->list_price);
    }

    public function test_an_offline_opd_bill_that_breaks_the_rules_is_recorded_and_flagged(): void
    {
        $sale = $this->opd([
            new CartLine($this->consult->getKey(), 1, tillUnitPrice: 150_000),
            new CartLine($this->tabs->getKey(), 2, tillUnitPrice: 2_000),
        ], 3_000, [
            'device' => $this->docTill(opd: false),
            'uuid' => (string) Str::uuid(),
            'isOfflineSync' => true,
            'soldAt' => now()->subMinutes(5),
            'tillGrandTotal' => 153_000,
        ]);

        $this->assertTrue($sale->needs_review);
        $this->assertSame(['opd'], array_column($sale->review_reasons ?? [], 'type'));
        $this->assertSame('Till T2 does not take OPD bills.', $sale->review_reasons[0]['message']);
        $this->assertSame(BillType::Opd, $sale->bill_type);
        $this->assertSame(3_000, $sale->items[1]->line_total);
        $this->assertSame(['SOON' => 8.0, 'LATER' => 100.0], $this->batchQuantities($this->tabs, $this->doctors));
    }

    public function test_returning_an_opd_medicine_refunds_its_share(): void
    {
        $sale = $this->opd($this->visit(), 100_001);
        $tabs = $sale->items[2];

        $return = app(ReturnService::class)->process($sale, [$tabs->getKey() => 15], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);
        $this->assertSame(60_001, $return->refund_total);

        $partial = app(ReturnService::class)->process($sale, [$sale->items[1]->getKey() => 10], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);
        $this->assertSame(10_000, $partial->refund_total); // a quarter of 40,000
        $this->assertLedgerMatches();
    }
}
