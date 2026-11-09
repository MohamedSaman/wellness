<?php

namespace Tests\Feature\Pos;

use App\Filament\Pages\ReceiptSettings;
use App\Filament\Pages\ShopSettings;
use App\Services\ReceiptService;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The patients' settings (docs/PHARMACY.md, branch 13): "Patient on sale"
 * in Shop settings → Pharmacy checkout, and printing the patient's name and
 * age in Receipt settings, both off by default and shown in the preview
 * before they are saved.
 */
class PatientSettingsTest extends BatchTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);
    }

    public function test_the_owner_chooses_when_the_till_needs_the_patient(): void
    {
        Livewire::test(ShopSettings::class)
            ->assertFormSet(['patient_on_sale' => 'ask'])
            ->assertSee('Ask, can skip: the till asks on every sale; the cashier may skip it')
            ->assertSee('Required for prescription &amp; controlled medicines', false)
            ->fillForm(['patient_on_sale' => 'rx'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('rx', $this->shop->fresh()?->setting('patient_on_sale'));

        foreach (['always', 'off'] as $mode) {
            Livewire::test(ShopSettings::class)->fillForm(['patient_on_sale' => $mode])->call('save')->assertHasNoFormErrors();
            $this->assertSame($mode, $this->shop->fresh()?->setting('patient_on_sale'));
        }

        Livewire::test(ShopSettings::class)->fillForm(['patient_on_sale' => 'sometimes'])->call('save')->assertHasFormErrors(['patient_on_sale']);
    }

    public function test_the_owner_prints_the_patients_name_and_age_and_sees_it_first(): void
    {
        Livewire::test(ReceiptSettings::class)
            ->assertFormSet(['receipt_show_patient_name' => false, 'receipt_show_patient_age' => false])
            ->assertSee('Patient on receipts')
            ->assertDontSee('Nimal Perera')
            ->fillForm(['receipt_show_patient_name' => true])
            ->assertSeeHtml('Patient')
            ->assertSee('Nimal Perera')
            ->assertDontSee('Nimal Perera (34 y)')
            ->fillForm(['receipt_show_patient_age' => true])
            ->assertSee('Nimal Perera (34 y)')
            ->call('save')
            ->assertHasNoFormErrors();

        $options = ReceiptService::printOptions($this->shop->fresh() ?? $this->shop);
        $this->assertTrue($options['showPatientName'] ?? null);
        $this->assertTrue($options['showPatientAge'] ?? null);
    }
}
