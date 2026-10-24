<?php

namespace Tests\Feature\Pos;

use App\Models\Device;
use App\Models\ProductVariant;
use App\Models\VariantUnit;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * Scanning a pack's barcode finds its item and says which pack was scanned
 * (docs/PHARMACY.md §9, branch 8): the till adds a box, not one tablet.
 */
class PackBarcodeSearchTest extends BatchTestCase
{
    use WorksWithCheckout;

    private function search(string $term, ?Device $till = null): TestResponse
    {
        return $this->actingAs($this->cashier)->getJson(route('pos.search', ['device' => ($till ?? $this->till)->uuid, 'q' => $term]));
    }

    private function boxed(): ProductVariant
    {
        return $this->medicine('Cetirizine 10 mg Tablets', [['name' => 'Box', 'factor' => 10, 'price' => 9_000, 'barcode' => 'BOX-CET-10']]);
    }

    public function test_a_pack_barcode_is_an_exact_match_on_its_variant_with_the_scanned_unit(): void
    {
        $cetirizine = $this->boxed();

        $this->search('BOX-CET-10')
            ->assertOk()
            ->assertJsonPath('exact.variant_id', $cetirizine->getKey())
            ->assertJsonPath('exact.scanned_unit_id', $this->boxOf($cetirizine))
            ->assertJsonPath('exact.units.1.barcode', 'BOX-CET-10');
    }

    public function test_the_variants_own_barcode_or_sku_scans_with_no_unit(): void
    {
        $cetirizine = $this->boxed();

        $this->search((string) $cetirizine->barcode)
            ->assertJsonPath('exact.variant_id', $cetirizine->getKey())
            ->assertJsonPath('exact.scanned_unit_id', null);

        $this->search($cetirizine->sku)
            ->assertJsonPath('exact.variant_id', $cetirizine->getKey())
            ->assertJsonPath('exact.scanned_unit_id', null);
    }

    public function test_a_deactivated_packs_barcode_finds_nothing(): void
    {
        $cetirizine = $this->boxed();
        VariantUnit::query()->whereKey($this->boxOf($cetirizine))->update(['is_active' => false]);

        $this->search('BOX-CET-10')->assertOk()->assertJsonPath('exact', null);
        $this->assertCount(1, $this->search('Cetirizine')->json('results.0.units'));
    }

    public function test_free_text_results_never_carry_a_scanned_unit(): void
    {
        $this->boxed();

        $item = $this->search('Cetirizine')->assertOk()->json('results.0');

        $this->assertNull($item['scanned_unit_id']);
        $this->assertSame(['Tablet', 'Box'], array_column($item['units'], 'name'));
    }
}
