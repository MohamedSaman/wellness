<?php

namespace Tests\Feature\Pos;

use App\Enums\ImportExisting;
use App\Enums\ImportType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierPayable;
use App\Services\SupplierService;
use Tests\Feature\Pos\Concerns\WorksWithImports;

/**
 * The sample files in database/seeders/files (filled-in templates for the
 * demo clinic, and a price list in the clinic's own layout) load cleanly,
 * in the order the import page suggests: suppliers, medicines, opening
 * stock, opening invoices. Today is 2026-10-18.
 */
class ImportSampleFilesTest extends BatchTestCase
{
    use WorksWithImports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImportStorage();
    }

    private function sample(string $name): string
    {
        return database_path("seeders/files/{$name}");
    }

    public function test_the_sample_files_import_in_order(): void
    {
        $this->actingAs($this->owner);

        $suppliers = $this->import(ImportType::Suppliers, $this->sample('clinic-suppliers.xlsx'));
        $this->assertImported($suppliers);
        $this->assertSame(6, Supplier::query()->count());

        $medicines = $this->preview(ImportType::Items, $this->sample('clinic-medicines.xlsx'));
        $this->assertSame(['rows' => 26, 'created' => 26, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'warnings' => 1, 'expiry_months' => 0], $medicines->counts);
        $this->assertStringContainsString('No loose price', implode(' ', $this->issuesOf($medicines, 15)['warnings']));
        $this->assertImported($this->imports()->confirm($medicines->run, $this->owner)->refresh());

        $stock = $this->preview(ImportType::OpeningStock, $this->sample('clinic-opening-stock.xlsx'));
        $this->assertSame(['rows' => 29, 'created' => 29, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'warnings' => 1, 'expiry_months' => 0], $stock->counts);
        $this->assertSame(['Short expiry: batch OM8820 expires on 31/12/2026, within 90 days.'], $this->issuesOf($stock, 12)['warnings']);
        $this->assertImported($this->imports()->confirm($stock->run, $this->owner)->refresh());
        $this->assertSame(29, StockMovement::query()->count());
        $this->assertLedgerMatches();

        $invoices = $this->preview(ImportType::OpeningInvoices, $this->sample('clinic-opening-invoices.xlsx'));
        $this->assertSame(0, $invoices->counts['errors']);
        $this->assertSame(6, $invoices->counts['created']);
        $this->assertImported($this->imports()->confirm($invoices->run, $this->owner)->refresh());
        $this->assertSame(6, SupplierPayable::query()->count());

        foreach (Supplier::query()->get() as $supplier) {
            $this->assertSame(0, app(SupplierService::class)->reconcile($supplier)['difference']);
        }
    }

    public function test_the_price_list_in_the_clinics_own_layout_is_matched_and_imports(): void
    {
        $this->actingAs($this->owner);

        $run = $this->imports()->upload(ImportType::Items, $this->sample('clinic-price-list.csv'), 'clinic-price-list.csv', $this->owner);
        $this->assertSame(
            ['sku', 'name', 'generic_names', 'strength', 'dosage_form', 'schedule', 'price', 'pack1_name', 'pack1_factor', 'pack1_price'],
            array_keys(collect($run->mapping)->sort()->all()),
        );

        $preview = $this->imports()->preview($run, (array) $run->mapping, ImportExisting::Skip, $this->owner);
        $this->assertSame(0, $preview->counts['errors'], json_encode($preview->issues));
        $this->assertSame(5, $preview->counts['created']);
        $this->assertImported($this->imports()->confirm($run, $this->owner)->refresh());

        $fluconazole = Product::query()->with('drugDetail')->where('name', 'Fluconazole 150 mg Capsules')->sole();
        $this->assertSame('150 mg', $fluconazole->drugDetail->strength());
        $this->assertSame('capsule', $fluconazole->drugDetail->dosage_form->value);
        $this->assertSame('5 mg/5 ml', Product::query()->with('drugDetail')->where('name', 'like', 'Loratadine%')->sole()->drugDetail->strength());
    }
}
