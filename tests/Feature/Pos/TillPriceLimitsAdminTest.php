<?php

namespace Tests\Feature\Pos;

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\ProductFormData;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductVariant;
use Database\Seeders\DemoClinicTillPricesSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * Prices changed at the till, the product form (docs/PHARMACY.md, branch
 * 14): any non-medicine may allow it, stock items too; a medicine never.
 * The highest price is required, the lowest optional, and the selling price
 * lies between them.
 */
class TillPriceLimitsAdminTest extends BatchTestCase
{
    use WorksWithCheckout;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);
    }

    public function test_a_non_medicine_stock_item_saves_its_till_price_limits_and_ask_for_price(): void
    {
        Livewire::test(CreateProduct::class)
            ->set('data.is_medicine', false)
            ->assertFormFieldIsVisible('allow_price_override')
            ->assertFormFieldIsHidden('ask_price_at_till')
            ->fillForm(['name' => 'Wound dressing', 'unit' => 'pcs', 'track_stock' => true, 'allow_price_override' => true, 'ask_price_at_till' => true])
            ->assertFormFieldIsVisible('ask_price_at_till')
            ->fillForm(['variants' => [['price' => '500', 'till_price_min' => '200', 'till_price_max' => '1500.50']]])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::query()->where('name', 'Wound dressing')->with('drugDetail')->firstOrFail();
        $variant = $product->variants()->firstOrFail();

        $this->assertTrue($product->track_stock);
        $this->assertTrue($product->allowsPriceOverride());
        $this->assertTrue($product->asksPriceAtTill());
        $this->assertSame(20_000, $variant->till_price_min);
        $this->assertSame(150_050, $variant->till_price_max);

        // Edit shows them as entered, and the collapsed section says so.
        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertFormSet(['allow_price_override' => true, 'ask_price_at_till' => true])
            ->assertSee('Price can be changed at the till')
            ->assertSet('data.variants', fn (array $rows): bool => (float) collect($rows)->first()['till_price_min'] === 200.0
                && (float) collect($rows)->first()['till_price_max'] === 1500.5);
    }

    public function test_the_max_is_required_and_the_selling_price_lies_between_the_limits(): void
    {
        $form = fn (array $variant) => Livewire::test(CreateProduct::class)
            ->set('data.is_medicine', false)
            ->fillForm(['name' => 'CBS', 'unit' => 'Test', 'track_stock' => false, 'allow_price_override' => true])
            ->fillForm(['variants' => [['price' => '1000', ...$variant]]])
            ->call('create');

        $form([])->assertHasFormErrors()->assertSee('Enter the highest price the cashier may type.');
        $form(['till_price_max' => '900'])->assertSee('The max till price can&#039;t be less than the selling price.', false);
        $form(['till_price_max' => '0'])->assertSee('The max till price must be more than zero.');
        $form(['till_price_max' => '2000', 'till_price_min' => '1200'])->assertSee('The min till price can&#039;t be more than the selling price.', false);
        $this->assertFalse(Product::query()->where('name', 'CBS')->exists());

        $form(['till_price_max' => '2000', 'till_price_min' => '1000'])->assertHasNoFormErrors();
        $variant = Product::query()->where('name', 'CBS')->firstOrFail()->variants()->firstOrFail();
        $this->assertSame([100_000, 200_000], [$variant->till_price_min, $variant->till_price_max]);
    }

    public function test_switched_off_the_limits_are_not_asked_for(): void
    {
        Livewire::test(CreateProduct::class)
            ->set('data.is_medicine', false)
            ->fillForm(['name' => 'Cotton wool', 'unit' => 'pcs', 'track_stock' => true, 'allow_price_override' => false, 'variants' => [['price' => '150']]])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::query()->where('name', 'Cotton wool')->with('drugDetail')->firstOrFail();
        $this->assertFalse($product->allowsPriceOverride());
        $this->assertNull($product->variants()->firstOrFail()->till_price_max);
    }

    public function test_a_medicine_can_never_allow_it(): void
    {
        // Hidden on a medicine.
        Livewire::test(CreateProduct::class)
            ->assertFormSet(['is_medicine' => true])
            ->assertFormFieldIsHidden('allow_price_override')
            ->assertFormFieldIsHidden('ask_price_at_till');

        // Saved off for a medicine whatever the form sends.
        $input = ProductFormData::toServiceInput([
            'name' => 'Panadol', 'unit' => 'Tablet', 'track_stock' => true, 'is_medicine' => true,
            'allow_price_override' => true, 'ask_price_at_till' => true,
            'drug' => ['generic_names' => ['Paracetamol'], 'dosage_form' => 'tablet', 'schedule' => 'otc'],
            'variants' => [],
        ]);
        $this->assertFalse($input['product']['allow_price_override']);
        $this->assertFalse($input['product']['ask_price_at_till']);

        // And a medicine whose flag is on in the database still can't take a typed price.
        $panadol = $this->syrup();
        $panadol->product->update(['allow_price_override' => true, 'ask_price_at_till' => true]);
        $product = $panadol->product->fresh(['drugDetail']);
        $this->assertFalse($product?->allowsPriceOverride());
        $this->assertFalse($product?->asksPriceAtTill());

        // Turning a non-medicine into a medicine switches it off.
        $dressing = $this->dressing();
        $input = ProductFormData::toServiceInput([
            ...ProductFormData::fromProduct($dressing->product->fresh()),
            'is_medicine' => true,
            'drug' => ['generic_names' => ['Gauze'], 'dosage_form' => 'tablet', 'schedule' => 'otc'],
        ]);
        $this->assertFalse($input['product']['allow_price_override']);
    }

    public function test_only_a_user_who_sets_prices_sees_and_changes_the_limits(): void
    {
        $dressing = $this->dressing();

        Livewire::test(EditProduct::class, ['record' => $dressing->product->getRouteKey()])
            ->assertSee('Max till price');

        // The limits are audited with the variant.
        $dressing->update(['till_price_max' => 300_000]);
        $this->assertTrue(AuditLog::query()
            ->where('auditable_type', $dressing->getMorphClass())
            ->where('auditable_id', $dressing->getKey())
            ->get()
            ->contains(fn (AuditLog $log): bool => ($log->new_values['till_price_max'] ?? null) === 300_000));
    }

    public function test_the_demo_seeder_sets_the_consultations_max_and_adds_a_dressing_and_a_lab_test(): void
    {
        $fee = $this->consultation(150_000);

        $this->seed(DemoClinicTillPricesSeeder::class);

        $this->assertSame(500_000, $fee->fresh()?->till_price_max);
        $this->assertSame(150_000, $fee->fresh()?->priceOn(), 'The fee itself is unchanged.');

        $dressing = Product::query()->where('name', 'Wound dressing')->with('drugDetail')->firstOrFail();
        $this->assertTrue($dressing->allowsPriceOverride());
        $this->assertSame([20_000, 150_000], [$dressing->variants()->firstOrFail()->till_price_min, $dressing->variants()->firstOrFail()->till_price_max]);
        $this->assertSame(200.0, $dressing->variants()->firstOrFail()->stockAt($this->general) + $dressing->variants()->firstOrFail()->stockAt($this->doctors));

        $cbs = Product::query()->where('name', 'CBS (full blood count)')->with('drugDetail')->firstOrFail();
        $this->assertTrue($cbs->asksPriceAtTill());
        $this->assertNull($cbs->variants()->firstOrFail()->till_price_min);
    }

    private function dressing(): ProductVariant
    {
        return $this->openPriceItem('Wound dressing', 50_000, max: 150_000, min: 20_000, tracksStock: true);
    }
}
