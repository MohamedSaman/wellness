<?php

namespace Tests\Feature\Pos;

use App\Enums\BillType;
use App\Enums\DrugSchedule;
use App\Enums\PaymentMethod;
use App\Exceptions\PosException;
use App\Filament\Pages\ShopSettings;
use App\Filament\Resources\Devices\Pages\ManageDevices;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Sales\Pages\ViewSale;
use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\LocationService;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * The admin screens for pharmacy checkout (docs/PHARMACY.md §9, branch 8):
 * users' allowed locations, OPD tills, the price-override toggle on a
 * service, the Pharmacy checkout and Offline tills settings, and what the
 * sale page shows of an OPD bill, prescriptions and a till's clock.
 */
class AdminPharmacyCheckoutTest extends BatchTestCase
{
    use WorksWithCheckout;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);
    }

    // Users: allowed locations

    public function test_owner_restricts_a_cashier_to_a_location_and_clears_it_again(): void
    {
        $doc = (int) $this->doctors->getKey();

        Livewire::test(EditUser::class, ['record' => $this->cashier->getKey()])
            ->assertFormSet(['allowed_location_ids' => []])
            ->assertSee('Leave empty for General Pharmacy only. Users who may work everywhere (the owner) can use every location.')
            ->fillForm(['allowed_location_ids' => [$doc]])
            ->call('save')
            ->assertHasNoFormErrors();

        $cashier = $this->cashier->fresh();
        $this->assertSame([$doc], $cashier->usableLocationIds());
        $this->assertFalse($cashier->mayUseLocation($this->general));

        Livewire::test(EditUser::class, ['record' => $this->cashier->getKey()])
            ->assertFormSet(['allowed_location_ids' => [$doc]])
            ->fillForm(['allowed_location_ids' => []])
            ->call('save')
            ->assertHasNoFormErrors();

        // Cleared: no rows, so the cashier is back to the default location only.
        $this->assertSame([], $this->cashier->fresh()->allowedLocationIds());
        $this->assertSame([(int) $this->general->getKey()], $this->cashier->fresh()->usableLocationIds());

        $entries = AuditLog::query()->where('event', 'allowed_locations_changed')->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertSame($this->owner->getKey(), $entries[0]->user_id);
        $this->assertSame($this->cashier->getKey(), $entries[0]->auditable_id);
        $this->assertSameIgnoringKeyOrder(['location_ids' => []], $entries[0]->old_values);
        $this->assertSameIgnoringKeyOrder(['location_ids' => [$doc]], $entries[0]->new_values);
        $this->assertSameIgnoringKeyOrder(['location_ids' => [$doc]], $entries[1]->old_values);
        $this->assertSameIgnoringKeyOrder(['location_ids' => []], $entries[1]->new_values);
    }

    public function test_a_new_user_is_created_with_their_allowed_locations(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'OPD Cashier',
                'email' => 'opd@clinic.test',
                'password' => 'a-strong-password',
                'role' => 'cashier',
                'allowed_location_ids' => [(int) $this->doctors->getKey()],
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::query()->where('email', 'opd@clinic.test')->firstOrFail();

        $this->assertSame([(int) $this->doctors->getKey()], $user->usableLocationIds());
        $this->assertTrue(AuditLog::query()->where('event', 'allowed_locations_changed')->where('auditable_id', $user->getKey())->exists());
    }

    public function test_the_users_list_shows_allowed_locations_the_default_or_all(): void
    {
        app(LocationService::class)->setAllowedLocations($this->cashier, [(int) $this->doctors->getKey(), (int) $this->general->getKey()], $this->owner);
        $atDefault = $this->cashierAt();

        Livewire::test(ListUsers::class)
            ->assertTableColumnExists('allowed_locations')
            ->assertTableColumnStateSet('allowed_locations', 'All', $this->owner)
            ->assertTableColumnStateSet('allowed_locations', "Doctor's Medicines, General Pharmacy", $this->cashier)
            ->assertTableColumnStateSet('allowed_locations', 'General Pharmacy (default)', $atDefault);
    }

    public function test_the_form_shows_only_the_stored_locations_and_saving_keeps_none(): void
    {
        // The cashier may use General Pharmacy (the default) without any rows: the form
        // shows none, and saving it unchanged does not turn the default into a row.
        Livewire::test(EditUser::class, ['record' => $this->cashier->getKey()])
            ->assertFormSet(['allowed_location_ids' => []])
            ->fillForm(['name' => 'Clinic Cashier'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([], $this->cashier->fresh()->allowedLocationIds());
        $this->assertFalse(AuditLog::query()->where('event', 'allowed_locations_changed')->exists());
    }

    public function test_a_deactivated_location_is_refused_rather_than_dropped(): void
    {
        $store = app(LocationService::class)->create(['name' => 'Back store', 'code' => 'STORE'], $this->owner);
        app(LocationService::class)->setAllowedLocations($this->cashier, [(int) $store->getKey()], $this->owner);
        $store->forceFill(['is_active' => false])->save();

        // Saving the form as it is must not widen the list to every location.
        Livewire::test(EditUser::class, ['record' => $this->cashier->getKey()])
            ->assertFormSet(['allowed_location_ids' => [(int) $store->getKey()]])
            ->call('save')
            ->assertNotified('Choose active locations of this shop.');

        $this->assertSame([(int) $store->getKey()], $this->cashier->fresh()->usableLocationIds());
    }

    public function test_allowed_locations_need_users_manage(): void
    {
        $this->actingAs($this->cashier);

        $this->get(EditUser::getUrl(['record' => $this->cashier]))->assertForbidden();

        try {
            UserResource::applyAllowedLocations($this->cashier, ['allowed_location_ids' => [(int) $this->doctors->getKey()]]);
            $this->fail('A cashier set allowed locations.');
        } catch (PosException $exception) {
            $this->assertSame('You do not have permission to manage users.', $exception->getMessage());
        }

        $this->assertSame([], $this->cashier->fresh()->allowedLocationIds());
        $this->assertFalse(AuditLog::query()->where('event', 'allowed_locations_changed')->exists());
    }

    // Tills: OPD bills

    public function test_owner_marks_a_till_for_opd_bills(): void
    {
        Livewire::test(ManageDevices::class)
            ->assertTableColumnVisible('opd_billing')
            ->callTableAction('edit', $this->till, data: ['name' => 'Front counter', 'location_id' => $this->general->getKey(), 'is_active' => true, 'opd_billing' => true])
            ->assertHasNoTableActionErrors();

        $this->assertTrue($this->till->fresh()->opd_billing);

        Livewire::test(ManageDevices::class)
            ->callAction('create', data: ['code' => 'T2', 'name' => 'OPD counter', 'location_id' => $this->doctors->getKey(), 'is_active' => true, 'opd_billing' => true])
            ->assertHasNoActionErrors();

        $this->assertTrue(Device::query()->where('code', 'T2')->firstOrFail()->opd_billing);
    }

    // Products: price typed at the till

    public function test_a_service_can_allow_its_price_to_be_changed_at_the_till(): void
    {
        Livewire::test(CreateProduct::class)
            ->set('data.is_medicine', false)
            ->fillForm(['name' => 'Consultation', 'unit' => 'Visit', 'track_stock' => false, 'allow_price_override' => true, 'variants' => [['price' => '1500']]])
            ->assertFormFieldIsVisible('allow_price_override')
            ->call('create')
            ->assertHasNoFormErrors();

        $fee = Product::query()->where('name', 'Consultation')->firstOrFail();

        $this->assertTrue($fee->allow_price_override);
        $this->assertTrue($fee->allowsPriceOverride());

        // A stock item never offers it.
        Livewire::test(CreateProduct::class)
            ->fillForm(['track_stock' => true])
            ->assertFormFieldIsHidden('allow_price_override');
    }

    // Shop settings

    public function test_owner_saves_the_pharmacy_checkout_and_offline_settings(): void
    {
        $fee = $this->consultation();
        $this->setShopSetting('opd_consultation_variant_id', null);

        Livewire::test(ShopSettings::class)
            ->assertFormSet([
                'rx_prescriber' => 'required',
                'default_prescriber' => null,
                'controlled_register' => false,
                'bank_transfer_reference_required' => true,
                'till_clock_tolerance_minutes' => 10,
            ])
            ->assertSee('The clinic doctor, pre-filled as the prescriber.')
            ->fillForm([
                'default_prescriber' => '  Dr. S. Perera ',
                'rx_prescriber' => 'prompt',
                'controlled_register' => true,
                'opd_consultation_variant_id' => $fee->getKey(),
                'bank_transfer_reference_required' => false,
                'till_clock_tolerance_minutes' => 30,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $shop = $this->shop->fresh();

        $this->assertSame('Dr. S. Perera', $shop->setting('default_prescriber'));
        $this->assertSame('prompt', $shop->setting('rx_prescriber'));
        $this->assertTrue($shop->setting('controlled_register'));
        $this->assertSame((int) $fee->getKey(), $shop->setting('opd_consultation_variant_id'));
        // The OPD receipt's medicines moved to Receipt settings (branch 9): untouched here.
        $this->assertSame('names', $shop->setting('opd_receipt_medicines'));
        $this->assertFalse($shop->setting('bank_transfer_reference_required'));
        $this->assertSame(30, $shop->setting('till_clock_tolerance_minutes'));
        // Merged into the settings: the others stay.
        $this->assertSame(90, $shop->setting('near_expiry_warning_days'));

        // Clearing the consultation item and the prescriber.
        Livewire::test(ShopSettings::class)
            ->assertFormSet(['opd_consultation_variant_id' => $fee->getKey(), 'default_prescriber' => 'Dr. S. Perera'])
            ->fillForm(['opd_consultation_variant_id' => null, 'default_prescriber' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($this->shop->fresh()->setting('opd_consultation_variant_id'));
        $this->assertNull($this->shop->fresh()->setting('default_prescriber'));
    }

    public function test_pharmacy_checkout_settings_are_validated(): void
    {
        $tablets = $this->medicine();

        Livewire::test(ShopSettings::class)
            ->fillForm(['till_clock_tolerance_minutes' => 0, 'rx_prescriber' => 'sometimes'])
            ->call('save')
            ->assertHasFormErrors(['till_clock_tolerance_minutes', 'rx_prescriber']);

        Livewire::test(ShopSettings::class)
            ->fillForm(['till_clock_tolerance_minutes' => 1441])
            ->call('save')
            ->assertHasFormErrors(['till_clock_tolerance_minutes']);

        Livewire::test(ShopSettings::class)
            ->fillForm(['till_clock_tolerance_minutes' => 2.5])
            ->call('save')
            ->assertHasFormErrors(['till_clock_tolerance_minutes']);

        // A medicine (it tracks stock) can't be the consultation line.
        Livewire::test(ShopSettings::class)
            ->fillForm(['opd_consultation_variant_id' => $tablets->getKey()])
            ->call('save');

        $shop = $this->shop->fresh();
        $this->assertNull($shop->setting('opd_consultation_variant_id'));
        $this->assertSame(10, $shop->setting('till_clock_tolerance_minutes'));
        $this->assertSame('required', $shop->setting('rx_prescriber'));
    }

    // The sale page

    /**
     * A sale written as CheckoutService records it (built from models, so the
     * page is tested apart from the checkout rules).
     *
     * @param  array<string, mixed>  $sale
     * @param  list<array{variant: ProductVariant, quantity: int, unit_price: int, line_total: int}&array<string, mixed>>  $lines
     */
    private function recordSale(array $sale, array $lines, ?string $reference = null): Sale
    {
        $total = array_sum(array_map(fn (array $line): int => $line['line_total'], $lines));

        $model = new Sale;
        $model->forceFill([
            'uuid' => (string) Str::uuid(),
            'location_id' => $this->doctors->getKey(),
            'device_id' => $this->docTill()->getKey(),
            'user_id' => $this->cashier->getKey(),
            'price_list_id' => $this->shop->defaultPriceList()->getKey(),
            'invoice_no' => 'T2-'.Str::padLeft((string) (Sale::query()->count() + 1), 6, '0'),
            'subtotal' => $total,
            'grand_total' => $total,
            'paid_total' => $total,
            'sold_at' => now(),
            ...$sale,
        ])->save();

        foreach ($lines as $line) {
            /** @var ProductVariant $variant */
            $variant = $line['variant'];
            unset($line['variant']);

            $model->items()->create([
                'product_variant_id' => $variant->getKey(),
                'location_id' => $this->doctors->getKey(),
                'description' => $variant->product->name,
                'sku' => $variant->sku,
                'unit_name' => $variant->product->unit,
                'unit_factor' => 1,
                'base_quantity' => $line['quantity'],
                ...$line,
            ]);
        }

        $model->payments()->create(['method' => $reference === null ? PaymentMethod::Cash : PaymentMethod::BankTransfer, 'amount' => $total, 'reference' => $reference]);

        return $model;
    }

    public function test_the_sale_page_shows_an_opd_bill_its_prescriptions_and_the_tills_clock(): void
    {
        $fee = $this->consultation();
        $amoxil = $this->medicine('Amoxil 250 mg Capsules', schedule: DrugSchedule::PrescriptionOnly);

        // An OPD bill (fee typed at the till, the capsules' share of the Medicines amount)
        // synced from a till whose clock was 12 minutes ahead.
        $sale = $this->recordSale([
            'bill_type' => BillType::Opd,
            'opd_medicines_amount' => 35_000,
            'customer_name' => 'Nimal Silva',
            'clock_offset_seconds' => 720,
            'reported_at' => now()->subMinutes(12),
            'synced_at' => now(),
            'needs_review' => true,
            'review_reasons' => [
                ['type' => 'clock', 'message' => "The till's clock was 12 minutes ahead."],
                ['type' => 'rx_missing', 'sku' => $amoxil->sku, 'message' => 'Amoxil 250 mg Capsules is prescription only and was sold without a prescriber.'],
                ['type' => 'opd', 'message' => 'OPD bill from a till that does not take OPD bills.'],
                ['type' => 'price_override', 'sku' => $fee->sku, 'message' => "Consultation's price was changed at the till by a cashier who may not."],
            ],
        ], [
            ['variant' => $fee, 'quantity' => 1, 'unit_price' => 200_000, 'list_price' => 150_000, 'line_total' => 200_000],
            [
                'variant' => $amoxil, 'quantity' => 10, 'unit_price' => 2_000, 'list_price' => 2_000, 'line_total' => 35_000, 'is_opd_medicine' => true,
                'drug_schedule' => DrugSchedule::PrescriptionOnly, 'prescriber' => 'Dr. S. Perera', 'prescription_ref' => 'RX-0042',
            ],
        ], reference: 'BOC-778812');

        Livewire::test(ViewSale::class, ['record' => $sale->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('OPD bill')
            ->assertSee('Medicines amount')
            ->assertSee($this->shop->formatMoney(35_000))
            ->assertSee('Patient')
            ->assertSee('Nimal Silva')
            ->assertSee('12 minutes ahead')
            ->assertSee('Counted in reports at')
            ->assertSee('Prescription only')
            ->assertSee('Dr. S. Perera')
            ->assertSee('RX-0042')
            ->assertSee('Share of the Medicines amount (list price '.$this->shop->formatMoney(2_000).')')
            ->assertSee('Price changed at the till (list price '.$this->shop->formatMoney(150_000).')')
            ->assertSee('BOC-778812')
            ->assertSee("The till's clock was 12 minutes ahead.")
            ->assertSee('Amoxil 250 mg Capsules is prescription only and was sold without a prescriber.')
            ->assertSee('OPD bill from a till that does not take OPD bills.')
            ->assertSee("Consultation's price was changed at the till by a cashier who may not.");
    }

    public function test_a_standard_sale_page_shows_none_of_it(): void
    {
        $tablets = $this->medicine();
        $sale = $this->recordSale([], [['variant' => $tablets, 'quantity' => 2, 'unit_price' => 2_000, 'line_total' => 4_000, 'drug_schedule' => DrugSchedule::Otc]]);

        $this->assertSame(BillType::Standard, $sale->fresh()->bill_type);
        $this->assertTrue($sale->fresh()->reported_at->equalTo($sale->sold_at));

        Livewire::test(ViewSale::class, ['record' => $sale->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Over the counter')
            ->assertDontSee('OPD bill')
            ->assertDontSee('Medicines amount')
            ->assertDontSee("Till's clock")
            ->assertDontSee('Counted in reports at')
            ->assertDontSee('Price changed at the till')
            ->assertDontSee('Prescriber')
            ->assertDontSee('Rx ref');
    }

    public function test_the_sale_page_says_how_far_out_the_tills_clock_was(): void
    {
        $this->assertSame('12 minutes ahead', SaleResource::clockOffset(720));
        $this->assertSame('45 seconds behind', SaleResource::clockOffset(-45));
        $this->assertSame('1 minute ahead', SaleResource::clockOffset(80));
        $this->assertSame('3 hours behind', SaleResource::clockOffset(-3 * 3600));
        $this->assertSame('2 days ahead', SaleResource::clockOffset(2 * 86400));
        $this->assertSame('', SaleResource::chargedNote(new SaleItem(['unit_price' => 100, 'list_price' => null])));
    }
}
