<?php

namespace Tests\Feature\Pos;

use App\Exceptions\PosException;
use App\Filament\Pages\Reports\PriceChangesReport;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Models\Product;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\ItemSearchService;
use App\Services\ProductCatalogService;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Prices changed at the till (branch 14) leave a retail shop (pharmacy off)
 * as it was: only a service may take a typed price, with no limit asked for
 * and none applied, no "Ask for price", and no report.
 */
class RetailTillPricesUnchangedTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);
    }

    public function test_a_retail_stock_item_still_never_takes_a_typed_price(): void
    {
        $plug = $this->variant('CPR8EA-9');
        // Even with its flag on in the database (the form never saves it for a stock item).
        $plug->product->update(['allow_price_override' => true, 'ask_price_at_till' => true]);

        $this->assertFalse($plug->product->fresh()?->allowsPriceOverride());
        $this->assertFalse($plug->product->fresh()?->asksPriceAtTill());

        try {
            app(CheckoutService::class)->checkout($this->cart([new CartLine($plug->getKey(), 1, unitPriceOverride: 100)]));
            $this->fail('A typed price on a retail stock item was accepted.');
        } catch (PosException $exception) {
            $this->assertSame("{$plug->sku}'s price can't be changed at the till.", $exception->getMessage());
        }
    }

    public function test_a_retail_service_takes_any_price_above_zero_with_no_limits_or_ask_for_price(): void
    {
        Livewire::test(CreateProduct::class)
            ->fillForm(['name' => 'Helmet fitting', 'unit' => 'job', 'track_stock' => false, 'allow_price_override' => true, 'variants' => [['price' => '500']]])
            ->assertFormFieldIsVisible('allow_price_override')
            ->assertFormFieldIsHidden('ask_price_at_till')
            ->assertDontSee('Max till price')
            ->call('create')
            ->assertHasNoFormErrors();

        $fitting = Product::query()->where('name', 'Helmet fitting')->firstOrFail();
        $this->assertFalse($fitting->asksPriceAtTill());

        $sale = app(CheckoutService::class)->checkout($this->cart([new CartLine($fitting->variants()->firstOrFail()->getKey(), 1, unitPriceOverride: 9_999_900)]));
        $this->assertSame(9_999_900, $sale->grand_total);
        $this->assertSame((int) $this->cashier->getKey(), $sale->items->sole()->price_changed_by);

        $this->assertFalse(PriceChangesReport::canAccess());
    }

    public function test_the_retail_till_payload_has_no_limits(): void
    {
        app(ProductCatalogService::class)->create(['name' => 'Chain lube service', 'unit' => 'job', 'track_stock' => false, 'allow_price_override' => true], [['price' => 50_000]], user: $this->owner);

        $item = app(ItemSearchService::class)->search($this->till, 'Chain lube')['results'][0];

        $this->assertTrue($item['allow_price_override']);
        $this->assertSame([null, null, false], [$item['price_min'], $item['price_max'], $item['ask_price']]);
    }
}
