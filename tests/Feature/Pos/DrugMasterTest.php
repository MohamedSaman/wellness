<?php

namespace Tests\Feature\Pos;

use App\Enums\DosageForm;
use App\Enums\DrugSchedule;
use App\Enums\StorageCondition;
use App\Enums\TaxCategory;
use App\Exceptions\PosException;
use App\Models\AuditLog;
use App\Models\DrugDetail;
use App\Models\Generic;
use App\Models\Product;
use App\Models\Shop;
use App\Services\Data\BatchInput;
use App\Services\Data\DrugData;
use App\Services\ProductCatalogService;
use App\Support\CurrentShop;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;

/** Drug details, generic names and search by generic or brand name (docs/PHARMACY.md §4.1). */
class DrugMasterTest extends PharmacyTestCase
{
    private function catalog(): ProductCatalogService
    {
        return app(ProductCatalogService::class);
    }

    /**
     * @param  list<string>  $generics
     */
    private function medicine(string $name, array $generics, ?string $brand = null, DrugSchedule $schedule = DrugSchedule::Otc, string $unit = 'Tablet', ?float $strength = 500, string $strengthUnit = 'mg'): Product
    {
        return $this->catalog()->create(
            ['name' => $name, 'unit' => $unit],
            [['price' => 1_500, 'opening_stock' => 100, 'opening_batch' => new BatchInput('B1', '12/2028')]],
            user: $this->owner,
            drug: new DrugData($generics, DosageForm::Tablet, $schedule, $brand, $strength, $strengthUnit, 'Store below 25 °C'),
        );
    }

    private function search(string $term): TestResponse
    {
        return $this->actingAs($this->cashier)->getJson(route('pos.search', ['device' => $this->till->uuid, 'q' => $term]));
    }

    public function test_a_medicine_is_saved_with_its_drug_details_whole_units_and_no_tax(): void
    {
        $product = $this->medicine('Panadol 500 mg Tablets', ['Paracetamol'], 'Panadol')->refresh();

        /** @var DrugDetail $detail */
        $detail = $product->drugDetail;

        $this->assertTrue($product->isMedicine());
        $this->assertSame('Panadol', $detail->brand_name);
        $this->assertSame('500 mg', $detail->strength());
        $this->assertSame(DosageForm::Tablet, $detail->dosage_form);
        $this->assertSame(DrugSchedule::Otc, $detail->schedule);
        $this->assertSame('Store below 25 °C', $detail->storage_note);
        $this->assertNull($detail->storage_condition);
        $this->assertNull($detail->registration_no);
        $this->assertSame(['Paracetamol'], $product->generics->pluck('name')->all());
        $this->assertSame((int) $this->shop->getKey(), (int) $detail->getAttribute('shop_id'));

        // Medicine defaults (Q11, §4.2).
        $this->assertTrue($product->whole_units_only);
        $this->assertSame(TaxCategory::Exempt, $product->tax_category);
    }

    public function test_a_medicine_can_be_set_to_standard_tax_and_part_units(): void
    {
        $product = $this->catalog()->create(
            ['name' => 'Cough Syrup', 'unit' => 'ml', 'tax_category' => TaxCategory::Standard, 'whole_units_only' => false],
            [['price' => 500]],
            user: $this->owner,
            drug: new DrugData(['Dextromethorphan'], DosageForm::Syrup, DrugSchedule::Otc, strengthValue: 15, strengthUnit: 'mg/5 ml'),
        )->refresh();

        $this->assertSame(TaxCategory::Standard, $product->tax_category);
        $this->assertFalse($product->whole_units_only);
        $this->assertSame('15 mg/5 ml', $product->drugDetail?->strength());
    }

    public function test_a_product_without_drug_details_is_not_a_medicine_and_stays_standard(): void
    {
        $fee = $this->catalog()->create(['name' => 'Consultation', 'track_stock' => false], [['price' => 200_000]], user: $this->owner)->refresh();

        $this->assertFalse($fee->isMedicine());
        $this->assertSame(TaxCategory::Standard, $fee->tax_category);
        $this->assertFalse($fee->whole_units_only);
    }

    public function test_generic_names_are_shared_across_products_whatever_their_case(): void
    {
        $this->medicine('Panadol 500 mg', ['Paracetamol'], 'Panadol');
        $this->medicine('Paracetamol 500 mg (SPC)', ['  paracetamol '], null);

        $this->assertSame(1, Generic::query()->count());
        $this->assertSame('Paracetamol', Generic::query()->value('name'));
        $this->assertSame(2, Generic::query()->firstOrFail()->products()->count());
    }

