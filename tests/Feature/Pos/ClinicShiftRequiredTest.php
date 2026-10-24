<?php

namespace Tests\Feature\Pos;

use App\Filament\Pages\ShopSettings;
use App\Services\ShiftService;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;

/**
 * The clinic sells only with an open shift (decided 2026-10-22): the
 * pharmacy profile starts shops with require_open_shift on, the till does
 * not offer "Sell without a shift", checkout refuses a sale without one
 * (an offline till's sale is still accepted: it already happened), and
 * the owner can change it on Shop settings. The other clinic tests turn
 * it off (SeedsDemoClinic) because they are not about shifts.
 */
class ClinicShiftRequiredTest extends BatchTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->shop->update(['settings' => [...($this->shop->settings ?? []), 'require_open_shift' => true]]);
    }

    public function test_the_pharmacy_profile_starts_shops_with_an_open_shift_required(): void
    {
        $this->assertTrue(config('pos.profiles.pharmacy.settings.require_open_shift'));
        $this->assertArrayNotHasKey('require_open_shift', config('pos.profiles.retail.settings'));
        $this->assertFalse(config('pos.shop_defaults.require_open_shift'));
    }

    public function test_the_till_does_not_offer_selling_without_a_shift_and_checkout_refuses_one(): void
    {
        $this->actingAs($this->cashier)
            ->get(route('pos.index'))
            ->assertInertia(fn (Assert $page) => $page->where('shop.requireOpenShift', true));

        $this->useShop($this->shop);
        $panadol = $this->medicine();
        $this->receive($panadol, 'B1', '2027-12-31', 10);
        $payload = [
            'device' => $this->till->uuid,
            'uuid' => (string) Str::uuid(),
            'lines' => [['variant_id' => $panadol->getKey(), 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => '100000']],
        ];

        $this->actingAs($this->cashier)->postJson(route('pos.checkout'), $payload)
            ->assertUnprocessable()
            ->assertJson(['message' => 'Open a shift before making sales.']);

        app(ShiftService::class)->open($this->till, $this->cashier, 500_000);
        $this->actingAs($this->cashier)->postJson(route('pos.checkout'), $payload)->assertOk();
    }

    public function test_the_owner_can_turn_it_off_on_shop_settings(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);

        Livewire::test(ShopSettings::class)
            ->assertSchemaStateSet(['require_open_shift' => true], 'form')
            ->fillForm(['require_open_shift' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($this->shop->fresh()?->setting('require_open_shift'));
    }
}
