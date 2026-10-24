<?php

namespace Tests\Feature\Pos;

use App\Enums\DosageForm;
use App\Enums\DrugSchedule;
use App\Enums\ImportExisting;
use App\Enums\ImportType;
use App\Enums\StorageCondition;
use App\Enums\TaxCategory;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\VariantUnit;
use App\Models\VariantUnitLocation;
use App\Services\Data\BatchInput;
use App\Services\Data\DrugData;
use App\Services\ProductCatalogService;
use App\Services\StockService;
use Tests\Feature\Pos\Concerns\WorksWithImports;

/**
 * Importing medicines and products into the demo clinic (docs/PHARMACY.md
 * §10): drug details, base unit and packs with their own prices and
 * barcodes, selling loose and reorder levels per location, the dosage
 * form's defaults, matching what is already on file (skip or update, never
 * twice), and the dry run's errors and warnings.
 */
class ImportItemsTest extends BatchTestCase
{
    use WorksWithImports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeImportStorage();

        $this->actingAs($this->owner);
    }

    /** @return array<string, mixed> a filled-in medicines row */
    private function panadol(array $overrides = []): array
    {
        return [
            'sku' => 'PAN-500',
            'name' => 'Panadol 500 mg Tablets',
            'medicine' => 'Yes',
            'generic_names' => 'Paracetamol',
            'brand_name' => 'Panadol',
            'manufacturer' => 'GSK',
            'strength' => 500,
            'strength_unit' => 'mg',
            'dosage_form' => 'Tablet',
            'schedule' => 'OTC',
            'storage_condition' => 'Room',
            'registration_no' => 'N/1234',
            'barcode' => '4792024010012',
            'price' => '5.00',
            'pack1_name' => 'Box of 100',
            'pack1_factor' => 100,
            'pack1_price' => '450',
            'pack1_barcode' => '4792024010029',
            'sell_loose:GEN' => 'Yes',
            'sell_loose:DOC' => 'Yes',
            'reorder:GEN' => 300,
            'reorder:DOC' => 50,
            'category' => 'Pain relief',
            ...$overrides,
        ];
    }

    /** @return array<string, mixed> */
    private function syrup(array $overrides = []): array
    {
        return [
            'sku' => 'PIR-SYR',
            'name' => 'Piriton Syrup 2 mg/5 ml',
            'generic_names' => 'Chlorphenamine',
            'strength' => 2,
            'strength_unit' => 'mg/5 ml',
            'dosage_form' => 'Syrup',
            'schedule' => 'otc',
            'price' => '4',
            'pack1_name' => 'Bottle',
            'pack1_factor' => 100,
            'pack1_price' => '380.00',
            'sell_loose:GEN' => 'No',
            'sell_loose:DOC' => 'Yes',
            ...$overrides,
        ];
    }

    private function variantOf(string $sku): ProductVariant
    {
        return ProductVariant::query()->with(['product.drugDetail', 'product.generics', 'units.prices', 'units.locationRules'])->where('sku', $sku)->firstOrFail();
    }

    public function test_medicines_import_with_drug_details_packs_and_per_location_settings(): void
    {
        $run = $this->import(ImportType::Items, $this->templateFile(ImportType::Items, [
            $this->panadol(),
            $this->syrup(),
            ['name' => 'Augmentin 625 mg Tablets', 'generic_names' => 'Amoxicillin + Clavulanic acid', 'strength' => 625, 'strength_unit' => 'mg', 'dosage_form' => 'tab', 'schedule' => 'Prescription only', 'tax_category' => 'Standard', 'price' => 95, 'pack1_name' => 'Box of 16', 'pack1_factor' => 16, 'pack1_price' => 1450, 'storage_condition' => 'Fridge'],
            ['name' => 'Cotton wool 50 g', 'medicine' => 'No', 'base_unit' => 'Pack', 'price' => 120, 'barcode' => '4790000099999'],
        ]));

        $this->assertImported($run);
        // A JSON column: MySQL keeps object keys in its own order.
        $this->assertSameIgnoringKeyOrder(['rows' => 4, 'created' => 4, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'warnings' => 0, 'expiry_months' => 0], $run->result_counts);

        $panadol = $this->variantOf('PAN-500');
        $product = $panadol->product;
        $this->assertSame('Tablet', $product->unit);
        $this->assertTrue($product->whole_units_only);
        $this->assertTrue($product->track_batches);
        $this->assertSame(TaxCategory::Exempt, $product->tax_category);
        $this->assertSame(['Paracetamol'], $product->generics->pluck('name')->all());
        $this->assertSame('Panadol', $product->drugDetail->brand_name);
        $this->assertSame(DosageForm::Tablet, $product->drugDetail->dosage_form);
        $this->assertSame(DrugSchedule::Otc, $product->drugDetail->schedule);
        $this->assertSame(StorageCondition::Room, $product->drugDetail->storage_condition);
        $this->assertSame('N/1234', $product->drugDetail->registration_no);
        $this->assertSame('500 mg', $product->drugDetail->strength());
        $this->assertSame('GSK', Brand::query()->findOrFail($product->brand_id)->name);
        $this->assertSame('Pain relief', Category::query()->findOrFail($product->category_id)->name);
        $this->assertSame('4792024010012', $panadol->barcode);

        $priceList = $this->shop->defaultPriceList();
        $base = $panadol->units->firstWhere('is_base', true);
        $box = $panadol->units->firstWhere('name', 'Box of 100');
        $this->assertSame(500, $base->priceOn($priceList));
        $this->assertSame('100.000', $box->factor);
        $this->assertSame(45_000, $box->priceOn($priceList));
        $this->assertSame('4792024010029', $box->barcode);
        $this->assertTrue($box->is_sellable && $box->is_purchasable);

        $this->assertSame(['300.000', '50.000'], [
            StockLevel::query()->where('product_variant_id', $panadol->getKey())->where('location_id', $this->general->getKey())->value('reorder_level'),
            StockLevel::query()->where('product_variant_id', $panadol->getKey())->where('location_id', $this->doctors->getKey())->value('reorder_level'),
        ]);

        // Syrup: ml with a bottle; loose ml at Doctor's Medicines only.
        $syrup = $this->variantOf('PIR-SYR');
        $ml = $syrup->units->firstWhere('is_base', true);
        $bottle = $syrup->units->firstWhere('name', 'Bottle');
        $this->assertSame('ml', $syrup->product->unit);
        $this->assertFalse($ml->is_sellable); // the dosage form's default
        $this->assertFalse($syrup->canSellUnitAt($ml, $this->general));
        $this->assertTrue($syrup->canSellUnitAt($ml, $this->doctors));
        $this->assertTrue($syrup->canSellUnitAt($bottle, $this->general));
        $this->assertSame(2, VariantUnitLocation::query()->where('variant_unit_id', $ml->getKey())->count());

        // Combination drug, schedule by label, form by abbreviation, generated SKU and in-store barcode.
        $augmentin = Product::query()->with(['generics', 'drugDetail', 'variants'])->where('name', 'Augmentin 625 mg Tablets')->firstOrFail();
        $this->assertSame(['Amoxicillin', 'Clavulanic acid'], $augmentin->generics->pluck('name')->all());
        $this->assertSame(DrugSchedule::PrescriptionOnly, $augmentin->drugDetail->schedule);
        $this->assertSame(TaxCategory::Standard, $augmentin->tax_category);
        $this->assertSame('AUGMENTIN-625-MG-TABLETS', $augmentin->variants->first()->sku);
        $this->assertStringStartsWith('200', (string) $augmentin->variants->first()->barcode);

        // Not a medicine: no drug details, no batches, standard tax.
        $cotton = Product::query()->where('name', 'Cotton wool 50 g')->firstOrFail();
        $this->assertNull($cotton->drugDetail);
        $this->assertFalse($cotton->track_batches);
        $this->assertSame(TaxCategory::Standard, $cotton->tax_category);
        $this->assertSame('Pack', $cotton->unit);

        // Audited, and the audit names the importer.
        $this->assertTrue(AuditLog::query()->where('auditable_type', $product->getMorphClass())->where('auditable_id', $product->getKey())->where('event', 'created')->where('user_id', $this->owner->getKey())->exists());
    }

    public function test_the_dry_run_writes_nothing(): void
    {
        $before = [Product::query()->count(), VariantUnit::query()->count(), Category::query()->count(), Brand::query()->count(), AuditLog::query()->count()];

        $preview = $this->preview(ImportType::Items, $this->templateFile(ImportType::Items, [$this->panadol(), $this->syrup()]));

        $this->assertSame(2, $preview->counts['created']);
        $this->assertTrue($preview->canCommit());
        $this->assertSame($before, [Product::query()->count(), VariantUnit::query()->count(), Category::query()->count(), Brand::query()->count(), AuditLog::query()->count()]);
    }

    public function test_row_errors_are_listed_and_block_the_import(): void
    {
        ProductVariant::query()->firstOr(fn () => $this->medicine('Existing 1 mg Tablets'));
        $taken = $this->medicine('Existing 2 mg Tablets');
        $taken->update(['barcode' => '4790000011111']);

        $preview = $this->preview(ImportType::Items, $this->templateFile(ImportType::Items, [
            $this->panadol(['generic_names' => null, 'dosage_form' => 'Lozenge', 'schedule' => null]),        // row 2
            $this->panadol(['sku' => 'B', 'barcode' => null, 'pack1_barcode' => '4790000011111', 'name' => 'B']), // row 3: barcode on file
            $this->panadol(['sku' => 'C', 'name' => 'C', 'barcode' => '111', 'pack1_barcode' => '111']),       // row 4: same barcode twice
            $this->panadol(['sku' => 'D', 'name' => 'D', 'barcode' => null, 'pack1_barcode' => null, 'pack1_factor' => 2.5, 'price' => 'abc']), // row 5
            $this->panadol(['sku' => 'E', 'name' => 'E', 'barcode' => '222', 'pack1_barcode' => null, 'strength_unit' => null, 'sell_loose:GEN' => 'maybe']), // row 6
            $this->panadol(['sku' => 'F', 'name' => 'F', 'barcode' => '333', 'pack1_barcode' => '444', 'pack1_name' => 'Tablet']), // row 7
            $this->panadol(['sku' => 'G', 'name' => 'G', 'barcode' => '555', 'pack1_barcode' => '4792024010029']), // row 8: dup of row 2's pack barcode
        ]));

        $this->assertSame(7, $preview->counts['errors']);
        $this->assertFalse($preview->canCommit());

        $this->assertContains('Generic name(s) is missing.', $this->issuesOf($preview, 2)['errors']);
        $this->assertContains('Schedule is missing.', $this->issuesOf($preview, 2)['errors']);
        $this->assertStringContainsString('Dosage form: "Lozenge" is not', implode(' ', $this->issuesOf($preview, 2)['errors']));
        $this->assertStringContainsString('Duplicate barcode 4790000011111: it is already on', implode(' ', $this->issuesOf($preview, 3)['errors']));
        $this->assertStringContainsString('Duplicate barcode 111: used twice on this row', implode(' ', $this->issuesOf($preview, 4)['errors']));
        $this->assertStringContainsString('must be a whole number', implode(' ', $this->issuesOf($preview, 5)['errors']));
        $this->assertStringContainsString('Loose price: "abc" is not a number', implode(' ', $this->issuesOf($preview, 5)['errors']));
        $this->assertStringContainsString('Strength unit is missing', implode(' ', $this->issuesOf($preview, 6)['errors']));
        $this->assertStringContainsString('Sell loose at GEN: "maybe" is not Yes or No', implode(' ', $this->issuesOf($preview, 6)['errors']));
        $this->assertStringContainsString('Two units are called Tablet', implode(' ', $this->issuesOf($preview, 7)['errors']));
        $this->assertStringContainsString('Duplicate barcode 4792024010029: also on row 2', implode(' ', $this->issuesOf($preview, 8)['errors']));

        $this->expectExceptionMessage('Some rows have errors');
        $this->imports()->confirm($preview->run, $this->owner);
    }

    public function test_warnings_for_missing_prices_and_possible_duplicates(): void
    {
        $this->medicine('Panadol 500 mg Tablets'); // Paracetamol, tablet, no strength on file
        $existing = app(ProductCatalogService::class)->create(
            ['name' => 'Calpol 500 mg Tablets', 'unit' => 'Tablet'],
            [['price' => 500, 'sku' => 'CALPOL']],
            user: $this->owner,
            drug: new DrugData(['Paracetamol'], DosageForm::Tablet, DrugSchedule::Otc, strengthValue: 500, strengthUnit: 'mg'),
        );

        $preview = $this->preview(ImportType::Items, $this->templateFile(ImportType::Items, [
            $this->panadol(['sku' => 'NEW-PCM', 'barcode' => null, 'pack1_barcode' => null, 'price' => null, 'pack1_price' => null]),
            ['sku' => 'X1', 'name' => 'Paracetamol 500mg (generic)', 'generic_names' => 'paracetamol', 'strength' => '500', 'strength_unit' => 'MG', 'dosage_form' => 'Tablet', 'schedule' => 'OTC', 'price' => 3],
        ]));

        $this->assertSame(0, $preview->counts['errors']);
        $this->assertSame(2, $preview->counts['created']);

        $first = implode(' | ', $this->issuesOf($preview, 2)['warnings']);
        $this->assertStringContainsString('No loose price: one Tablet is not for sale', $first);
        $this->assertStringContainsString('No price for Box of 100', $first);
        $this->assertStringContainsString('Possible duplicate of CALPOL Calpol 500 mg Tablets (same generic, strength and dosage form)', $first);
        $this->assertStringContainsString('An item called Panadol 500 mg Tablets is already on file', $first);
        $this->assertStringContainsString('Possible duplicate of CALPOL', implode(' ', $this->issuesOf($preview, 3)['warnings']));

        // Warnings don't stop it: imported, the unpriced units simply have no price.
        $this->imports()->confirm($preview->run, $this->owner);
        $variant = $this->variantOf('NEW-PCM');
        $this->assertNull($variant->units->firstWhere('is_base', true)->priceOn($this->shop->defaultPriceList()));
        $this->assertNull($variant->units->firstWhere('name', 'Box of 100')->priceOn($this->shop->defaultPriceList()));
        $this->assertSame(1, $existing->variants()->count());
    }

    public function test_running_the_same_file_again_creates_nothing_twice(): void
    {
        $file = $this->templateFile(ImportType::Items, [
            $this->panadol(),
            $this->syrup(),
            ['name' => 'Gauze swabs', 'medicine' => 'No', 'price' => 50], // no SKU, no barcode: matched by name
        ]);

        $this->assertImported($this->import(ImportType::Items, $file));
        $counts = [Product::query()->count(), VariantUnit::query()->count(), VariantUnitLocation::query()->count()];

        $again = $this->preview(ImportType::Items, $file);
        $this->assertSame(['rows' => 3, 'created' => 0, 'updated' => 0, 'skipped' => 3, 'errors' => 0, 'warnings' => 3, 'expiry_months' => 0], $again->counts);
        $this->assertStringContainsString('Already on file (PAN-500, matched by SKU PAN-500): skipped.', implode(' ', $this->issuesOf($again, 2)['warnings']));
        $this->assertStringContainsString('matched by name', implode(' ', $this->issuesOf($again, 4)['warnings']));
        $this->assertNotNull($again->importedBefore);
        $this->assertFalse($again->canCommit());

        // Updating from the same file changes nothing either.
        $updated = $this->import(ImportType::Items, $file, ImportExisting::Update);
        $this->assertImported($updated);
        $this->assertSame(3, $updated->result_counts['updated']);
        $this->assertSame($counts, [Product::query()->count(), VariantUnit::query()->count(), VariantUnitLocation::query()->count()]);
    }

    public function test_update_changes_prices_packs_and_details_but_never_the_base_unit_of_an_item_with_stock(): void
    {
        $this->assertImported($this->import(ImportType::Items, $this->templateFile(ImportType::Items, [$this->panadol(), $this->syrup()])));
        $panadol = $this->variantOf('PAN-500');
        app(StockService::class)->opening($panadol, $this->general, 100, $this->owner, 300, new BatchInput('B1', '12/2028'));

        // Matched by barcode this time, with a new price, a second pack and a changed schedule.
        $run = $this->import(ImportType::Items, $this->templateFile(ImportType::Items, [[
            'name' => 'Panadol 500 mg Tablets',
            'barcode' => '4792024010012',
            'schedule' => 'Prescription only',
            'price' => '6.00',
            'pack1_name' => 'Box of 100',
            'pack1_price' => '500',
            'pack2_name' => 'Box of 30',
            'pack2_factor' => 30,
            'pack2_price' => '170',
            'sell_loose:DOC' => 'No',
        ]]), ImportExisting::Update);

        $this->assertImported($run);
        $panadol = $this->variantOf('PAN-500');
        $priceList = $this->shop->defaultPriceList();
        $this->assertSame(600, $panadol->units->firstWhere('is_base', true)->priceOn($priceList));
        $this->assertSame(50_000, $panadol->units->firstWhere('name', 'Box of 100')->priceOn($priceList));
        $this->assertSame('100.000', $panadol->units->firstWhere('name', 'Box of 100')->factor);
        $this->assertSame(17_000, $panadol->units->firstWhere('name', 'Box of 30')->priceOn($priceList));
        $this->assertSame(DrugSchedule::PrescriptionOnly, $panadol->product->drugDetail->schedule);
        $this->assertSame('Panadol', $panadol->product->drugDetail->brand_name);      // blank cells keep what is on file
        $this->assertSame(['Paracetamol'], $panadol->product->generics->pluck('name')->all());
        $this->assertSame('GSK', $panadol->product->brand?->name);
        $this->assertFalse($panadol->canSellUnitAt($panadol->units->firstWhere('is_base', true), $this->doctors));

        // The base unit can't change once there is stock.
        $preview = $this->preview(ImportType::Items, $this->templateFile(ImportType::Items, [$this->panadol(['base_unit' => 'Strip'])]), existing: ImportExisting::Update);
        $this->assertSame(1, $preview->counts['errors']);
        $this->assertStringContainsString("The base unit can't change from Tablet to Strip", implode(' ', $this->issuesOf($preview, 2)['errors']));
    }

    public function test_a_csv_with_its_own_headings_is_matched_and_the_mapping_is_remembered(): void
    {
        $path = $this->spreadsheet(
            ['Item Code', 'Description', 'Generic', 'Form', 'Legal category', 'MRP', 'Pack size', 'Pack', 'Box price'],
            [['A-1', 'Losartan 50 mg Tablets', 'Losartan', 'Tablet', 'Rx', '12.50', 30, 'Box of 30', '360']],
            'csv',
        );

        $run = $this->imports()->upload(ImportType::Items, $path, 'clinic-list.csv', $this->owner);
        $this->assertSame([
            'sku' => 0, 'name' => 1, 'generic_names' => 2, 'dosage_form' => 3, 'schedule' => 4, 'price' => 5, 'pack1_factor' => 6, 'pack1_name' => 7, 'pack1_price' => 8,
        ], collect($run->mapping)->sort()->all());

        // The user maps one column differently; the next file with the same headings gets that mapping.
        $mapping = [...$run->mapping, 'pack1_price' => null];
        $preview = $this->imports()->preview($run, $mapping, ImportExisting::Skip, $this->owner);
        $this->assertSame(1, $preview->counts['created']);

        $next = $this->imports()->upload(ImportType::Items, $path, 'clinic-list-2.csv', $this->owner);
        $this->assertArrayNotHasKey('pack1_price', $next->mapping);
        $this->assertSame(6, $next->mapping['pack1_factor']);
    }

    public function test_a_required_field_must_be_mapped(): void
    {
        $path = $this->spreadsheet(['Code', 'Price'], [['A-1', 10]]);
        $run = $this->imports()->upload(ImportType::Items, $path, 'prices.xlsx', $this->owner);

        $this->expectExceptionMessage('Choose the column that holds Name: it is required.');
        $this->imports()->preview($run, (array) $run->mapping, ImportExisting::Skip, $this->owner);
    }

    public function test_only_someone_with_imports_manage_may_import(): void
    {
        $this->expectExceptionMessage('You do not have permission to import data.');
        $this->imports()->upload(ImportType::Items, $this->templateFile(ImportType::Items, [$this->panadol()]), 'x.xlsx', $this->cashier);
    }
}
