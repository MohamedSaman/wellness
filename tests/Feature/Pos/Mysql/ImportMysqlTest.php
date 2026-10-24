<?php

namespace Tests\Feature\Pos\Mysql;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Models\ImportRun;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\VariantUnit;
use App\Services\Import\ImportService;
use App\Support\UnitCost;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Pos\Concerns\WorksWithImports;

/**
 * Imports on MySQL (docs/PHARMACY.md §10): one transaction per file for
 * real (a failure leaves nothing behind), the same file imported from two
 * places at once creates each item once (imports of a shop take the shop
 * row first, so the second sees the first's items), and costs to 4 places
 * on MySQL's DECIMAL. With POS_IMPORT_BENCH=1, times 5,000 medicines and
 * 10,000 opening stock rows.
 */
#[Group('mysql')]
class ImportMysqlTest extends MysqlTestCase
{
    use WorksWithImports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImportStorage();

        $this->actingAs($this->owner);
    }

    /** @return list<array<string, mixed>> */
    private function medicines(int $count): array
    {
        $forms = ['Tablet', 'Capsule', 'Syrup', 'Cream', 'Injection'];
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $form = $forms[$i % 5];
            $rows[] = [
                'sku' => "MED-{$i}", 'name' => "Medicine {$i} {$form}", 'generic_names' => 'Generic '.intdiv($i, 3),
                'strength' => 100 + $i, 'strength_unit' => 'mg', 'dosage_form' => $form, 'schedule' => 'OTC',
                'barcode' => (string) (4_790_000_000_000 + $i), 'price' => '10.00',
                'pack1_name' => 'Box of 30', 'pack1_factor' => 30, 'pack1_price' => '280',
                'reorder:GEN' => 10, 'sell_loose:DOC' => 'Yes', 'category' => 'Group '.($i % 20), 'manufacturer' => 'Maker '.($i % 50),
            ];
        }

        return $rows;
    }

    /** A checked run, queued as confirm() leaves it, but not yet written. */
    private function queued(ImportType $type, string $path): ImportRun
    {
        $run = $this->preview($type, $path)->run;
        $run->update(['status' => ImportStatus::Queued]);

        return $run;
    }

    public function test_the_same_file_imported_from_two_places_at_once_creates_each_item_once(): void
    {
        $path = $this->templateFile(ImportType::Items, $this->medicines(40));
        $runs = [$this->queued(ImportType::Items, $path), $this->queued(ImportType::Items, $path)];

        $outcomes = $this->race(2, fn (int $worker) => app(ImportService::class)->run($runs[$worker]));

        $this->assertSame(['ok', 'ok'], $outcomes);
        $this->assertSame(40, Product::query()->count());
        $this->assertSame(80, VariantUnit::query()->count());

        $results = collect($runs)->map(fn (ImportRun $run): array => $run->refresh()->result_counts ?? [])->sortBy('created')->values();
        $this->assertSame([ImportStatus::Completed, ImportStatus::Completed], array_map(fn (ImportRun $run) => $run->status, $runs));
        $this->assertSame([0, 40], $results->pluck('created')->all());
        $this->assertSame([40, 0], $results->pluck('skipped')->all());
    }

    public function test_a_failing_row_leaves_nothing_behind_and_costs_are_exact(): void
    {
        $this->assertImported($this->import(ImportType::Items, $this->templateFile(ImportType::Items, $this->medicines(3))));

        $rows = [
            ['item' => 'MED-1', 'location' => 'GEN', 'batch_no' => 'A1', 'expiry' => '09/2028', 'quantity' => 3, 'unit' => 'Box of 30', 'unit_cost' => '100.00'],
            ['item' => 'MED-1', 'location' => 'GEN', 'batch_no' => 'A2', 'expiry' => '10/2028', 'quantity' => 7, 'unit_cost' => '3.3333'],
            ['item' => 'MED-2', 'location' => 'DOC', 'batch_no' => 'B1', 'expiry' => '09/2028', 'quantity' => 1, 'unit' => 'Box of 30', 'unit_cost' => '95'],
        ];

        // MED-2 is deactivated between the check and the write: the file fails as a whole.
        $run = $this->queued(ImportType::OpeningStock, $this->templateFile(ImportType::OpeningStock, $rows));
        $this->doctors->update(['is_active' => false]);
        app(ImportService::class)->run($run);

        $this->assertSame(ImportStatus::Failed, $run->refresh()->status);
        $this->assertSame([0, 0], [StockMovement::query()->count(), StockBatch::query()->count()]);

        $this->doctors->update(['is_active' => true]);
        $this->assertImported($this->import(ImportType::OpeningStock, $this->templateFile(ImportType::OpeningStock, $rows)));

        // 90 tablets at 333.3333 and 7 at 333.33 (minor units per tablet), averaged to 4 places.
        $level = StockLevel::query()->where('location_id', $this->general->getKey())->whereHas('variant', fn ($q) => $q->where('sku', 'MED-1'))->sole();
        $this->assertSame('97.000', $level->quantity);
        $this->assertTrue(UnitCost::equals('333.3331', $level->average_cost), $level->average_cost);
        $this->assertSame(['333.3333', '333.3300'], StockMovement::query()->where('location_id', $this->general->getKey())->orderBy('id')->pluck('received_cost')->all());
        $this->assertLedgerMatches();
    }

    public function test_benchmark_five_thousand_medicines_and_ten_thousand_stock_rows(): void
    {
        if (! filter_var(getenv('POS_IMPORT_BENCH'), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set POS_IMPORT_BENCH=1 to time large imports.');
        }

        $items = (int) (getenv('POS_IMPORT_BENCH_ITEMS') ?: 5000);

        $started = microtime(true);
        $preview = $this->preview(ImportType::Items, $this->templateFile(ImportType::Items, $this->medicines($items)));
        $checked = microtime(true);
        $run = app(ImportService::class)->confirm($preview->run, $this->owner)->refresh();
        $this->assertImported($run);
        $done = microtime(true);
        fwrite(STDERR, sprintf("\n%d medicines: check %.1fs, import %.1fs\n", $items, $checked - $started, $done - $checked));

        $stock = [];

        for ($i = 1; $i <= 2 * $items; $i++) {
            $stock[] = [
                'item' => 'MED-'.(1 + ($i % $items)), 'location' => $i % 3 === 0 ? 'DOC' : 'GEN', 'batch_no' => "B{$i}",
                'expiry' => sprintf('%02d/2029', 1 + $i % 12), 'quantity' => 10 + $i % 7, 'unit' => $i % 2 === 1 ? 'Box of 30' : null, 'unit_cost' => '7.50',
            ];
        }

        $started = microtime(true);
        $preview = $this->preview(ImportType::OpeningStock, $this->templateFile(ImportType::OpeningStock, $stock));
        $checked = microtime(true);
        $run = app(ImportService::class)->confirm($preview->run, $this->owner)->refresh();
        $this->assertImported($run);
        $done = microtime(true);
        fwrite(STDERR, sprintf("%d opening stock rows: check %.1fs, import %.1fs\n", 2 * $items, $checked - $started, $done - $checked));

        $this->assertSame(2 * $items, StockMovement::query()->count());
    }
}
