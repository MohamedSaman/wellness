<?php

namespace Tests\Feature\Pos;

use App\Services\ProductCatalogService;
use App\Support\TillSelection;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;

/**
 * Branch 8's item, catalog and page additions in a retail shop (pharmacy
 * off): every existing key keeps its value, an item is one base unit with
 * no drug details and no batches, packs still scan, and the page has no
 * pharmacy block.
 */
class RetailSearchPayloadTest extends PosTestCase
{
    private function search(string $term): TestResponse
    {
        return $this->actingAs($this->cashier)->getJson(route('pos.search', ['device' => $this->till->uuid, 'q' => $term]))->assertOk();
    }

    public function test_a_retail_item_is_one_base_unit_with_no_batches_or_drug_details(): void
    {
        $plug = $this->variant('CPR8EA-9');
        $item = $this->search($plug->sku)->json('exact');

        $this->assertSame($plug->getKey(), $item['variant_id']);
        $this->assertSame(145_000, $item['price']);
        $this->assertSame(40, $item['stock']);
        $this->assertSame('standard', $item['tax_category']);
        $this->assertNull($item['drug']);
        $this->assertSame([[
            'id' => (int) $plug->units()->value('id'),
            'name' => $plug->product->unit,
            'factor' => 1,
            'barcode' => null,
            'price' => 145_000,
            'is_base' => true,
            'is_default_sale' => true,
            'sellable' => true,
        ]], $item['units']);
        $this->assertTrue($item['sellable']);
        $this->assertFalse($item['whole_units_only']);
        $this->assertFalse($item['track_batches']);
        $this->assertFalse($item['allow_price_override']);
        $this->assertNull($item['min_days_to_expiry']);
        $this->assertSame([], $item['batches']);
        $this->assertNull($item['nearest_expiry']);
        $this->assertNull($item['scanned_unit_id']);
    }

    public function test_a_retail_pack_barcode_scans_to_its_pack(): void
    {
        $oil = app(ProductCatalogService::class)->create(
            ['name' => 'Engine oil 10W-40 (loose)', 'unit' => 'litre'],
            [['price' => 250_000, 'opening_stock' => 100, 'packs' => [['name' => 'Drum', 'factor' => 20, 'price' => 4_800_000, 'barcode' => 'DRUM-10W40']]]],
            user: $this->owner,
        )->variants()->firstOrFail();

        $exact = $this->search('DRUM-10W40')->json('exact');

        $this->assertSame($oil->getKey(), $exact['variant_id']);
        $this->assertSame((int) $oil->units()->where('name', 'Drum')->value('id'), $exact['scanned_unit_id']);
        $this->assertSame([250_000, 4_800_000], array_column($exact['units'], 'price'));
        $this->assertSame(250_000, $exact['price'], 'price stays the base unit\'s.');
    }

    public function test_the_retail_catalog_has_the_tills_location_and_no_pharmacy(): void
    {
        Feature::for($this->shop)->activate('module:offline_sync');
        $token = $this->till->issueSyncToken('Counter')->plainTextToken;

        $response = $this->withToken($token)->getJson(route('pos.sync.catalog'))
            ->assertOk()
            ->assertJsonPath('till.location_id', $this->location->getKey())
            ->assertJsonPath('till.location_code', $this->location->code)
            ->assertJsonPath('till.opd_billing', false)
            ->assertJsonPath('shop.pharmacy', false)
            ->assertJsonPath('shop.opdConsultationVariantId', null);

        $item = collect($response->json('items'))->firstWhere('sku', $this->variant('CPR8EA-9')->sku);
        $this->assertCount(1, $item['units']);
        $this->assertSame([], $item['batches']);
    }

    public function test_the_retail_page_has_no_pharmacy_block(): void
    {
        $this->actingAs($this->cashier)
            ->withCookie(TillSelection::COOKIE, $this->till->uuid)
            ->get(route('pos.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('features.pharmacy', false)
                ->where('pharmacy', null)
                ->where('tills.0.location', $this->location->name)
                ->where('till.location.code', $this->location->code)
                ->where('till.opdBilling', false));
    }
}
