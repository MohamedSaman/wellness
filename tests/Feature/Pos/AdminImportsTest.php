<?php

namespace Tests\Feature\Pos;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Filament\Resources\ImportRuns\Pages\ImportFile;
use App\Filament\Resources\ImportRuns\Pages\ListImportRuns;
use App\Models\ImportRun;
use App\Models\Shop;
use App\Models\Supplier;
use App\Services\ShopSetupService;
use App\Support\CurrentShop;
use App\Support\InstallProfile;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Feature\Pos\Concerns\WorksWithImports;

/**
 * The import screens (Administration -> Imports): the log, template
 * downloads, and importing a file step by step (upload, match the
 * columns, check, confirm), owner only.
 */
class AdminImportsTest extends BatchTestCase
{
    use WorksWithImports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImportStorage();

        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);
    }

    private function suppliersFile(): string
    {
        return $this->templateFile(ImportType::Suppliers, [
            ['name' => 'Hemas Pharmaceuticals', 'payment_terms_days' => 30],
            ['name' => 'State Pharmaceuticals', 'email' => 'not-an-email'],
        ]);
    }

    public function test_importing_a_file_step_by_step(): void
    {
        $file = UploadedFile::fake()->createWithContent('suppliers.xlsx', (string) file_get_contents($this->templateFile(ImportType::Suppliers, [
            ['name' => 'Hemas Pharmaceuticals', 'payment_terms_days' => 30],
            ['name' => 'State Pharmaceuticals', 'payment_terms_days' => 45],
        ])));

        $page = Livewire::test(ImportFile::class)
            ->assertSee('1. Choose the file')
            ->fillForm(['type' => 'suppliers', 'file' => $file], 'uploadForm')
            ->call('uploadFile')
            ->assertHasNoErrors();

        $run = ImportRun::query()->sole();
        $this->assertSame(ImportStatus::Uploaded, $run->status);
        $this->assertSame('suppliers.xlsx', $run->file_name);
        $page->assertSet('run', $run->uuid)
            ->assertSee('2. Match the columns')
            ->assertSee('Supplier name')
            ->assertSee('A · Supplier name');

        $page->call('check')
            ->assertSee('Ready to import')
            ->assertSee('To create: 2');
        $this->assertSame(0, Supplier::query()->count()); // the check writes nothing

        $page->call('confirmImport')->assertNotified();

        $this->assertSame(ImportStatus::Completed, $run->refresh()->status);
        $this->assertSame(['Hemas Pharmaceuticals', 'State Pharmaceuticals'], Supplier::query()->orderBy('name')->pluck('name')->all());

        // Opened again from the log: the result.
        Livewire::withQueryParams(['run' => $run->uuid])->test(ImportFile::class)
            ->assertSee('Imported')
            ->assertSee('Created: 2');
    }

    public function test_the_check_counts_expiries_read_as_a_month(): void
    {
        $this->medicine()->update(['sku' => 'PAN-500']);
        $path = $this->templateFile(ImportType::OpeningStock, [
            ['item' => 'PAN-500', 'location' => 'GEN', 'batch_no' => 'A', 'expiry' => new \DateTimeImmutable('2027-03-01'), 'quantity' => 10, 'unit_cost' => '4'],
            ['item' => 'PAN-500', 'location' => 'GEN', 'batch_no' => 'B', 'expiry' => new \DateTimeImmutable('2027-06-01'), 'quantity' => 10, 'unit_cost' => '4'],
            ['item' => 'PAN-500', 'location' => 'GEN', 'batch_no' => 'C', 'expiry' => '09/2027', 'quantity' => 10, 'unit_cost' => '4'],
        ]);
        $run = $this->imports()->upload(ImportType::OpeningStock, $path, 'stock.xlsx', $this->owner);

        Livewire::withQueryParams(['run' => $run->uuid])->test(ImportFile::class)
            ->call('check')
            ->assertSee('Expiry read as month: 2')
            ->assertSee('2 rows have expiries that Excel stored as a date on the 1st of a month')
            ->assertSee('Expiry read as month 06/2027 → 30/06/2027 — confirm, or type the full date as text if the pack shows a day.');

        $this->assertSame(2, $run->refresh()->preview_counts['expiry_months']);
    }

    public function test_a_file_with_errors_lists_them_and_cannot_be_confirmed(): void
    {
        $run = $this->imports()->upload(ImportType::Suppliers, $this->suppliersFile(), 'suppliers.xlsx', $this->owner);

        Livewire::withQueryParams(['run' => $run->uuid])->test(ImportFile::class)
            ->call('check')
            ->assertSee('With errors: 1')
            ->assertSee('Email: "not-an-email" is not an email address.')
            ->assertSee('Download the error report')
            ->call('confirmImport')
            ->assertNotified('Some rows have errors. Correct them in the file (the error report lists them) and upload it again: a file is imported whole or not at all.');

        $this->assertSame(ImportStatus::Previewed, $run->refresh()->status);
        $this->assertSame(0, Supplier::query()->count());
    }

    public function test_columns_can_be_matched_by_hand(): void
    {
        $path = $this->spreadsheet(['Vendor name', 'Credit period', 'Remarks'], [['Hemas', 30, 'x']]);
        $run = $this->imports()->upload(ImportType::Suppliers, $path, 'vendors.xlsx', $this->owner);
        $this->assertSameIgnoringKeyOrder(['name' => 0, 'payment_terms_days' => 1, 'notes' => 2], $run->mapping);

        Livewire::withQueryParams(['run' => $run->uuid])->test(ImportFile::class)
            ->assertSet('columns.map.name', 0)
            ->set('columns.map.notes', null)
            ->set('columns.map.address', 2)
            ->call('check')
            ->assertSee('To create: 1');

        $this->assertSameIgnoringKeyOrder(['name' => 0, 'address' => 2, 'payment_terms_days' => 1], $run->refresh()->mapping);
    }

    public function test_the_error_report_has_the_original_rows_and_an_error_column(): void
    {
        $run = $this->preview(ImportType::Suppliers, $this->suppliersFile())->run;

        $path = tempnam(sys_get_temp_dir(), 'pos-report-test-').'.xlsx';
        $this->importFiles[] = $path;
        file_put_contents($path, $this->get(route('imports.report', $run))->assertOk()->streamedContent());

        $reader = new Reader;
        $reader->open($path);
        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }

            break;
        }

        $reader->close();

        $headings = $rows[0];
        $this->assertSame('Row', $headings[0]);
        $this->assertSame('Supplier name', $headings[1]);
        $this->assertSame(['Errors', 'Warnings'], array_slice($headings, -2));
        $this->assertCount(2, $rows); // only the row with a problem
        $this->assertSame(3, $rows[1][0]);
        $this->assertSame('State Pharmaceuticals', $rows[1][1]);
        $this->assertSame('Email: "not-an-email" is not an email address.', $rows[1][count($headings) - 2]);
    }

    public function test_templates_download_for_each_type_with_this_shops_locations(): void
    {
        foreach (array_keys(ImportType::options($this->shop)) as $type) {
            $response = $this->get(route('imports.template', $type))->assertOk();
            $this->assertStringContainsString('.xlsx', (string) $response->headers->get('content-disposition'));
        }

        $path = $this->get(route('imports.template', 'items'))->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive;
        $zip->open($path);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        $zip->close();

        $this->assertStringContainsString('<sheet name="Medicines"', $workbook);
        $this->assertStringContainsString('<sheet name="Instructions"', $workbook);
        $this->assertStringContainsString('<sheet name="Lists" sheetId="3" r:id="rIdSheet3" state="hidden"/>', $workbook);
        $this->assertStringContainsString('Sell loose at GEN', $sheet);
        $this->assertStringContainsString('<dataValidations count=', $sheet);
        $this->assertStringContainsString('promptTitle="Dosage form"', $sheet);

        $this->get(route('imports.template', 'nonsense'))->assertNotFound();
    }

    public function test_a_retail_shop_gets_the_retail_items_template(): void
    {
        $retail = Shop::query()->create(['name' => 'Moto', 'code' => 'MOTO', 'business_type' => 'retail', 'currency' => 'LKR', 'timezone' => 'Asia/Colombo']);
        app(ShopSetupService::class)->provision($retail, InstallProfile::named('retail'));

        app(CurrentShop::class)->runAs($retail, function () use ($retail): void {
            $labels = array_map(fn ($field) => $field->label, $this->imports()->definition(ImportType::Items)->fields($retail));

            $this->assertContains('Brand', $labels);
            $this->assertContains('Unit', $labels);
            $this->assertContains('Price', $labels);
            $this->assertContains('Reorder level', $labels);
            $this->assertNotContains('Generic name(s)', $labels);
            $this->assertNotContains('Dosage form', $labels);
            $this->assertSame('Items', $this->imports()->definition(ImportType::Items)->sheetName($retail));
            $this->assertArrayNotHasKey('suppliers', ImportType::options($retail));
        });
    }

    public function test_another_shops_import_is_not_found(): void
    {
        $other = Shop::query()->create(['name' => 'Moto', 'code' => 'MOTO', 'business_type' => 'retail', 'currency' => 'LKR', 'timezone' => 'Asia/Colombo']);
        app(ShopSetupService::class)->provision($other, InstallProfile::named('retail'));

        $theirs = app(CurrentShop::class)->runAs($other, fn (): ImportRun => ImportRun::query()->create([
            'user_id' => $this->owner->getKey(), 'type' => ImportType::Items, 'file_name' => 'theirs.xlsx',
            'file_path' => 'imports/x/theirs.xlsx', 'file_hash' => str_repeat('0', 64), 'error_report_path' => 'imports/x/report.xlsx',
        ]));

        $this->get(route('imports.file', $theirs->uuid))->assertNotFound();
        $this->get(route('imports.report', $theirs->uuid))->assertNotFound();
        $this->get(ImportFile::getUrl(['run' => $theirs->uuid]))->assertNotFound();
    }

    public function test_a_csv_saved_by_older_excel_in_windows_1252_reads_correctly(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pos-import-test-').'.csv';
        $this->importFiles[] = $path;
        file_put_contents($path, mb_convert_encoding("Supplier name;Notes\nNestlé Lanka;Café order\n", 'Windows-1252', 'UTF-8'));

        $this->assertImported($this->import(ImportType::Suppliers, $path));
        $this->assertSame(['name' => 'Nestlé Lanka', 'notes' => 'Café order'], Supplier::query()->sole()->only(['name', 'notes']));
    }

    public function test_the_log_lists_imports_and_only_the_owner_sees_it(): void
    {
        $run = $this->preview(ImportType::Suppliers, $this->suppliersFile(), name: 'my-suppliers.xlsx')->run;

        Livewire::test(ListImportRuns::class)
            ->assertCanSeeTableRecords([$run])
            ->assertSee('my-suppliers.xlsx')
            ->assertSee('Checked, not imported')
            ->assertSee('1 created, 1 with errors');

        $this->get(ImportRunResource::getUrl())->assertOk();
        $this->get(route('imports.file', $run))->assertOk()->assertDownload('my-suppliers.xlsx');

        $this->actingAs($this->cashier);
        $this->get(ImportRunResource::getUrl())->assertForbidden();
        $this->get(ImportFile::getUrl())->assertForbidden();
        $this->get(route('imports.template', 'items'))->assertForbidden();
        $this->get(route('imports.report', $run))->assertForbidden();
    }
}
