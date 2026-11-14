<?php

namespace Tests\Feature\Pos;

use App\Enums\BatchStatus;
use App\Enums\PaymentMethod;
use App\Models\Device;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\StockRequest;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use App\Support\TillSelection;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * Till search by location (docs/PHARMACY.md, decision "Till search by
 * location"): the till lists its own location's stock and services by
 * default, can look at another selling location's stock or every
 * location's, and never shows Quarantine / Expired. The exact barcode/SKU
 * match is found whatever the scope, with stock at each location, so the
 * till can say where a scanned item is. Checkout is unchanged.
 */
class TillSearchLocationTest extends BatchTestCase
{
    use WorksWithCheckout;

    private User $opd;

    private Location $quarantine;

    /** Held at General Pharmacy only. */
    private ProductVariant $amoxicillin;

    /** Held at Doctor's Medicines (and General Pharmacy). */
    private ProductVariant $panadol;

    /** Held nowhere. */
    private ProductVariant $cetirizine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->opd = $this->cashierAt($this->doctors);
        $this->quarantine = Location::query()->where('code', 'QUAR')->firstOrFail();

        $this->amoxicillin = $this->medicine('Amoxicillin 500 mg Capsules');
        $this->receive($this->amoxicillin, 'AM1', '03/2028', 120);

        $this->panadol = $this->medicine('Panadol 500 mg Tablets');
        $this->receive($this->panadol, 'PA1', '03/2028', 200);
        $this->receive($this->panadol, 'PA2', '03/2028', 40, $this->doctors);

        $this->cetirizine = $this->medicine('Cetirizine 10 mg Tablets');

        // Quarantine holds stock of all three: it must never show.
        foreach ([$this->amoxicillin, $this->panadol, $this->cetirizine] as $variant) {
            $this->receive($variant, 'Q-'.$variant->getKey(), '03/2028', 5, $this->quarantine);
        }
    }

    /** @param array<string, mixed> $query */
    private function search(string $term, array $query = [], ?Device $till = null, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->opd)->getJson(route('pos.search', ['device' => ($till ?? $this->docTill())->uuid, 'q' => $term, ...$query]));
    }

    /** @return list<string> */
    private function names(TestResponse $response): array
    {
        return array_column($response->assertOk()->json('results'), 'name');
    }

    public function test_by_default_the_till_lists_only_its_own_locations_stock(): void
    {
        $this->assertSame(['Panadol 500 mg Tablets'], $this->names($this->search('500 mg')));
        $this->assertSame([], $this->names($this->search('amoxicillin')));
        $this->assertSame([], $this->names($this->search('cetirizine')));
        $this->assertSame([], $this->names($this->search('cetirizine', ['scope' => 'here'])));

        // General Pharmacy's till: its own stock.
        $this->assertSame(['Amoxicillin 500 mg Capsules', 'Panadol 500 mg Tablets'], $this->names($this->search('500 mg', till: $this->genTill(), as: $this->owner)));

        // "This location" results carry no stock per location.
        $this->search('panadol')->assertJsonMissingPath('results.0.stock_by_location');
    }

    public function test_services_and_open_price_items_are_always_listed(): void
    {
        $this->consultation();
        $this->openPriceItem('CBS (full blood count)', 120_000, 500_000, ask: true);
        $this->openPriceItem('Dressing', 10_000, 50_000, tracksStock: true);

        $this->assertSame(['Consultation'], $this->names($this->search('consult')));
        $this->assertSame(['CBS (full blood count)'], $this->names($this->search('CBS')));
        // An open-price item that tracks stock follows the stock rule: none here.
        $this->assertSame([], $this->names($this->search('dressing')));
    }

    public function test_an_exact_match_in_stock_here_is_found_as_before(): void
    {
        $this->search($this->panadol->sku)
            ->assertOk()
            ->assertJsonPath('exact.variant_id', $this->panadol->getKey())
            ->assertJsonPath('exact.stock', 40)
            ->assertJsonPath('exact.held_back', null)
            ->assertJsonPath('results.0.variant_id', $this->panadol->getKey());
    }

    public function test_an_exact_match_not_held_here_is_found_with_where_it_is_held(): void
    {
        $exact = $this->search((string) $this->amoxicillin->barcode)
            ->assertOk()
            ->assertJsonPath('results', [])
            ->json('exact');

        $this->assertSame($this->amoxicillin->getKey(), $exact['variant_id']);
        $this->assertEquals(0, $exact['stock']);
        // Selling locations only, in order: never Quarantine.
        $this->assertSame([
            ['id' => $this->general->getKey(), 'code' => 'GEN', 'stock' => 120],
            ['id' => $this->doctors->getKey(), 'code' => 'DOC', 'stock' => 0],
        ], $exact['stock_by_location']);
    }

    public function test_another_location_lists_what_it_holds_with_stock_per_location(): void
    {
        $this->consultation();

        $results = $this->search('500 mg', ['scope' => (string) $this->general->getKey()])->assertOk()->json('results');

        $this->assertSame(['Amoxicillin 500 mg Capsules', 'Panadol 500 mg Tablets'], array_column($results, 'name'));
        $this->assertSame([['GEN', 120], ['DOC', 0]], array_map(fn (array $level): array => [$level['code'], $level['stock']], $results[0]['stock_by_location']));
        $this->assertSame([['GEN', 200], ['DOC', 40]], array_map(fn (array $level): array => [$level['code'], $level['stock']], $results[1]['stock_by_location']));
        // Stock here stays the till's own location's.
        $this->assertEquals([0, 40], array_column($results, 'stock'));

        // Services are not held anywhere.
        $this->assertSame([], $this->names($this->search('consult', ['scope' => (string) $this->general->getKey()])));
    }

    public function test_all_locations_lists_every_match(): void
    {
        $this->consultation();

        $this->assertSame(
            ['Amoxicillin 500 mg Capsules', 'Panadol 500 mg Tablets'],
            $this->names($this->search('500 mg', ['scope' => 'all'])),
        );
        $cetirizine = $this->search('cetirizine', ['scope' => 'all'])->assertOk()->json('results.0');
        $this->assertSame([0, 0], array_column($cetirizine['stock_by_location'], 'stock'));
        $this->assertSame(['Consultation'], $this->names($this->search('consult', ['scope' => 'all'])));
    }

    public function test_quarantine_and_unknown_locations_are_refused(): void
    {
        $this->search('panadol', ['scope' => (string) $this->quarantine->getKey()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scope' => 'Choose a location that sells stock.']);
        $this->search('panadol', ['scope' => '999999'])->assertUnprocessable();
        $this->search('panadol', ['scope' => 'everywhere'])->assertUnprocessable();
    }

    public function test_the_page_offers_the_selling_locations_and_never_quarantine(): void
    {
        $this->actingAs($this->opd)
            ->withCookie(TillSelection::COOKIE, $this->docTill()->uuid)
            ->get(route('pos.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('features.locationSearch', true)
                ->where('searchLocations', [
                    ['id' => $this->general->getKey(), 'code' => 'GEN', 'name' => 'General Pharmacy'],
                    ['id' => $this->doctors->getKey(), 'code' => 'DOC', 'name' => "Doctor's Medicines"],
                ]));
    }

    public function test_with_one_selling_location_the_search_is_not_scoped(): void
    {
        // No till at Doctor's Medicines, and it no longer sells: General Pharmacy is the one selling location.
        Device::query()->where('location_id', $this->doctors->getKey())->delete();
        $this->doctors->update(['is_sellable' => false]);

        $this->actingAs($this->owner)
            ->withCookie(TillSelection::COOKIE, $this->genTill()->uuid)
            ->get(route('pos.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('features.locationSearch', false)->where('searchLocations', []));

        // Every match, as before, whatever the till asks, and no stock per location.
        $response = $this->search('cetirizine', ['scope' => (string) $this->quarantine->getKey()], $this->genTill(), $this->owner);
        $this->assertSame(['Cetirizine 10 mg Tablets'], $this->names($response));
        $response->assertJsonMissingPath('results.0.stock_by_location');
        $this->search($this->cetirizine->sku, till: $this->genTill(), as: $this->owner)->assertJsonMissingPath('exact.stock_by_location');
    }

    public function test_a_medicine_with_only_held_back_stock_here_says_why(): void
    {
        // Only expired stock at Doctor's Medicines (the clock is 2026-10-18).
        $expired = $this->medicine('Ibuprofen 400 mg Tablets');
        $this->foundExpired($expired, 'IB-OLD', '09/2026', 30, $this->doctors);

        // Only quarantined stock.
        $blocked = $this->medicine('Loratadine 10 mg Tablets');
        $this->receive($blocked, 'LO1', '03/2028', 30, $this->doctors)->update(['status' => BatchStatus::Quarantined]);

        // Only stock past its stop-selling date (30 days before expiry).
        $short = $this->medicine('Metformin 500 mg Tablets', minDaysToExpiry: 30);
        $this->receive($short, 'ME1', '2026-11-01', 30, $this->doctors);

        $byName = collect($this->search('Tablets')->assertOk()->json('results'))->keyBy('name');

        // Still listed (they hold stock here), each with the reason; nothing else changes.
        $this->assertSame('expired', $byName['Ibuprofen 400 mg Tablets']['held_back']);
        $this->assertSame('blocked', $byName['Loratadine 10 mg Tablets']['held_back']);
        $this->assertSame('near_expiry', $byName['Metformin 500 mg Tablets']['held_back']);
        $this->assertNull($byName['Panadol 500 mg Tablets']['held_back']);
        $this->assertSame(['ME1'], array_column($byName['Metformin 500 mg Tablets']['batches'], 'batch_no'));
    }

    public function test_the_tills_request_creates_a_stock_request(): void
    {
        $exact = $this->search((string) $this->amoxicillin->barcode)->json('exact');
        $box = collect($exact['units'])->firstWhere('is_base', false);

        // What the till's Request dialog sends: from the location holding it, for the till's.
        $this->actingAs($this->opd)->postJson(route('pos.stock-requests.store'), [
            'from' => $this->general->getKey(),
            'for' => $this->doctors->getKey(),
            'lines' => [['variant_id' => $exact['variant_id'], 'unit_id' => $box['id'], 'quantity' => 2]],
        ])->assertCreated()->assertJsonPath('request.number', 'RQ-000001');

        $request = StockRequest::query()->with('items')->sole();
        $this->assertSame([(int) $this->general->getKey(), (int) $this->doctors->getKey()], [(int) $request->from_location_id, (int) $request->to_location_id]);
        $this->assertSame([(int) $this->amoxicillin->getKey(), 20.0], [(int) $request->items->sole()->product_variant_id, (float) $request->items->sole()->base_quantity]);
    }

    public function test_checkout_still_refuses_a_line_from_another_locations_stock(): void
    {
        $payload = fn (array $line): array => [
            'device' => $this->docTill()->uuid,
            'uuid' => (string) Str::uuid(),
            'lines' => [['variant_id' => $this->amoxicillin->getKey(), 'quantity' => 1, ...$line]],
            'payments' => [['method' => 'cash', 'amount' => '100']],
        ];

        $this->actingAs($this->opd)->postJson(route('pos.checkout'), $payload(['location_id' => $this->general->getKey()]))
            ->assertUnprocessable()
            ->assertJsonPath('message', "You can't sell at General Pharmacy.");
        $this->actingAs($this->owner)->postJson(route('pos.checkout'), $payload(['location_id' => $this->quarantine->getKey()]))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Nothing can be sold from Quarantine / Expired.');
        // At the till's own location there is none to sell.
        $this->actingAs($this->opd)->postJson(route('pos.checkout'), $payload([]))->assertUnprocessable();
        $this->assertSame(0, Sale::query()->count());

        // Offline the sale already happened: recorded and flagged.
        $sale = app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->docTill(),
            cashier: $this->opd,
            lines: [new CartLine($this->panadol->getKey(), 1, tillUnitPrice: 2_000, locationId: (int) $this->general->getKey())],
            payments: [new PaymentInput(PaymentMethod::Cash, 10_000_000)],
            isOfflineSync: true,
            soldAt: now(),
        ));
        $this->assertTrue($sale->needs_review);
        $this->assertSame("{$this->opd->name} is not allowed to sell at General Pharmacy.", collect($sale->review_reasons)->firstWhere('type', 'location')['message'] ?? null);
    }
}
