<?php

namespace Tests\Feature\Pos;

use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\ProductFormData;
use App\Filament\Resources\VehicleMakes\VehicleMakeResource;
use App\Models\Product;
use App\Models\VehicleModel;
use App\Services\ModuleService;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;

/**
 * vehicle_fitment guards every vehicle screen and field. It is on for the
 * retail demo shop (the rest of the suite runs with it on); these tests
 * switch it off.
 */
class VehicleFitmentModuleTest extends AdminTestCase
{
    private function switchOff(): void
    {
        app(ModuleService::class)->disable($this->shop, 'vehicle_fitment');
    }

    private function search(array $query): TestResponse
    {
        return $this->actingAs($this->cashier)->getJson(route('pos.search', ['device' => $this->till->uuid, ...$query]));
    }

    private function plug(): Product
    {
        return Product::query()->where('part_number', 'CPR8EA-9')->firstOrFail();
    }

    public function test_it_is_on_for_the_retail_demo_shop(): void
    {
        $this->assertTrue($this->shop->hasModule('vehicle_fitment'));

        $this->actingAs($this->cashier)->getJson(route('pos.vehicles'))->assertOk()->assertJsonPath('makes.0.name', 'Bajaj');
        $this->actingAs($this->cashier)->get(route('pos.index'))
            ->assertInertia(fn (Assert $page) => $page->where('features.vehicleFitment', true));
    }

    public function test_when_off_the_till_gets_no_vehicle_list_and_hides_the_filter(): void
    {
        $this->switchOff();

        $this->actingAs($this->cashier)->getJson(route('pos.vehicles'))->assertNotFound();
        $this->actingAs($this->cashier)->get(route('pos.index'))
            ->assertInertia(fn (Assert $page) => $page->where('features.vehicleFitment', false));
    }

    public function test_when_off_search_ignores_a_vehicle_filter(): void
    {
        $pulsar = VehicleModel::query()->where('name', 'Pulsar 150')->firstOrFail();
        $this->assertNotEmpty($this->search(['model_id' => $pulsar->getKey()])->json('results'));

        $this->switchOff();

        $this->search(['model_id' => $pulsar->getKey()])->assertOk()->assertJsonPath('results', []);

        // Text search still works, and the filter does not narrow it.
        $helmets = $this->search(['q' => 'helmet', 'model_id' => $pulsar->getKey()])->assertOk()->json('results');
        $this->assertNotEmpty($helmets);
    }

    public function test_when_off_the_offline_catalog_carries_no_fitments_or_vehicles(): void
    {
        app(ModuleService::class)->enable($this->shop, 'offline_sync');
        $token = $this->till->issueSyncToken('Front counter browser')->plainTextToken;
        $this->switchOff();

        $response = $this->withToken($token)->getJson(route('pos.sync.catalog'))->assertOk()->assertJsonPath('vehicles', null);

        $this->assertNotEmpty($response->json('items'));

        foreach ($response->json('items') as $item) {
            $this->assertSame([], $item['fitments']);
        }
    }

    public function test_when_off_the_admin_vehicle_screen_is_gone(): void
    {
        $this->actingAs($this->owner);
        $this->assertTrue(VehicleMakeResource::canAccess());

        $this->switchOff();

        $this->assertFalse(VehicleMakeResource::canAccess());
        $this->get(route('filament.admin.resources.vehicle-makes.index'))->assertForbidden();
        $this->get(route('filament.admin.pages.dashboard'))->assertSuccessful()->assertDontSee('Vehicles');
    }

    public function test_the_product_form_shows_the_fitment_field_only_when_on(): void
    {
        $this->actingAs($this->owner);
        $plug = $this->plug();

        Livewire::test(EditProduct::class, ['record' => $plug->getRouteKey()])->assertFormFieldVisible('fitment_ids');

        $this->switchOff();

        Livewire::test(EditProduct::class, ['record' => $plug->getRouteKey()])->assertFormFieldHidden('fitment_ids');
    }

    public function test_saving_a_product_while_off_keeps_its_fitments(): void
    {
        $this->actingAs($this->owner);
        $plug = $this->plug();
        $fitments = $plug->fitments()->pluck('vehicle_models.id')->sort()->values()->all();
        $this->assertNotEmpty($fitments);

        $this->switchOff();

        Livewire::test(EditProduct::class, ['record' => $plug->getRouteKey()])
            ->fillForm(['name' => 'NGK Spark Plug (CPR8EA-9)', 'variants' => ProductFormData::fromProduct($plug)['variants']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('NGK Spark Plug (CPR8EA-9)', $plug->fresh()?->name);
        $this->assertSame($fitments, $plug->fitments()->pluck('vehicle_models.id')->sort()->values()->all());
    }
}
