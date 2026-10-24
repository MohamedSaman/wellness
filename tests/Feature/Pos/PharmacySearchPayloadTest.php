<?php

namespace Tests\Feature\Pos;

use App\Enums\AdjustmentReason;
use App\Enums\BatchStatus;
use App\Enums\StockMovementType;
use App\Models\Device;
use App\Models\ProductVariant;
use App\Models\VariantPrice;
use App\Models\VariantUnit;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * What the till gets for an item in a pharmacy (docs/PHARMACY.md §9,
 * branch 8): its units as sold at the till's location, whether it can be
 * sold there at all, stock and batches at that location in first expiry
 * first out order, and the nearest expiry it may still sell. The clock is
 * fixed at 2026-10-18 (BatchTestCase).
 */
class PharmacySearchPayloadTest extends BatchTestCase
{
    use WorksWithCheckout;

    protected function setUp(): void
    {
        parent::setUp();

        // What each counter's till shows, not who may use it: the cashier may use both.
        $this->allowAt($this->cashier, $this->general, $this->doctors);
    }

    /** @return array<string, mixed> */
    private function item(string $term, ?Device $till = null): array
    {
        $item = $this->search($term, $till)->assertOk()->json('results.0');
        $this->assertIsArray($item, "Nothing found for {$term}.");

        return $item;
    }

    private function search(string $term, ?Device $till = null): TestResponse
    {
        return $this->actingAs($this->cashier)->getJson(route('pos.search', ['device' => ($till ?? $this->genTill())->uuid, 'q' => $term]));
    }

