<?php

namespace Tests\Feature\Pos;

use App\Models\Location;
use App\Services\CatalogSyncService;
use App\Services\ItemSearchService;
use Illuminate\Support\Facades\Process;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * The till's offline search (resources/js/lib/offline/searchCatalog.ts, run
 * here under node over the till's real offline catalog) finds what the
 * server finds, for "this location" and every location: the same exact
 * match and pack, the same results in the same order, and the same stock,
 * nearest expiry and held-back reason (docs/PHARMACY.md, "Till search by
 * location"). Skipped when node is not installed.
 */
class TillSearchOfflineParityTest extends BatchTestCase
{
    use WorksWithCheckout;

    public function test_the_offline_search_matches_the_server(): void
    {
        $version = Process::run(['node', '--version']);
        if (! $version->successful() || ! preg_match('/^v(\d+)\.(\d+)/', trim($version->output()), $m) || [(int) $m[1], (int) $m[2]] < [22, 15]) {
            $this->markTestSkipped('Needs node 22.15 or later (type stripping, module hooks) to run the till\'s search.');
        }

        $till = $this->docTill();
        $quarantine = Location::query()->where('code', 'QUAR')->firstOrFail();

        $amoxicillin = $this->medicine('Amoxicillin 500 mg Capsules', [['name' => 'Box', 'factor' => 10, 'price' => 30_000, 'barcode' => 'BOX-AMX']]);
        $this->receive($amoxicillin, 'AM1', '03/2028', 120);
        $panadol = $this->medicine('Panadol 500 mg Tablets', [['name' => 'Box', 'factor' => 10, 'price' => 18_000, 'barcode' => 'BOX-PAN']]);
        $this->receive($panadol, 'PA1', '03/2028', 200);
        $this->receive($panadol, 'PA2', '03/2028', 40, $this->doctors);
        $this->receive($this->medicine('Cetirizine 10 mg Tablets'), 'CE1', '03/2028', 5, $quarantine);
        $expired = $this->medicine('Ibuprofen 400 mg Tablets');
        $this->foundExpired($expired, 'IB-OLD', '09/2026', 30, $this->doctors);
        $this->receive($this->medicine('Metformin 500 mg Tablets', minDaysToExpiry: 30), 'ME1', '2026-11-01', 30, $this->doctors);
        $this->consultation();
        $this->openPriceItem('CBS (full blood count)', 120_000, 500_000, ask: true);
        $this->openPriceItem('Dressing', 10_000, 50_000, tracksStock: true);

        $terms = ['500 mg', 'paracetamol', 'Tablets', $panadol->sku, 'BOX-PAN', 'BOX-AMX', (string) $amoxicillin->barcode, 'consult', 'CBS', 'dress', 'cetirizine', 'nothing like this'];
        $cases = [];
        foreach ($terms as $term) {
            foreach (['here', 'all'] as $scope) {
                $cases[] = ['term' => $term, 'scope' => $scope];
            }
        }

        $catalog = app(CatalogSyncService::class)->changes($till, null);
        $result = Process::path(base_path())
            ->input(json_encode([
                'catalog' => ['shop' => $catalog['shop'], 'items' => $catalog['items']],
                'day' => now($this->shop->timezone)->toDateString(),
                'cases' => $cases,
            ], JSON_THROW_ON_ERROR))
            ->run(['node', '--experimental-strip-types', '--no-warnings', 'tests/js/search-offline.mjs']);
        $this->assertTrue($result->successful(), $result->errorOutput());
        $offline = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);

        $shape = fn (?array $item): ?array => $item === null ? null : [
            'variant_id' => $item['variant_id'],
            'stock' => $item['stock'] === null ? null : (float) $item['stock'],
            'nearest_expiry' => $item['nearest_expiry'],
            'held_back' => $item['held_back'],
            'scanned_unit_id' => $item['scanned_unit_id'],
        ];

        foreach ($cases as $n => $case) {
            $server = app(ItemSearchService::class)->search($till, $case['term'], scope: $case['scope']);
            $label = "\"{$case['term']}\" ({$case['scope']})";

            $this->assertSame($shape($server['exact']), $shape($offline[$n]['exact']), "exact, {$label}");
            $this->assertSame(array_map($shape, $server['results']), array_map($shape, $offline[$n]['results']), "results, {$label}");
        }

        // The cases cover what they are meant to: stock elsewhere only, held back, services.
        $here = fn (string $term): array => array_column($offline[array_search(['term' => $term, 'scope' => 'here'], $cases, true)]['results'], 'name');
        $this->assertSame([], $here((string) $amoxicillin->barcode));
        $this->assertSame(['CBS (full blood count)'], $here('CBS'));
        $this->assertSame([], $here('dress'));
        $this->assertSame([], $here('cetirizine'));
        $this->assertContains('expired', array_column($offline[array_search(['term' => 'Tablets', 'scope' => 'here'], $cases, true)]['results'], 'held_back'));
        $this->assertSame((int) $amoxicillin->getKey(), $offline[array_search(['term' => 'BOX-AMX', 'scope' => 'here'], $cases, true)]['exact']['variant_id']);
    }
}
