<?php

namespace Tests\Feature\Pos;

use App\Filament\Pages\ShopSettings;
use App\Filament\Resources\Devices\Pages\ManageDevices;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Sales\Pages\ViewSale;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\AuditLog;
use App\Models\Product;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use Livewire\Livewire;

/**
 * The pharmacy checkout admin fields (branch 8) stay out of a retail shop:
 * no allowed locations, OPD tills, pharmacy or till-clock settings, and the
 * sale page as before. The one shared field: a service's price can be
 * typed at the till.
 */
class AdminRetailCheckoutFieldsTest extends AdminTestCase
{
    public function test_retail_users_tills_and_settings_have_no_pharmacy_checkout_fields(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(EditUser::class, ['record' => $this->cashier->getKey()])
            ->assertFormFieldIsHidden('allowed_location_ids')
            ->fillForm(['name' => 'Renamed Cashier'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Renamed Cashier', $this->cashier->fresh()->name);
        $this->assertNull($this->cashier->fresh()->usableLocationIds());
        $this->assertFalse(AuditLog::query()->where('event', 'allowed_locations_changed')->exists());

        Livewire::test(ListUsers::class)->assertTableColumnHidden('allowed_locations');

        Livewire::test(ManageDevices::class)
            ->assertTableColumnHidden('opd_billing')
            ->mountTableAction('edit', $this->till)
            ->assertSchemaComponentHidden('opd_billing');

        $before = $this->shop->settings ?? [];

        Livewire::test(ShopSettings::class)
            ->assertFormFieldIsHidden('rx_prescriber')
            ->assertFormFieldIsHidden('default_prescriber')
            ->assertFormFieldIsHidden('controlled_register')
            ->assertFormFieldIsHidden('opd_consultation_variant_id')
            ->assertFormFieldIsHidden('bank_transfer_reference_required')
            ->assertFormFieldIsHidden('till_clock_tolerance_minutes')
            ->assertDontSee('Pharmacy checkout')
            ->call('save')
            ->assertHasNoFormErrors();

        $after = $this->shop->fresh()->settings ?? [];
        foreach (['rx_prescriber', 'default_prescriber', 'controlled_register', 'opd_consultation_variant_id', 'opd_receipt_medicines', 'bank_transfer_reference_required', 'till_clock_tolerance_minutes'] as $key) {
            $this->assertSame(array_key_exists($key, $before), array_key_exists($key, $after), $key);
        }
    }

    public function test_a_retail_service_can_allow_its_price_to_be_changed_at_the_till(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(CreateProduct::class)
            ->assertFormFieldIsHidden('allow_price_override')
            ->fillForm(['name' => 'Helmet fitting', 'unit' => 'job', 'track_stock' => false, 'allow_price_override' => true, 'variants' => [['price' => '500']]])
            ->assertFormFieldIsVisible('allow_price_override')
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(Product::query()->where('name', 'Helmet fitting')->firstOrFail()->allowsPriceOverride());
    }

    public function test_a_retail_sale_page_is_unchanged(): void
    {
        $sale = app(CheckoutService::class)->checkout($this->cart([new CartLine($this->variant('CPR8EA-9')->getKey(), 2)]));
        $this->actingAs($this->owner);

        Livewire::test(ViewSale::class, ['record' => $sale->getRouteKey()])
            ->assertSuccessful()
            ->assertDontSee('OPD bill')
            ->assertDontSee('Medicines amount')
            ->assertDontSee('Counted in reports at')
            ->assertDontSee('Schedule')
            ->assertDontSee('Prescriber');
    }
}