    /** Stock out first expiry first out at General Pharmacy, as a sale would take it. */
    private function takeOut(ProductVariant $variant, float $quantity, bool $allowNegative = false): void
    {
        app(StockService::class)->issue($variant, $this->general, StockMovementType::AdjustmentOut, $quantity, $this->owner,
            allowNegative: $allowNegative, reason: AdjustmentReason::Damaged);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, array<string, mixed>>
     */
    private function unitsByName(array $item): array
    {
        return collect($item['units'])->keyBy('name')->all();
    }

    public function test_syrup_sells_by_the_bottle_only_at_general_pharmacy_and_loose_at_doctors_medicines(): void
    {
        $syrup = $this->syrup();

        $atGeneral = $this->item('Paracetamol Syrup');
        $units = $this->unitsByName($atGeneral);

        $this->assertSame($syrup->getKey(), $atGeneral['variant_id']);
        $this->assertFalse($units['ml']['sellable']);
        $this->assertTrue($units['Bottle']['sellable']);
        $this->assertTrue($atGeneral['sellable']);
        $this->assertSame(500, $atGeneral['price']);
        $this->assertSame(['id', 'name', 'factor', 'barcode', 'price', 'is_base', 'is_default_sale', 'sellable'], array_keys($units['Bottle']));
        $this->assertSame([60.0, 25_000, false], [(float) $units['Bottle']['factor'], $units['Bottle']['price'], $units['Bottle']['is_base']]);
        $this->assertTrue($units['ml']['is_base']);
        $this->assertSame('ml', $atGeneral['units'][0]['name'], 'The base unit comes first.');

        $atDoctors = $this->unitsByName($this->item('Paracetamol Syrup', $this->docTill()));

        $this->assertTrue($atDoctors['ml']['sellable']);
        $this->assertTrue($atDoctors['Bottle']['sellable']);
    }

    public function test_an_item_is_sellable_only_where_a_unit_sold_there_has_a_price(): void
    {
        $syrup = $this->syrup();
        $bottle = (int) $syrup->units()->where('name', 'Bottle')->value('id');

        // The bottle loses its price: General Pharmacy sells no other unit of it.
        VariantPrice::query()->where('variant_unit_id', $bottle)->delete();

        $this->assertFalse($this->item('Paracetamol Syrup')['sellable']);
        $this->assertTrue($this->item('Paracetamol Syrup', $this->docTill())['sellable']);

        VariantPrice::query()->where('product_variant_id', $syrup->getKey())->delete();

        $unpriced = $this->item('Paracetamol Syrup', $this->docTill());
        $this->assertFalse($unpriced['sellable']);
        $this->assertNull($unpriced['price']);
    }

    public function test_medicines_carry_their_selling_rules(): void
    {
        $this->medicine('Amoxicillin 250 mg Capsules', minDaysToExpiry: 30);
        $consultation = $this->consultation();

        $medicine = $this->item('Amoxicillin');
        $this->assertTrue($medicine['whole_units_only']);
        $this->assertTrue($medicine['track_batches']);
        $this->assertFalse($medicine['allow_price_override']);
        $this->assertSame(30, $medicine['min_days_to_expiry']);
        $this->assertNull($medicine['scanned_unit_id']);

        $fee = collect($this->search('Consultation')->json('results'))->firstWhere('variant_id', $consultation->getKey());
        $this->assertTrue($fee['allow_price_override']);
        $this->assertFalse($fee['track_batches']);
        $this->assertSame([], $fee['batches']);
        $this->assertNull($fee['nearest_expiry']);
        $this->assertNull($fee['min_days_to_expiry']);
        $this->assertNull($fee['drug']);
    }

    public function test_stock_and_batches_are_the_tills_location_only(): void
    {
        $syrup = $this->syrup();
        $this->receive($syrup, 'GEN-1', '06/2027', 600);
        $this->receive($syrup, 'DOC-1', '03/2027', 120, $this->doctors);

        $atGeneral = $this->item('Paracetamol Syrup');
        $this->assertSame(600.0, (float) $atGeneral['stock']);
        $this->assertSame(['GEN-1'], array_column($atGeneral['batches'], 'batch_no'));

        $atDoctors = $this->item('Paracetamol Syrup', $this->docTill());
        $this->assertSame(120.0, (float) $atDoctors['stock']);
        $this->assertSame([['id' => $this->batch($syrup, 'DOC-1', $this->doctors)->getKey(), 'batch_no' => 'DOC-1', 'expiry' => '2027-03-31', 'quantity' => 120]], $atDoctors['batches']);
    }

    public function test_batches_are_listed_first_expiry_first_without_expired_quarantined_or_unallocated_ones(): void
    {
        $panadol = $this->medicine('Paracip 500 mg Tablets');
        // An oversell books to the unallocated batch (the next receipt settles it).
        $this->takeOut($panadol, 5, allowNegative: true);
        $this->receive($panadol, 'LATE', '06/2027', 50);
        $this->receive($panadol, 'SOON', '12/2026', 20);
        $this->receive($panadol, 'MID', '03/2027', 30);
        $this->foundExpired($panadol, 'GONE', '09/2026', 5);
        $this->receive($panadol, 'HELD', '11/2026', 10)->update(['status' => BatchStatus::Quarantined]);

        // Give the unallocated batch stock, so only is_unallocated keeps it out.
        $this->unallocated($panadol)?->update(['quantity' => 5]);
        $this->assertNotNull($this->unallocated($panadol));

        $item = $this->item('Paracip');

        $this->assertSame(['SOON', 'MID', 'LATE'], array_column($item['batches'], 'batch_no'));
        $this->assertSame(['2026-12-31', '2027-03-31', '2027-06-30'], array_column($item['batches'], 'expiry'));
        $this->assertSame('2026-12-31', $item['nearest_expiry']);
    }

    public function test_a_sold_out_batch_is_not_listed(): void
    {
        $panadol = $this->medicine('Paracip 500 mg Tablets');
        $this->receive($panadol, 'SOON', '12/2026', 4);
        $this->receive($panadol, 'LATE', '06/2027', 50);

        $this->takeOut($panadol, 4);

        $this->assertSame(['LATE'], array_column($this->item('Paracip')['batches'], 'batch_no'));
    }

    public function test_the_nearest_expiry_respects_the_minimum_days_to_expiry(): void
    {
        $own = $this->medicine('Ownrule 10 mg Tablets', minDaysToExpiry: 90);
        $this->receive($own, 'SOON', '12/2026', 20); // 74 days away
        $this->receive($own, 'LATE', '06/2027', 50);

        $item = $this->item('Ownrule');
        // Listed (the till shows what is on the shelf), but not the one it may sell.
        $this->assertSame(['SOON', 'LATE'], array_column($item['batches'], 'batch_no'));
        $this->assertSame('2027-06-30', $item['nearest_expiry']);
        $this->assertSame(90, $item['min_days_to_expiry']);

        $shopRule = $this->medicine('Shoprule 10 mg Tablets');
        $this->receive($shopRule, 'SOON', '12/2026', 20);
        $this->receive($shopRule, 'LATE', '06/2027', 50);

        $this->assertSame('2026-12-31', $this->item('Shoprule')['nearest_expiry']);

        $this->setShopSetting('min_days_to_expiry_for_sale', 90);

        $item = $this->item('Shoprule');
        $this->assertSame('2027-06-30', $item['nearest_expiry']);
        $this->assertNull($item['min_days_to_expiry'], 'Null means the shop setting.');

        $this->setShopSetting('min_days_to_expiry_for_sale', 365);
        $this->assertNull($this->item('Shoprule')['nearest_expiry'], 'No batch is sellable a year out.');
    }

    public function test_a_search_runs_the_same_queries_for_one_item_as_for_fifteen(): void
    {
        foreach (range(1, 15) as $n) {
            $syrup = $this->syrup(sprintf('Querysyrup %02d', $n));
            $this->receive($syrup, "Q{$n}A", '06/2027', 60);
            $this->receive($syrup, "Q{$n}B", '03/2027', 60);
            $this->receive($syrup, "Q{$n}D", '03/2027', 60, $this->doctors);
        }

        $this->search('warm up'); // loads the cashier's permissions once

        $count = function (string $term, int $expected): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $results = $this->search($term)->assertOk()->json('results');
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            $this->assertCount($expected, $results);
            $this->assertCount(2, $results[0]['batches']);
            $this->assertCount(2, $results[0]['units']);

            return $queries;
        };

        $this->assertSame($count('Querysyrup 07', 1), $count('Querysyrup', 15));
    }

    public function test_responses_never_contain_costs(): void
    {
        $syrup = $this->syrup();
        $this->receive($syrup, 'GEN-1', '06/2027', 600, cost: 287);

        $json = $this->search('Paracetamol Syrup')->assertOk()->getContent();

        $this->assertStringNotContainsString('cost', strtolower((string) $json));
        $this->assertStringNotContainsString('287', (string) $json);
    }

    public function test_a_deactivated_unit_is_not_offered(): void
    {
        $syrup = $this->syrup();
        VariantUnit::query()->where('product_variant_id', $syrup->getKey())->where('name', 'Bottle')->update(['is_active' => false]);

        $item = $this->item('Paracetamol Syrup');

        $this->assertSame(['ml'], array_column($item['units'], 'name'));
        // With no bottle to sell, the ml sells everywhere (Q16).
        $this->assertTrue($item['units'][0]['sellable']);
    }
}
