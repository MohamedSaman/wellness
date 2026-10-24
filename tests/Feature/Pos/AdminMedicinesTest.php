<?php

namespace Tests\Feature\Pos;

use App\Enums\DosageForm;
use App\Enums\DrugSchedule;
use App\Enums\StorageCondition;
use App\Enums\TaxCategory;
use App\Filament\Resources\Brands\BrandResource;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\ProductFormData;
use App\Models\Location;
use App\Models\Product;
use App\Models\VariantUnitLocation;
use App\Services\Data\DrugData;
use App\Services\ProductCatalogService;
use Filament\Facades\Filament;
use Livewire\Livewire;

/** The product form as a medicine form, and the medicine list, on a pharmacy (demo clinic). */
class AdminMedicinesTest extends PharmacyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);
    }

    private function medicine(string $name, string $generic, DrugSchedule $schedule, DosageForm $form = DosageForm::Tablet, ?TaxCategory $tax = null): Product
    {
        return app(ProductCatalogService::class)->create(
            ['name' => $name, 'unit' => 'Tablet', ...($tax === null ? [] : ['tax_category' => $tax])],
            [['price' => 1_000]],
            user: $this->owner,
            drug: new DrugData([$generic], $form, $schedule),
        );
    }

    /** @return list<array<string, mixed>> */
    private function packs(mixed $component): array
    {
        $variants = array_values($component->get('data.variants'));

        return array_values(array_map(fn (array $pack): array => ['name' => $pack['name'], 'factor' => $pack['factor']], $variants[0]['packs'] ?? []));
    }

    public function test_the_form_starts_with_drug_details_and_has_no_spare_part_section(): void
    {
        Livewire::test(CreateProduct::class)
            ->assertSeeInOrder(['This is a medicine', 'Generic name', 'Brand name', 'Strength', 'Dosage form', 'Schedule', 'Storage condition', 'Storage note', 'NMRA registration no.', 'Manufacturer'])
            ->assertFormFieldIsVisible('tax_category')
            ->assertFormSet(['is_medicine' => true, 'tax_category' => 'exempt'])
            ->assertDontSee('Spare part details')
            ->assertDontSee('OEM / alternative numbers');
    }

    public function test_choosing_a_tablet_fills_in_loose_tablets_and_boxes_of_30_and_100(): void
    {
        $form = Livewire::test(CreateProduct::class)->set('data.drug.dosage_form', 'tablet');

        $form->assertFormSet(['unit' => 'Tablet', 'whole_units_only' => true]);
        $this->assertTrue(array_values($form->get('data.variants'))[0]['allow_unit_sale']);
        $this->assertSame([['name' => 'Box of 30', 'factor' => 30], ['name' => 'Box of 100', 'factor' => 100]], $this->packs($form));
    }

    public function test_choosing_a_syrup_counts_ml_sells_the_bottle_and_drops_the_tablet_packs(): void
    {
        $form = Livewire::test(CreateProduct::class)
            ->set('data.drug.dosage_form', 'tablet')
            ->set('data.drug.dosage_form', 'syrup');

        // Loose ml is off: General Pharmacy sells bottles only (selling loose per location comes with checkout).
        $form->assertFormSet(['unit' => 'ml', 'whole_units_only' => true]);
        $this->assertFalse(array_values($form->get('data.variants'))[0]['allow_unit_sale']);
        $this->assertSame([['name' => 'Bottle', 'factor' => 100]], $this->packs($form));
    }

    public function test_choosing_drops_sells_the_bottle_with_no_packs(): void
    {
        $form = Livewire::test(CreateProduct::class)->set('data.drug.dosage_form', 'drops');

        $form->assertFormSet(['unit' => 'Bottle']);
        $this->assertSame([], $this->packs($form));
    }

    public function test_choosing_an_injection_sells_single_vials_and_the_box(): void
    {
        $form = Livewire::test(CreateProduct::class)->set('data.drug.dosage_form', 'injection');

        $form->assertFormSet(['unit' => 'Vial']);
        $this->assertTrue(array_values($form->get('data.variants'))[0]['allow_unit_sale']);
        $this->assertSame([['name' => 'Box', 'factor' => 10]], $this->packs($form));
    }

    public function test_packs_with_a_price_typed_in_are_not_replaced(): void
    {
        $form = Livewire::test(CreateProduct::class)->set('data.drug.dosage_form', 'tablet');

        $variants = $form->get('data.variants');
        $key = array_key_first($variants);
        $packKey = array_key_first($variants[$key]['packs']);
        $form->set("data.variants.{$key}.packs.{$packKey}.price", '140')
            ->set("data.variants.{$key}.packs.{$packKey}.factor", 12)
            ->set('data.drug.dosage_form', 'capsule');

        $form->assertFormSet(['unit' => 'Capsule']);
        $this->assertSame([['name' => 'Box of 30', 'factor' => 12], ['name' => 'Box of 100', 'factor' => 100]], $this->packs($form));
    }

    public function test_the_suggested_units_can_be_changed_before_saving(): void
    {
        // The dosage form is picked first (it fills in the units); then the suggestions are edited.
        Livewire::test(CreateProduct::class)
            ->set('data.drug.dosage_form', 'tablet')
            ->fillForm([
                'drug' => [
                    'generic_names' => ['Amoxicillin', 'Clavulanic acid'],
                    'brand_name' => 'Augmentin',
                    'strength_value' => '625',
                    'strength_unit' => 'mg',
                    'dosage_form' => 'tablet',
                    'schedule' => 'prescription_only',
                    'storage_condition' => 'room',
                    'storage_note' => 'Store below 25 °C',
                    'registration_no' => 'N/R/0625',
                ],
                'name' => 'Augmentin 625 mg Tablets',
                'unit' => 'Caplet',
                'variants' => [[
                    'price' => '45',
                    'allow_unit_sale' => false,
                    'packs' => [['name' => 'Strip', 'factor' => 7, 'price' => '300', 'is_sellable' => true, 'is_purchasable' => true]],
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::query()->where('name', 'Augmentin 625 mg Tablets')->firstOrFail();
        $variant = $product->variants()->firstOrFail();

        $this->assertSame('Caplet', $product->unit);
        $this->assertSame(TaxCategory::Exempt, $product->tax_category);
        $this->assertSame(['Amoxicillin', 'Clavulanic acid'], $product->generics()->pluck('name')->all());
        $this->assertSame(DrugSchedule::PrescriptionOnly, $product->drugDetail?->schedule);
        $this->assertSame('625 mg', $product->drugDetail?->strength());
        $this->assertSame(StorageCondition::Room, $product->drugDetail?->storage_condition);
        $this->assertSame('N/R/0625', $product->drugDetail?->registration_no);
        $this->assertSame(['Caplet', 'Strip'], $variant->units()->orderBy('sort_order')->pluck('name')->all());
        $this->assertFalse($variant->baseUnit()->value('is_sellable'));
    }

    public function test_a_medicine_needs_generic_name_dosage_form_and_schedule(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm(['name' => 'Unknown', 'unit' => 'Tablet', 'drug' => ['strength_value' => '500'], 'variants' => [['price' => '1']]])
            ->call('create')
            ->assertHasFormErrors(['drug.generic_names' => 'required', 'drug.dosage_form' => 'required', 'drug.schedule' => 'required', 'drug.strength_unit' => 'required']);

        $this->assertFalse(Product::query()->where('name', 'Unknown')->exists());
    }

    public function test_a_consultation_fee_is_saved_as_a_standard_rated_non_medicine(): void
    {
        Livewire::test(CreateProduct::class)
            ->set('data.is_medicine', false)
            ->assertFormSet(['tax_category' => 'standard'])
            ->assertFormFieldIsHidden('drug.generic_names')
            ->fillForm(['name' => 'Consultation', 'unit' => 'Visit', 'track_stock' => false, 'variants' => [['price' => '2000']]])
            ->call('create')
            ->assertHasNoFormErrors();

        $fee = Product::query()->where('name', 'Consultation')->firstOrFail();

        $this->assertFalse($fee->isMedicine());
        $this->assertSame(TaxCategory::Standard, $fee->tax_category);
    }

    public function test_the_edit_form_shows_and_saves_drug_details(): void
    {
        $product = $this->medicine('Amoxil 250 mg Capsules', 'Amoxicillin', DrugSchedule::Otc, DosageForm::Capsule);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertFormSet([
                'is_medicine' => true,
                'tax_category' => 'exempt',
                'drug.generic_names' => ['Amoxicillin'],
                'drug.dosage_form' => 'capsule',
                'drug.schedule' => 'otc',
            ])
            ->assertFormSet(['drug.storage_condition' => null, 'drug.registration_no' => null])
            ->fillForm(['drug.schedule' => 'prescription_only', 'drug.brand_name' => 'Amoxil', 'tax_category' => 'standard', 'drug.storage_condition' => 'fridge', 'drug.registration_no' => 'N/R/0250'])
            ->call('save')
            ->assertHasNoFormErrors();

        $product->refresh();
        $this->assertSame(DrugSchedule::PrescriptionOnly, $product->drugDetail?->schedule);
        $this->assertSame('Amoxil', $product->drugDetail?->brand_name);
        $this->assertSame(StorageCondition::Fridge, $product->drugDetail?->storage_condition);
        $this->assertSame('N/R/0250', $product->drugDetail?->registration_no);
        $this->assertSame(TaxCategory::Standard, $product->tax_category);
        $this->assertSame('Tablet', $product->unit); // editing never re-applies the dosage-form defaults
    }

    public function test_selling_loose_is_set_per_location_on_the_form(): void
    {
        $product = app(ProductCatalogService::class)->create(
            ['name' => 'Piriton Syrup', 'unit' => 'ml'],
            [['price' => 400, 'allow_unit_sale' => false, 'packs' => [['name' => 'Bottle', 'factor' => 100, 'price' => 38_000]]]],
            user: $this->owner,
            drug: new DrugData(['Chlorphenamine'], DosageForm::Syrup, DrugSchedule::Otc),
        );
        $general = Location::query()->where('code', 'GEN')->sole();
        $doctors = Location::query()->where('code', 'DOC')->sole();
        $form = ProductFormData::fromProduct($product);
        $this->assertSame([], $form['variants'][0]['loose_at']);
        $form['variants'][0]['loose_at'] = [$doctors->getKey() => 'yes'];

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertSee('Sell loose at GEN')
            ->assertSee('Sell loose at DOC')
            ->fillForm(['variants' => $form['variants']])
            ->call('save')
            ->assertHasNoFormErrors();

        $variant = $product->variants()->with('units.locationRules')->firstOrFail();
        $ml = $variant->units->firstWhere('is_base', true);
        $this->assertFalse($variant->canSellUnitAt($ml, $general));
        $this->assertTrue($variant->canSellUnitAt($ml, $doctors));

        // Shown again on the form; blank goes back to "Sell loose".
        $form = ProductFormData::fromProduct($product->refresh());
        $this->assertSame([$doctors->getKey() => 'yes'], $form['variants'][0]['loose_at']);
        $form['variants'][0]['loose_at'] = [$doctors->getKey() => null];

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['variants' => $form['variants']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(0, VariantUnitLocation::query()->count());
    }

    public function test_the_list_filters_by_schedule_dosage_form_tax_and_active(): void
    {
        $panadol = $this->medicine('Panadol 500 mg', 'Paracetamol', DrugSchedule::Otc);
        $amoxil = $this->medicine('Amoxil 250 mg', 'Amoxicillin', DrugSchedule::PrescriptionOnly, DosageForm::Capsule);
        $morphine = $this->medicine('Morphine 10 mg', 'Morphine', DrugSchedule::Controlled, DosageForm::Injection, TaxCategory::Standard);
        $morphine->update(['is_active' => false]);

        Livewire::test(ListProducts::class)
            ->assertTableColumnVisible('drugDetail.schedule')
            ->filterTable('schedule', 'prescription_only')
            ->assertCanSeeTableRecords([$amoxil])->assertCanNotSeeTableRecords([$panadol, $morphine])
            ->resetTableFilters()
            ->filterTable('dosage_form', 'injection')
            ->assertCanSeeTableRecords([$morphine])->assertCanNotSeeTableRecords([$panadol, $amoxil])
            ->resetTableFilters()
            ->filterTable('tax_category', 'exempt')
            ->assertCanSeeTableRecords([$panadol, $amoxil])->assertCanNotSeeTableRecords([$morphine])
            ->resetTableFilters()
            ->filterTable('is_active', true)
            ->assertCanSeeTableRecords([$panadol, $amoxil])->assertCanNotSeeTableRecords([$morphine]);
    }

    public function test_the_list_filters_by_storage_condition_and_can_show_the_nmra_number(): void
    {
        $catalog = app(ProductCatalogService::class);
        $insulin = $catalog->create(['name' => 'Insulatard', 'unit' => 'Vial'], [['price' => 1_000]], user: $this->owner,
            drug: new DrugData(['Insulin isophane'], DosageForm::Injection, DrugSchedule::PrescriptionOnly, storageCondition: StorageCondition::Fridge, registrationNo: 'N/R/1234'));
        $panadol = $this->medicine('Panadol 500 mg', 'Paracetamol', DrugSchedule::Otc);

        Livewire::test(ListProducts::class)
            ->assertTableColumnExists('drugDetail.registration_no')
            ->assertDontSee('N/R/1234') // hidden until toggled on
            ->filterTable('storage_condition', 'fridge')
            ->assertCanSeeTableRecords([$insulin])->assertCanNotSeeTableRecords([$panadol])
            ->toggleAllTableColumns()
            ->assertSee('N/R/1234');
    }

    public function test_the_list_searches_generic_and_brand_names(): void
    {
        $panadol = $this->medicine('Panadol 500 mg', 'Paracetamol', DrugSchedule::Otc);
        $amoxil = $this->medicine('Amoxil 250 mg', 'Amoxicillin', DrugSchedule::PrescriptionOnly);

        Livewire::test(ListProducts::class)
            ->searchTable('amoxici')
            ->assertCanSeeTableRecords([$amoxil])->assertCanNotSeeTableRecords([$panadol])
            ->assertSee('Amoxicillin');
    }

    public function test_brands_are_called_manufacturers(): void
    {
        $this->assertSame('Manufacturers', BrandResource::getNavigationLabel());

        $this->get(BrandResource::getUrl())->assertOk()->assertSee('Manufacturers');
    }

    public function test_a_cashier_can_look_at_medicines_but_not_create_or_change_them(): void
    {
        $product = $this->medicine('Panadol 500 mg', 'Paracetamol', DrugSchedule::Otc);
        $this->cashier->givePermissionTo('admin.access'); // even with the panel open to them
        $this->actingAs($this->cashier);

        Livewire::test(ListProducts::class)->assertSuccessful()->assertCanSeeTableRecords([$product]);
        $this->get(route('filament.admin.resources.products.create'))->assertForbidden();
        $this->get(route('filament.admin.resources.products.edit', $product))->assertForbidden();
    }
}
