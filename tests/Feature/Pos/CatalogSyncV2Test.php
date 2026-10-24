<?php

namespace Tests\Feature\Pos;

use App\Enums\BatchStatus;
use App\Models\Device;
use App\Models\ProductVariant;
use App\Models\VariantPrice;
use App\Models\VariantUnit;
use App\Models\VariantUnitLocation;
use App\Services\ProductCatalogService;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * The offline catalog in a pharmacy (docs/PHARMACY.md §9, branch 8): items
 * as the search presents them at the till's location, the till's location
 * and OPD flag, the shop's pharmacy checkout settings, and deltas that pick
 * up changes to units, any unit's price, selling loose at the till's
 * location and batches there.
 */
class CatalogSyncV2Test extends BatchTestCase
{
    use WorksWithCheckout;

    /** @var array<int, string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();

        Feature::for($this->shop)->activate('module:offline_sync');
    }

    /** @param  array<string, mixed>  $query */
    private function catalog(?Device $till = null, array $query = []): TestResponse
    {
        $till ??= $this->genTill();
        $this->tokens[$till->getKey()] ??= $till->issueSyncToken('Counter')->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->tokens[$till->getKey()])->getJson(route('pos.sync.catalog', $query))->assertOk();
    }

    /** @return array<string, mixed>|null */
    private function itemIn(TestResponse $response, ProductVariant $variant): ?array
    {
        return collect($response->json('items'))->firstWhere('variant_id', $variant->getKey());
    }

    /** A cursor taken well after the setup, so that only what changes after it is in the next delta. */
    private function cursor(?Device $till = null): string
    {
        $this->travel(5)->minutes();
        $cursor = $this->catalog($till)->json('cursor');
        $this->travel(5)->minutes();

        return $cursor;
    }

    public function test_the_till_carries_its_location_and_opd_flag(): void
    {
        $this->catalog()
            ->assertJsonPath('till.code', 'T1')
            ->assertJsonPath('till.location_id', $this->general->getKey())
            ->assertJsonPath('till.location_code', 'GEN')
            ->assertJsonPath('till.location_name', $this->general->name)
            ->assertJsonPath('till.opd_billing', false);

        $this->catalog($this->docTill())
            ->assertJsonPath('till.code', 'T2')
            ->assertJsonPath('till.location_code', 'DOC')
            ->assertJsonPath('till.opd_billing', true);
    }

    public function test_the_shop_carries_the_pharmacy_checkout_settings(): void
    {
        $consultation = $this->consultation();
        $this->setShopSetting('default_prescriber', 'Dr. S. Perera');

        $shop = $this->catalog()->json('shop');

        $this->assertTrue($shop['pharmacy']);
        $this->assertSame('required', $shop['rxPrescriber']);
        $this->assertSame('Dr. S. Perera', $shop['defaultPrescriber']);
        $this->assertFalse($shop['controlledRegister']);
        $this->assertSame((int) $this->shop->setting('min_days_to_expiry_for_sale'), $shop['minDaysToExpiryForSale']);
        $this->assertSame('names', $shop['opdReceiptMedicines']);
        $this->assertSame($consultation->getKey(), $shop['opdConsultationVariantId']);
        $this->assertTrue($shop['bankTransferReferenceRequired']);
    }

    public function test_a_full_sync_presents_items_at_the_tills_location(): void
    {
        $syrup = $this->syrup();
        $this->receive($syrup, 'GEN-1', '06/2027', 600);
        $this->receive($syrup, 'DOC-1', '03/2027', 120, $this->doctors);

        $atGeneral = $this->itemIn($this->catalog(), $syrup);
        $this->assertSame([false, true], array_column($atGeneral['units'], 'sellable'));
        $this->assertSame(['GEN-1'], array_column($atGeneral['batches'], 'batch_no'));
        $this->assertSame('2027-06-30', $atGeneral['nearest_expiry']);
        $this->assertSame(600.0, (float) $atGeneral['stock']);
        $this->assertNull($atGeneral['scanned_unit_id']);
        $this->assertTrue($atGeneral['track_batches']);
        $this->assertSame((int) $syrup->product_id, $atGeneral['product_id']);

        $atDoctors = $this->itemIn($this->catalog($this->docTill()), $syrup);
        $this->assertSame([true, true], array_column($atDoctors['units'], 'sellable'));
        $this->assertSame(['DOC-1'], array_column($atDoctors['batches'], 'batch_no'));
        $this->assertSame(120.0, (float) $atDoctors['stock']);
    }

    public function test_a_delta_picks_up_a_pack_price_change(): void
    {
        $syrup = $this->syrup();
        $cursor = $this->cursor();

        $this->catalog(null, ['since' => $cursor])->assertJsonCount(0, 'items');

        // Only the bottle's price row changes: not the variant, not the base unit's price.
        VariantPrice::query()
            ->whereIn('variant_unit_id', VariantUnit::query()->where('product_variant_id', $syrup->getKey())->where('name', 'Bottle')->select('id'))
            ->update(['price' => 27_500]);

        $item = $this->itemIn($this->catalog(null, ['since' => $cursor])->assertJsonCount(1, 'items'), $syrup);
        $this->assertSame(27_500, collect($item['units'])->firstWhere('name', 'Bottle')['price']);
    }

    public function test_a_delta_picks_up_a_unit_change(): void
    {
        $syrup = $this->syrup();
        $cursor = $this->cursor();

        VariantUnit::query()->where('product_variant_id', $syrup->getKey())->where('name', 'Bottle')->update(['barcode' => 'BTL-PARA-60']);

        $item = $this->itemIn($this->catalog(null, ['since' => $cursor])->assertJsonCount(1, 'items'), $syrup);
        $this->assertSame('BTL-PARA-60', collect($item['units'])->firstWhere('name', 'Bottle')['barcode']);
    }

    public function test_a_delta_picks_up_selling_loose_at_the_tills_location_only(): void
    {
        $syrup = $this->syrup();
        $docTill = $this->docTill();
        $atGeneral = $this->cursor();
        $atDoctors = $this->cursor($docTill);

        // Doctor's Medicines stops selling loose ml.
        VariantUnitLocation::query()
            ->where('location_id', $this->doctors->getKey())
            ->whereIn('variant_unit_id', VariantUnit::query()->where('product_variant_id', $syrup->getKey())->select('id'))
            ->update(['is_sellable' => false]);

        $item = $this->itemIn($this->catalog($docTill, ['since' => $atDoctors])->assertJsonCount(1, 'items'), $syrup);
        $this->assertSame([false, true], array_column($item['units'], 'sellable'));

        $this->catalog(null, ['since' => $atGeneral])->assertJsonCount(0, 'items');
    }

    public function test_a_delta_picks_up_a_selling_loose_rule_removed_from_the_tills_location(): void
    {
        // Not a medicine (no drug details touch the product): gauze by the piece, in boxes of 10,
        // not sold loose except at Doctor's Medicines.
        $gauze = app(ProductCatalogService::class)->create(
            ['name' => 'Gauze Swab', 'unit' => 'piece'],
            [['price' => 100, 'cost' => 50, 'allow_unit_sale' => false, 'loose_at' => [(int) $this->doctors->getKey() => true], 'packs' => [['name' => 'Box of 10', 'factor' => 10, 'price' => 900]]]],
            user: $this->owner,
        )->variants()->firstOrFail();
        $docTill = $this->docTill();
        $this->assertSame([true, true], array_column($this->itemIn($this->catalog($docTill), $gauze)['units'], 'sellable'));
        $cursor = $this->cursor($docTill);

        // The owner sets Doctor's Medicines back to "as the item": the rule row is deleted.
        $product = $gauze->product()->firstOrFail();
        app(ProductCatalogService::class)->update(
            $product,
            ['name' => $product->name],
            [['id' => $gauze->getKey(), 'sku' => $gauze->sku, 'barcode' => $gauze->barcode, 'reorder_level' => $gauze->reorder_level, 'bin_location' => $gauze->bin_location, 'is_active' => true, 'allow_unit_sale' => false, 'loose_at' => [(int) $this->doctors->getKey() => null]]],
            [],
            null,
            $this->owner,
            false,
        );
        $this->assertSame(0, VariantUnitLocation::query()->whereIn('variant_unit_id', VariantUnit::query()->where('product_variant_id', $gauze->getKey())->select('id'))->count());

        $item = $this->itemIn($this->catalog($docTill, ['since' => $cursor])->assertJsonCount(1, 'items'), $gauze);
        $this->assertSame([false, true], array_column($item['units'], 'sellable'));
    }

    public function test_a_delta_picks_up_a_batch_received_at_the_tills_location(): void
    {
        $syrup = $this->syrup();
        $cursor = $this->cursor();

        $this->receive($syrup, 'GEN-1', '06/2027', 600);

        $item = $this->itemIn($this->catalog(null, ['since' => $cursor])->assertJsonCount(1, 'items'), $syrup);
        $this->assertSame(['GEN-1'], array_column($item['batches'], 'batch_no'));
        $this->assertSame('2027-06-30', $item['nearest_expiry']);
    }

    public function test_a_delta_picks_up_batch_changes_at_the_tills_location_only(): void
    {
        $syrup = $this->syrup();
        $this->receive($syrup, 'GEN-1', '06/2027', 600);
        $this->receive($syrup, 'DOC-1', '03/2027', 120, $this->doctors);
        $cursor = $this->cursor();

        // A batch put on hold changes no stock level and no variant: only the batch row.
        $this->batch($syrup, 'DOC-1', $this->doctors)->update(['status' => BatchStatus::Quarantined]);
        $this->catalog(null, ['since' => $cursor])->assertJsonCount(0, 'items');

        $this->batch($syrup, 'GEN-1')->update(['status' => BatchStatus::Quarantined]);

        $item = $this->itemIn($this->catalog(null, ['since' => $cursor])->assertJsonCount(1, 'items'), $syrup);
        $this->assertSame([], $item['batches']);
        $this->assertNull($item['nearest_expiry']);
    }

    public function test_the_catalog_runs_the_same_queries_for_one_item_as_for_many(): void
    {
        $count = function (): int {
            $this->catalog(); // issues the token and loads the till first

            $this->app['db']->flushQueryLog();
            $this->app['db']->enableQueryLog();
            $this->catalog();
            $queries = count($this->app['db']->getQueryLog());
            $this->app['db']->disableQueryLog();

            return $queries;
        };

        $first = $this->syrup('Countsyrup 00');
        $this->receive($first, 'C0', '06/2027', 60);
        $before = $count();

        foreach (range(1, 10) as $n) {
            $syrup = $this->syrup(sprintf('Countsyrup %02d', $n));
            $this->receive($syrup, "C{$n}", '06/2027', 60);
        }

        $this->assertSame($before, $count());
    }
}
