<?php

namespace Tests\Feature\Pos;

use App\Enums\ImportExisting;
use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Enums\StockMovementType;
use App\Exceptions\PosException;
use App\Models\ImportRun;
use App\Models\ProductVariant;
use App\Models\StockBatch;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\BatchService;
use App\Services\ProductCatalogService;
use App\Services\StockService;
use App\Support\CurrentShop;
use App\Support\UnitCost;
use Tests\Feature\Pos\Concerns\WorksWithImports;

/**
 * Importing opening stock into the demo clinic (today 2026-10-18; short
 * expiry within 90 days): per item, location and batch, in base units or
 * packs, at the cost per unit given. Each row is an "opening" movement
 * through StockService into its batch; the same file again is skipped.
 */
class ImportOpeningStockTest extends BatchTestCase
{
    use WorksWithImports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImportStorage();

        $this->actingAs($this->owner);
    }

    private function panadol(): ProductVariant
    {
        $variant = $this->medicine('Panadol 500 mg Tablets', [
            ['name' => 'Box of 100', 'factor' => 100, 'price' => 45_000, 'barcode' => '4792024010029'],
        ]);
        $variant->update(['sku' => 'PAN-500', 'barcode' => '4792024010012']);

        return $variant;
    }

    public function test_opening_stock_goes_into_batches_per_location_in_packs_or_base_units(): void
    {
        $panadol = $this->panadol();

        $run = $this->import(ImportType::OpeningStock, $this->templateFile(ImportType::OpeningStock, [
            ['item' => 'PAN-500', 'location' => 'GEN', 'batch_no' => 'px2401', 'expiry' => '09/2028', 'quantity' => 12, 'unit' => 'Box of 100', 'unit_cost' => '380.00'],
            ['item' => '4792024010012', 'location' => 'DOC', 'batch_no' => 'PX2401', 'expiry' => '2028-09', 'quantity' => 150, 'unit' => 'Tablet', 'unit_cost' => '3.6'],
            // A pack's barcode finds the item and picks the pack.
            ['item' => '4792024010029', 'location' => 'gen', 'batch_no' => 'PX2502', 'expiry' => '31/03/2029', 'quantity' => 2, 'unit_cost' => '400'],
            // By name; no unit = the base unit; the same batch on two shelves adds up.
            ['item' => 'panadol 500 mg tablets', 'location' => 'GEN', 'batch_no' => 'PX2401', 'expiry' => '09/2028', 'quantity' => 30, 'unit_cost' => '3.80'],
        ]));

        $this->assertImported($run);
        $this->assertSame(4, $run->result_counts['created']);

        $gen = StockBatch::query()->where('product_variant_id', $panadol->getKey())->where('location_id', $this->general->getKey())->where('batch_no', 'PX2401')->sole();
        $this->assertSame('2028-09-30', $gen->expiry_date->toDateString());
        $this->assertSame('1230.000', $gen->quantity);                       // 12 boxes of 100 + 30 tablets
        $this->assertSame('200.000', StockBatch::query()->where('batch_no', 'PX2502')->value('quantity'));
        $this->assertSame('150.000', StockBatch::query()->where('location_id', $this->doctors->getKey())->value('quantity'));
        $this->assertSame(1430.0, $panadol->stockAt($this->general));
        $this->assertSame(150.0, $panadol->stockAt($this->doctors));

        // Cost per base unit = the unit cost ÷ the pack's contents; the location averages them.
        $movements = StockMovement::query()->where('product_variant_id', $panadol->getKey())->orderBy('id')->get();
        $this->assertSame([StockMovementType::Opening], $movements->pluck('type')->unique()->values()->all());
        // Written in lock order: General Pharmacy's rows first, then Doctor's Medicines.
        $this->assertSame(['380.0000', '400.0000', '380.0000', '360.0000'], $movements->pluck('received_cost')->all());
        $this->assertTrue($movements->every(fn (StockMovement $m): bool => $m->reference_type === (new ImportRun)->getMorphClass() && (int) $m->reference_id === $run->getKey()));
        $this->assertSame((int) $this->owner->getKey(), (int) $movements->first()->user_id);

        $general = StockLevel::query()->where('product_variant_id', $panadol->getKey())->where('location_id', $this->general->getKey())->sole();
        // (1200 × 3.80 + 200 × 4.00 + 30 × 3.80) ÷ 1430 = 3.8280 a tablet, in minor units.
        $this->assertTrue(UnitCost::equals('382.7972', $general->average_cost), $general->average_cost);

        $this->assertLedgerMatches();
    }

    public function test_short_expiry_is_a_warning_the_owner_accepts_and_expired_stock_an_error(): void
    {
        $panadol = $this->panadol();

        $preview = $this->preview(ImportType::OpeningStock, $this->templateFile(ImportType::OpeningStock, [
            ['item' => 'PAN-500', 'location' => 'GEN', 'batch_no' => 'SHORT', 'expiry' => '12/2026', 'quantity' => 10, 'unit_cost' => '4'],
            ['item' => 'PAN-500', 'location' => 'GEN', 'batch_no' => 'OLD', 'expiry' => '09/2026', 'quantity' => 10, 'unit_cost' => '4'],
            ['item' => 'PAN-500', 'location' => 'GEN', 'batch_no' => 'FAR', 'expiry' => '01/2040', 'quantity' => 10, 'unit_cost' => '4'],
            ['item' => 'PAN-500', 'location' => 'GEN', 'batch_no' => 'BAD', 'expiry' => 'soon', 'quantity' => 10, 'unit_cost' => '4'],
        ]));

        $this->assertSame(['Short expiry: batch SHORT expires on 31/12/2026, within 90 days.'], $this->issuesOf($preview, 2)['warnings']);
        $this->assertSame([], $this->issuesOf($preview, 2)['errors']);
        $this->assertStringContainsString("Batch OLD expired on 30/09/2026: expired stock can't be taken in", implode(' ', $this->issuesOf($preview, 3)['errors']));
        $this->assertStringContainsString('more than 10 years away', implode(' ', $this->issuesOf($preview, 4)['errors']));
        $this->assertStringContainsString('Expiry: "soon" is not the month or date on the pack', implode(' ', $this->issuesOf($preview, 5)['errors']));

        // The short-dated row alone imports: confirming is the owner's acceptance.
        $run = $this->import(ImportType::OpeningStock, $this->templateFile(ImportType::OpeningStock, [
            ['item' => 'PAN-500', 'location' => 'GEN', 'batch_no' => 'SHORT', 'expiry' => '12/2026', 'quantity' => 10, 'unit_cost' => '4'],
        ]));
        $this->assertImported($run);
        $this->assertSame(10.0, $panadol->stockAt($this->general));

        // Someone who may import but not accept short expiry gets an error instead.
        $importer = User::factory()->create(['email' => 'stores@clinic.test']);
        $importer->forceFill(['shop_id' => $this->shop->getKey()])->save();
        $importer->givePermissionTo('imports.manage');

        $preview = $this->preview(ImportType::OpeningStock, $this->templateFile(ImportType::OpeningStock, [
            ['item' => 'PAN-500', 'location' => 'GEN', 'batch_no' => 'SHORT2', 'expiry' => '12/2026', 'quantity' => 10, 'unit_cost' => '4'],
        ]), user: $importer);
        $this->assertStringContainsString('only someone who may accept short expiry', implode(' ', $this->issuesOf($preview, 2)['errors']));
    }

    public function test_an_excel_date_on_the_1st_is_read_as_the_month_with_a_warning_and_text_is_kept_as_typed(): void
    {
        $panadol = $this->panadol();
        $row = fn (string $batch, mixed $expiry): array => ['item' => 'PAN-500', 'location' => 'GEN', 'batch_no' => $batch, 'expiry' => $expiry, 'quantity' => 10, 'unit_cost' => '4'];

        $preview = $this->preview(ImportType::OpeningStock, $this->templateFile(ImportType::OpeningStock, [
            $row('CELL-1ST', new \DateTimeImmutable('2027-03-01')),   // "03/2027" typed into a date cell
            $row('CELL-15TH', new \DateTimeImmutable('2027-03-15')),  // a real date
            $row('TEXT-MONTH', '03/2027'),
            $row('TEXT-1ST', '01/03/2027'),
        ]));

        $this->assertSame(['rows' => 4, 'created' => 4, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'warnings' => 1, 'expiry_months' => 1], $preview->counts);
        $this->assertSame(['Expiry read as month 03/2027 → 31/03/2027 — confirm, or type the full date as text if the pack shows a day.'], $this->issuesOf($preview, 2)['warnings']);
        foreach ([3, 4, 5] as $number) {
            $this->assertSame([], $this->issuesOf($preview, $number)['warnings']);
        }

        $this->assertImported($this->imports()->confirm($preview->run, $this->owner)->refresh());

        $expiries = StockBatch::query()->where('product_variant_id', $panadol->getKey())->orderBy('id')->get()
            ->mapWithKeys(fn (StockBatch $batch): array => [$batch->batch_no => $batch->expiry_date->toDateString()])->all();
        $this->assertSame(['CELL-1ST' => '2027-03-31', 'CELL-15TH' => '2027-03-15', 'TEXT-MONTH' => '2027-03-31', 'TEXT-1ST' => '2027-03-01'], $expiries);
    }

    public function test_unknown_items_locations_and_units_and_whole_units_are_errors(): void
    {
        $this->panadol();
        $service = app(ProductCatalogService::class)->create(['name' => 'Consultation', 'track_stock' => false], [['sku' => 'CONSULT', 'price' => 150_000]], user: $this->owner);

        $preview = $this->preview(ImportType::OpeningStock, $this->templateFile(ImportType::OpeningStock, [
            ['item' => 'NOPE-1', 'location' => 'GEN', 'batch_no' => 'A', 'expiry' => '12/2028', 'quantity' => 1],
            ['item' => 'PAN-500', 'location' => 'WAREHOUSE', 'batch_no' => 'A', 'expiry' => '12/2028', 'quantity' => 1],
            ['item' => 'PAN-500', 'location' => 'GEN', 'batch_no' => 'A', 'expiry' => '12/2028', 'quantity' => 1, 'unit' => 'Strip'],
            ['item' => 'PAN-500', 'location' => 'GEN', 'batch_no' => 'A', 'expiry' => '12/2028', 'quantity' => 2.5],
            ['item' => 'PAN-500', 'location' => 'GEN', 'batch_no' => null, 'expiry' => null, 'quantity' => 5],
            ['item' => 'CONSULT', 'location' => 'GEN', 'quantity' => 1],
            ['item' => 'PAN-500', 'location' => null, 'batch_no' => 'A', 'expiry' => '12/2028', 'quantity' => 1],
        ]));

        $this->assertSame(7, $preview->counts['errors']);
        $this->assertStringContainsString('Unknown item "NOPE-1"', implode(' ', $this->issuesOf($preview, 2)['errors']));
        $this->assertStringContainsString('Unknown location "WAREHOUSE"', implode(' ', $this->issuesOf($preview, 3)['errors']));
        $this->assertStringContainsString('Unknown unit "Strip" for', implode(' ', $this->issuesOf($preview, 4)['errors']));
        $this->assertStringContainsString('whole units only: 2.5 Tablet is not allowed', implode(' ', $this->issuesOf($preview, 5)['errors']));
        $this->assertSame(['Batch no. is missing: '.'PAN-500 tracks batches.', 'Expiry is missing: PAN-500 tracks batches.'], $this->issuesOf($preview, 6)['errors']);
        $this->assertStringContainsString('is a service', implode(' ', $this->issuesOf($preview, 7)['errors']));
        $this->assertStringContainsString('Location is missing', implode(' ', $this->issuesOf($preview, 8)['errors']));
        $this->assertSame(1, $service->variants()->count());
    }

    public function test_a_batch_number_keeps_one_expiry(): void
    {
        $panadol = $this->panadol();
        $this->receive($panadol, 'PX1', '06/2028', 10);

        $preview = $this->preview(ImportType::OpeningStock, $this->templateFile(ImportType::OpeningStock, [
            ['item' => 'PAN-500', 'location' => 'DOC', 'batch_no' => 'PX1', 'expiry' => '07/2028', 'quantity' => 1, 'unit_cost' => 1],
            ['item' => 'PAN-500', 'location' => 'GEN', 'batch_no' => 'PX9', 'expiry' => '07/2028', 'quantity' => 1, 'unit_cost' => 1],
            ['item' => 'PAN-500', 'location' => 'DOC', 'batch_no' => 'PX9', 'expiry' => '08/2028', 'quantity' => 1, 'unit_cost' => 1],
        ]));

        $this->assertStringContainsString('Batch PX1 of PAN-500 is on file with expiry 30/06/2028', implode(' ', $this->issuesOf($preview, 2)['errors']));
        $this->assertSame([], $this->issuesOf($preview, 3)['errors']);
        $this->assertStringContainsString('Batch PX9 is on row 3 with another expiry', implode(' ', $this->issuesOf($preview, 4)['errors']));
    }

    public function test_the_same_file_again_is_skipped_and_new_rows_still_go_in(): void
    {
        $panadol = $this->panadol();
        $rows = [
            ['item' => 'PAN-500', 'location' => 'GEN', 'batch_no' => 'PX1', 'expiry' => '09/2028', 'quantity' => 100, 'unit_cost' => '3.80'],
            ['item' => 'PAN-500', 'location' => 'DOC', 'batch_no' => 'PX1', 'expiry' => '09/2028', 'quantity' => 20, 'unit_cost' => '3.80'],
        ];

        $this->assertImported($this->import(ImportType::OpeningStock, $this->templateFile(ImportType::OpeningStock, $rows)));

        $again = $this->preview(ImportType::OpeningStock, $this->templateFile(ImportType::OpeningStock, [
            ...$rows,
            ['item' => 'PAN-500', 'location' => 'GEN', 'batch_no' => 'PX2', 'expiry' => '10/2028', 'quantity' => 5, 'unit_cost' => '3.90'],
        ]));

        $this->assertSame(['rows' => 3, 'created' => 1, 'updated' => 0, 'skipped' => 2, 'errors' => 0, 'warnings' => 2, 'expiry_months' => 0], $again->counts);
        $this->assertSame(['PAN-500 already has opening stock in batch PX1 at GEN: skipped.'], $this->issuesOf($again, 2)['warnings']);

        $this->imports()->confirm($again->run, $this->owner);
        $this->assertSame(105.0, $panadol->stockAt($this->general));
        $this->assertSame(20.0, $panadol->stockAt($this->doctors));
        $this->assertSame(3, StockMovement::query()->where('type', StockMovementType::Opening->value)->count());
    }

    public function test_an_error_while_writing_rolls_the_whole_file_back(): void
    {
        $panadol = $this->panadol();
        $preview = $this->preview(ImportType::OpeningStock, $this->templateFile(ImportType::OpeningStock, [
            ['item' => 'PAN-500', 'location' => 'GEN', 'batch_no' => 'PX1', 'expiry' => '09/2028', 'quantity' => 100, 'unit_cost' => '3.80'],
            ['item' => 'PAN-500', 'location' => 'DOC', 'batch_no' => 'PX1', 'expiry' => '09/2028', 'quantity' => 20, 'unit_cost' => '3.80'],
        ]));
        $this->assertTrue($preview->canCommit());

        // Between the check and the write, the DOC location is closed: row 3 now fails, so nothing goes in.
        $this->doctors->update(['is_active' => false]);

        $run = $this->imports()->confirm($preview->run, $this->owner)->refresh();

        $this->assertSame(ImportStatus::Failed, $run->status);
        $this->assertStringContainsString("1 row(s) have errors now (row 3: Doctor's Medicines is not active.)", (string) $run->message);
        $this->assertStringContainsString('Nothing was imported.', (string) $run->message);
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(0, StockBatch::query()->count());
        $this->assertSame(0.0, $panadol->stockAt($this->general));

        // Put right, the same upload is checked and confirmed again.
        $this->doctors->update(['is_active' => true]);
        $again = $this->imports()->preview($run, (array) $run->mapping, ImportExisting::Skip, $this->owner);
        $this->assertTrue($again->canCommit());
        $this->assertImported($this->imports()->confirm($run, $this->owner)->refresh());
        $this->assertSame(120.0, $panadol->stockAt($this->general) + $panadol->stockAt($this->doctors));
    }

    public function test_a_service_refusing_a_row_rolls_back_the_rows_before_it(): void
    {
        $panadol = $this->panadol();
        $preview = $this->preview(ImportType::OpeningStock, $this->templateFile(ImportType::OpeningStock, [
            ['item' => 'PAN-500', 'location' => 'GEN', 'batch_no' => 'PX1', 'expiry' => '09/2028', 'quantity' => 100, 'unit_cost' => '3.80'],
            ['item' => 'PAN-500', 'location' => 'DOC', 'batch_no' => 'PX1', 'expiry' => '09/2028', 'quantity' => 20, 'unit_cost' => '3.80'],
        ]));

        // A refusal the check can't see (StockService throws on the second row).
        $this->app->extend(StockService::class, function (StockService $stock) {
            return new class($stock) extends StockService
            {
                private int $calls = 0;

                public function __construct(private readonly StockService $inner)
                {
                    parent::__construct(app(CurrentShop::class), app(BatchService::class));
                }

                public function receive(...$arguments): StockMovement
                {
                    if (++$this->calls === 2) {
                        throw new PosException('The stock level is locked.');
                    }

                    return $this->inner->receive(...$arguments);
                }
            };
        });

        $run = $this->imports()->confirm($preview->run, $this->owner)->refresh();

        $this->assertSame(ImportStatus::Failed, $run->status);
        $this->assertSame('Row 3: The stock level is locked. Nothing was imported.', $run->message);
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(0.0, $panadol->stockAt($this->general));
    }
}
