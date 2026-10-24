<?php

namespace Tests\Feature\Pos;

use App\Filament\Pages\ReceiptSettings;
use App\Services\CatalogSyncService;
use App\Services\ReceiptService;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithReceipts;

/**
 * Receipt settings in a pharmacy (docs/PHARMACY.md §9, branch 9): the
 * pharmacy profile prints the batch and the prescriber but not the expiry
 * (the doctor, 2026-10-21); the owner changes what receipts print, sees it
 * in the live preview before saving, and the till gets it for offline
 * receipts.
 */
class ReceiptSettingsTest extends BatchTestCase
{
    use WorksWithCheckout, WorksWithReceipts;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);
    }

    public function test_a_pharmacy_starts_with_the_batch_and_prescriber_printed_and_the_expiry_hidden(): void
    {
        $this->assertSame(
            ['paperWidth' => 80, 'showBatch' => true, 'showExpiry' => false, 'showPrescriber' => true],
            ReceiptService::printOptions($this->shop),
        );
        $this->assertSame('names', $this->shop->setting('opd_receipt_medicines'));
    }

    public function test_the_owner_changes_what_receipts_print(): void
    {
        Storage::fake('public');

        Livewire::test(ReceiptSettings::class)
            ->assertFormSet([
                'receipt_paper_width' => 80,
                'receipt_show_batch' => true,
                'receipt_show_expiry' => false,
                'receipt_show_prescriber' => true,
                'opd_receipt_medicines' => 'names',
                'receipt_footer' => 'Get well soon. Medicines are not returnable once dispensed.',
            ])
            ->fillForm([
                'logo_path' => UploadedFile::fake()->image('clinic.png', 300, 120),
                'receipt_header' => "Reg. no. PH 1234\nOpen 8 am to 8 pm",
                'receipt_footer' => 'Thank you',
                'receipt_paper_width' => 58,
                'receipt_show_batch' => false,
                'receipt_show_expiry' => true,
                'receipt_show_prescriber' => false,
                'opd_receipt_medicines' => 'totals',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Receipt settings saved');

        $shop = $this->shop->fresh();
        $this->assertSame(['paperWidth' => 58, 'showBatch' => false, 'showExpiry' => true, 'showPrescriber' => false], ReceiptService::printOptions($shop));
        $this->assertSame('totals', $shop->setting('opd_receipt_medicines'));
        $this->assertSame("Reg. no. PH 1234\nOpen 8 am to 8 pm", $shop->receipt_header);
        $this->assertSame('Thank you', $shop->receipt_footer);
        $this->assertNotNull($shop->logo_path);
        Storage::disk('public')->assertExists($shop->logo_path);

        // Read when printing: a bill rung up before prints the new way.
        $this->stockTheClinic();
        $printed = $this->printed('sale', app(ReceiptService::class)->sale($this->billAtGeneral()), 58);
        $this->assertStringContainsString("        Reg. no. PH 1234\n       Open 8 am to 8 pm", $printed);
        $this->assertStringContainsString('  Exp 03/2027', $printed);
        $this->assertStringNotContainsString('Batch', $printed);
        $this->assertStringEndsWith('           Thank you', $printed);

        // The logo goes to the thermal printer as a raster image.
        $escpos = base64_decode(app(ReceiptService::class)->thermal('sale', app(ReceiptService::class)->sale($this->billAtGeneral()))['escpos']);
        $this->assertStringStartsWith("\x1b@\x1ba\x01\x1dv0\x00", $escpos);
    }

    public function test_the_preview_shows_the_choices_before_they_are_saved(): void
    {
        Livewire::test(ReceiptSettings::class)
            ->assertSee('General Pharmacy bill')
            ->assertSee('OPD bill')
            ->assertSee('Batch PN2611')
            ->assertDontSee('Exp 03/2027')
            ->assertSee('Prescriber: Dr. S. Perera')
            ->assertSee('Paracetamol Syrup 120 mg/5 ml')
            ->assertSee('width: calc(48ch', false)
            ->fillForm(['receipt_show_expiry' => true, 'receipt_paper_width' => 58, 'opd_receipt_medicines' => 'totals', 'receipt_footer' => 'Preview footer'])
            ->assertSee('Batch PN2611 Exp 03/2027')
            ->assertSee('width: calc(32ch', false)
            ->assertDontSee('Paracetamol Syrup')
            ->assertSee('Preview footer');

        $this->assertFalse((bool) $this->shop->fresh()->setting('receipt_show_expiry'));
    }

    public function test_only_the_owner_opens_receipt_settings(): void
    {
        $this->actingAs($this->cashier)->get(ReceiptSettings::getUrl())->assertForbidden();
        $this->actingAs($this->owner)->get(ReceiptSettings::getUrl())->assertOk()->assertSee('Medicines on receipts');
    }

    public function test_the_till_gets_the_receipt_settings_for_offline_receipts(): void
    {
        $this->setShopSetting('receipt_paper_width', 58);

        $this->assertSame(
            ['paperWidth' => 58, 'showBatch' => true, 'showExpiry' => false, 'showPrescriber' => true],
            app(CatalogSyncService::class)->shopSettings($this->shop->fresh())['receipt'],
        );
    }
}
