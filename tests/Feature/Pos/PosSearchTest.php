<?php

namespace Tests\Feature\Pos;

use App\Models\Device;
use App\Models\Shop;
use App\Models\User;
use App\Models\VehicleModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

class PosSearchTest extends PosTestCase
{
    private function search(array $query): TestResponse
    {
        return $this->actingAs($this->cashier)->getJson(route('pos.search', ['device' => $this->till->uuid, ...$query]));
    }

    public function test_an_exact_barcode_or_sku_is_returned_as_an_exact_match(): void
    {
        $plug = $this->variant('CPR8EA-9');

        $this->search(['q' => $plug->barcode])
            ->assertOk()
            ->assertJsonPath('exact.variant_id', $plug->getKey())
            ->assertJsonPath('exact.sku', $plug->sku)
            ->assertJsonPath('exact.stock', 40);

        $this->search(['q' => $plug->sku])->assertJsonPath('exact.variant_id', $plug->getKey());
    }

    public function test_free_text_returns_name_part_number_oem_price_and_stock(): void
    {
        $response = $this->search(['q' => 'spark'])->assertOk()->assertJsonPath('exact', null);

        $item = collect($response->json('results'))->firstWhere('part_number', 'CPR8EA-9');

        $this->assertNotNull($item);
        $this->assertSame('NGK Spark Plug', $item['name']);
        $this->assertSame(145_000, $item['price']);
        $this->assertSame(40, $item['stock']);
        $this->assertSame(['31916-KRP-B01'], $item['oem_numbers']);

        $this->assertNotEmpty($this->search(['q' => '31916-KRP'])->json('results'));
    }

    public function test_retail_items_are_standard_rated_and_carry_no_drug_details(): void
    {
        $item = $this->search(['q' => 'spark'])->json('results.0');

        $this->assertSame('standard', $item['tax_category']);
        $this->assertNull($item['drug']);
    }

    public function test_results_are_limited_to_twenty(): void
    {
        $this->assertLessThanOrEqual(20, count($this->search(['q' => 'a'])->json('results')));
    }

    public function test_search_runs_the_same_number_of_queries_however_many_items_match(): void
    {
        $count = function (string $term): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $results = count($this->search(['q' => $term])->assertOk()->json('results'));
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries * 1000 + $results;
        };

        $many = $count('a');
        $one = $count('CPR8EA-9');

        $this->assertGreaterThan(1, $many % 1000);
        $this->assertLessThanOrEqual(intdiv($one, 1000) + 1, intdiv($many, 1000));
    }

    public function test_responses_never_contain_cost_prices(): void
    {
        $this->search(['q' => 'a'])->assertOk();
        $json = $this->search(['q' => 'a'])->getContent();

        $this->assertStringNotContainsString('cost', strtolower($json));
        $this->assertStringNotContainsString((string) $this->variant('CPR8EA-9')->cost_price.',', $json);
    }

    public function test_find_parts_for_a_vehicle_by_model_make_and_year(): void
    {
        $pulsar = VehicleModel::query()->where('name', 'Pulsar 150')->firstOrFail();

        $byModel = collect($this->search(['model_id' => $pulsar->getKey()])->json('results'))->pluck('part_number');
        $this->assertContains('CPR8EA-9', $byModel->all());

        $byMake = $this->search(['make_id' => $pulsar->vehicle_make_id])->json('results');
        $this->assertNotEmpty($byMake);

        // Brake pads fit the Pulsar only from 2012 onwards.
        $pads = fn (int $year) => collect($this->search(['model_id' => $pulsar->getKey(), 'year' => $year])->json('results'))
            ->contains(fn (array $item) => str_contains(strtolower($item['name']), 'brake'));
        $this->assertTrue($pads(2015));
        $this->assertFalse($pads(2005));
    }

    public function test_a_search_without_text_or_vehicle_returns_nothing(): void
    {
        $this->search([])->assertOk()->assertJsonPath('results', [])->assertJsonPath('exact', null);
    }

    public function test_search_is_rate_limited(): void
    {
        for ($i = 0; $i < 240; $i++) {
            $this->search(['q' => 'x'])->assertOk();
        }

        $this->search(['q' => 'x'])->assertStatus(429);
    }

    public function test_a_till_from_another_shop_is_refused(): void
    {
        $other = Shop::query()->create(['name' => 'Other Shop', 'code' => 'OTHER']);
        $foreign = (new Device)->forceFill([
            'shop_id' => $other->getKey(),
            'location_id' => $this->location->getKey(),
            'name' => 'Foreign till',
            'code' => 'X1',
        ]);
        $foreign->save();

        $this->actingAs($this->cashier)
            ->getJson(route('pos.search', ['device' => $foreign->uuid, 'q' => 'a']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('device');
    }

    public function test_users_without_the_checkout_permission_are_blocked(): void
    {
        $noRole = User::factory()->create();
        $noRole->forceFill(['shop_id' => $this->shop->getKey()])->save();

        $this->actingAs($noRole)->getJson(route('pos.search', ['device' => $this->till->uuid, 'q' => 'a']))->assertForbidden();
    }

    public function test_guests_are_blocked(): void
    {
        $this->getJson(route('pos.search', ['device' => $this->till->uuid, 'q' => 'a']))->assertUnauthorized();
    }
}