    public function test_storage_condition_and_nmra_registration_number_are_saved_and_can_be_cleared(): void
    {
        $insulin = $this->catalog()->create(
            ['name' => 'Insulatard 100 IU/ml', 'unit' => 'Vial'],
            [['price' => 250_000]],
            user: $this->owner,
            drug: new DrugData(['Insulin isophane'], DosageForm::Injection, DrugSchedule::PrescriptionOnly, 'Insulatard', 100, 'IU/ml',
                storageNote: 'Do not freeze', storageCondition: StorageCondition::Fridge, registrationNo: '  N/R/1234  '),
        );

        $detail = $insulin->drugDetail()->firstOrFail();
        $this->assertSame(StorageCondition::Fridge, $detail->storage_condition);
        $this->assertSame('N/R/1234', $detail->registration_no);

        $this->catalog()->update($insulin, ['name' => $insulin->name], [], [], null, $this->owner,
            new DrugData(['Insulin isophane'], DosageForm::Injection, DrugSchedule::PrescriptionOnly, 'Insulatard', 100, 'IU/ml'));

        $detail->refresh();
        $this->assertNull($detail->storage_condition);
        $this->assertNull($detail->registration_no);
    }

    public function test_a_combination_drug_keeps_its_generics_in_label_order(): void
    {
        $product = $this->medicine('Augmentin 625 mg', ['Amoxicillin', 'Clavulanic acid', 'amoxicillin'], 'Augmentin', DrugSchedule::PrescriptionOnly, strength: 625);

        $this->assertSame(['Amoxicillin', 'Clavulanic acid'], $product->generics()->pluck('name')->all());
    }

    public function test_a_medicine_needs_a_generic_name(): void
    {
        $this->expectException(PosException::class);
        $this->expectExceptionMessage('needs a generic name');

        $this->medicine('Mystery pill', ['  ']);
    }

    public function test_a_strength_needs_a_unit_and_must_be_positive(): void
    {
        foreach ([[500.0, null, 'measured in'], [0.0, 'mg', 'more than zero']] as [$value, $unit, $message]) {
            try {
                $this->catalog()->create(['name' => 'Bad strength'], [['price' => 100]], user: $this->owner,
                    drug: new DrugData(['Paracetamol'], DosageForm::Tablet, DrugSchedule::Otc, strengthValue: $value, strengthUnit: $unit));
                $this->fail('A bad strength was accepted.');
            } catch (PosException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }

        $this->assertFalse(Product::query()->where('name', 'Bad strength')->exists());
    }

    public function test_updating_replaces_drug_details_null_leaves_them_and_false_removes_them(): void
    {
        $product = $this->medicine('Amoxil 250 mg', ['Amoxicillin'], 'Amoxil', DrugSchedule::PrescriptionOnly, 'Capsule', 250);
        $update = fn (DrugData|false|null $drug) => $this->catalog()->update($product, ['name' => $product->name], [], [], null, $this->owner, $drug);

        $update(new DrugData(['Amoxicillin trihydrate'], DosageForm::Capsule, DrugSchedule::Controlled, 'Amoxil Forte', 500, 'mg'));
        $detail = $product->drugDetail()->firstOrFail();
        $this->assertSame(DrugSchedule::Controlled, $detail->schedule);
        $this->assertSame('500 mg', $detail->strength());
        $this->assertSame(['Amoxicillin trihydrate'], $product->generics()->pluck('name')->all());

        $update(null);
        $this->assertTrue($product->drugDetail()->exists());

        $update(false);
        $this->assertFalse($product->drugDetail()->exists());
        $this->assertSame(0, $product->generics()->count());
    }

    public function test_schedule_changes_are_audited(): void
    {
        $this->actingAs($this->owner);
        $product = $this->medicine('Amoxil 250 mg', ['Amoxicillin'], 'Amoxil', DrugSchedule::Otc);

        $this->catalog()->update($product, ['name' => $product->name], [], [], null, $this->owner,
            new DrugData(['Amoxicillin'], DosageForm::Capsule, DrugSchedule::PrescriptionOnly, 'Amoxil', 250, 'mg'));

        $entry = AuditLog::query()->where('auditable_type', (new DrugDetail)->getMorphClass())->where('event', 'updated')->latest('id')->firstOrFail();

        $this->assertSame('otc', $entry->old_values['schedule'] ?? null);
        $this->assertSame('prescription_only', $entry->new_values['schedule'] ?? null);
    }

    public function test_only_users_who_manage_products_can_create_or_change_medicines(): void
    {
        $product = $this->medicine('Panadol 500 mg', ['Paracetamol'], 'Panadol');

        try {
            $this->catalog()->create(['name' => 'Cashier pill'], [['price' => 100]], user: $this->cashier,
                drug: new DrugData(['Paracetamol'], DosageForm::Tablet, DrugSchedule::Otc));
            $this->fail('A cashier created a medicine.');
        } catch (PosException) {
        }

        try {
            $this->catalog()->update($product, ['name' => $product->name], [], [], null, $this->cashier,
                new DrugData(['Paracetamol'], DosageForm::Tablet, DrugSchedule::Controlled));
            $this->fail('A cashier changed a schedule.');
        } catch (PosException) {
        }

        $this->assertFalse(Product::query()->where('name', 'Cashier pill')->exists());
        $this->assertSame(DrugSchedule::Otc, $product->drugDetail()->firstOrFail()->schedule);
    }

    public function test_the_till_finds_medicines_by_partial_generic_or_brand_name(): void
    {
        $panadol = $this->medicine('Panadol 500 mg Tablets', ['Paracetamol'], 'Panadol');
        $augmentin = $this->medicine('Augmentin 625 mg Tablets', ['Amoxicillin', 'Clavulanic acid'], 'Augmentin', DrugSchedule::PrescriptionOnly, strength: 625);
        $this->medicine('Brufen 400 mg Tablets', ['Ibuprofen'], 'Brufen', strength: 400);

        $names = fn (string $term): array => collect($this->search($term)->assertOk()->json('results'))->pluck('name')->sort()->values()->all();

        $this->assertSame([$panadol->name], $names('paracet'));  // generic, start of the name
        $this->assertSame([$augmentin->name], $names('CLAVUL'));  // second generic, any case
        $this->assertSame([$augmentin->name], $names('ulanic'));  // generic, middle of the name
        $this->assertSame([$panadol->name], $names('panad'));     // brand
        $this->assertSame([], $names('aspirin'));
    }

    public function test_search_results_carry_drug_details_and_tax_category_but_no_cost(): void
    {
        $this->medicine('Augmentin 625 mg Tablets', ['Amoxicillin', 'Clavulanic acid'], 'Augmentin', DrugSchedule::PrescriptionOnly, strength: 625);
        $this->catalog()->create(['name' => 'Cotton wool', 'unit' => 'Pack'], [['price' => 25_000]], user: $this->owner);

        $medicine = $this->search('amoxi')->json('results.0');

        $this->assertSame('exempt', $medicine['tax_category']);
        $this->assertSame([
            'generic_names' => ['Amoxicillin', 'Clavulanic acid'],
            'brand_name' => 'Augmentin',
            'strength' => '625 mg',
            'dosage_form' => 'tablet',
            'schedule' => 'prescription_only',
        ], $medicine['drug']);
        $this->assertArrayNotHasKey('cost_price', $medicine);

        $sundry = $this->search('cotton')->json('results.0');
        $this->assertNull($sundry['drug']);
        $this->assertSame('standard', $sundry['tax_category']);
    }

    public function test_search_by_drug_name_runs_a_fixed_number_of_queries(): void
    {
        foreach (range(1, 12) as $n) {
            $this->medicine("Paracetamol {$n}00 mg", ['Paracetamol', "Additive {$n}"], "Brand {$n}");
        }

        $this->search('warm up'); // loads the cashier's permissions once

        DB::flushQueryLog();
        DB::enableQueryLog();
        $results = $this->search('paracetamol')->assertOk()->json('results');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(12, $results);
        // Same count as a search that finds one item: drug details and generics are eager loaded.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->search('Brand 7')->assertOk();
        $this->assertSame($queries, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_drug_names_are_not_searched_when_the_pharmacy_module_is_off(): void
    {
        $this->medicine('Panadol 500 mg Tablets', ['Paracetamol'], 'Panadol');
        Feature::for($this->shop)->deactivate('module:pharmacy');

        $this->assertSame([], $this->search('paracet')->json('results'));
        $this->assertNull($this->search('Panadol 500')->json('results.0.drug'));
    }

    public function test_generics_belong_to_their_shop(): void
    {
        $this->medicine('Panadol 500 mg', ['Paracetamol'], 'Panadol');
        $other = Shop::query()->create(['name' => 'Other Clinic', 'code' => 'OTHER']);

        app(CurrentShop::class)->runAs($other, function (): void {
            $this->assertSame(0, Generic::query()->count());
            $this->assertSame(0, DrugDetail::query()->count());
        });
    }

    public function test_the_offline_catalog_carries_drug_details_and_picks_up_their_changes(): void
    {
        Feature::for($this->shop)->activate('module:offline_sync');
        $token = $this->till->issueSyncToken('Counter')->plainTextToken;
        $product = $this->medicine('Panadol 500 mg Tablets', ['Paracetamol'], 'Panadol');

        $item = collect($this->withToken($token)->getJson(route('pos.sync.catalog'))->assertOk()->json('items'))->firstWhere('name', 'Panadol 500 mg Tablets');
        $this->assertSame('exempt', $item['tax_category']);
        $this->assertSame(['Paracetamol'], $item['drug']['generic_names']);

        $this->travel(5)->minutes();
        $cursor = $this->withToken($token)->getJson(route('pos.sync.catalog'))->json('cursor');
        $this->travel(5)->minutes();

        $this->catalog()->update($product, ['name' => $product->name], [], [], null, $this->owner,
            new DrugData(['Paracetamol'], DosageForm::Tablet, DrugSchedule::PrescriptionOnly, 'Panadol', 500, 'mg'));

        $this->withToken($token)->getJson(route('pos.sync.catalog', ['since' => $cursor]))
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.drug.schedule', 'prescription_only');
    }
}
