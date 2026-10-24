<?php

namespace Tests\Feature\Pos;

use App\Enums\DosageForm;
use App\Enums\DrugSchedule;
use App\Enums\StockMovementType;
use App\Exceptions\PosException;
use App\Filament\Resources\StockTransfers\Pages\CreateStockTransfer;
use App\Filament\Resources\StockTransfers\Pages\ListStockTransfers;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\Data\BatchInput;
use App\Services\Data\DrugData;
use App\Services\Data\TransferLine;
use App\Services\LocationService;
use App\Services\ProductCatalogService;
use App\Services\StockService;
use App\Services\StockTransferService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

/** Transfers between locations: paired ledger entries at the source location's average cost. */
class StockTransferTest extends PharmacyTestCase
{
    private ProductVariant $panadol;

    protected function setUp(): void
    {
        parent::setUp();

        // 100 tablets at GEN at 1,000; 10 at DOC at 1,300. Box of 10.
        $this->panadol = app(ProductCatalogService::class)->create(
            ['name' => 'Panadol 500 mg Tablets', 'unit' => 'Tablet'],
            [['price' => 2_000, 'cost' => 1_000, 'opening_stock' => 100, 'opening_batch' => new BatchInput('PN1', '12/2028'), 'packs' => [['name' => 'Box', 'factor' => 10, 'price' => 18_000]]]],
            user: $this->owner,
            drug: new DrugData(['Paracetamol'], DosageForm::Tablet, DrugSchedule::Otc),
        )->variants()->firstOrFail();

        app(StockService::class)->stockIn($this->panadol, $this->doctors, 10, $this->owner, 1_300, batch: new BatchInput('PN2', '06/2029'));
    }

    private function box(): int
    {
        return (int) $this->panadol->units()->where('name', 'Box')->value('id');
    }

    /** @param  list<TransferLine>  $lines */
    private function transfer(array $lines, ?Location $from = null, ?Location $to = null, ?User $user = null): StockTransfer
    {
        return app(StockTransferService::class)->create($from ?? $this->general, $to ?? $this->doctors, $lines, $user ?? $this->owner, 'Doctor ran short');
    }

    public function test_a_transfer_posts_paired_movements_at_the_source_average(): void
    {
        $transfer = $this->transfer([new TransferLine($this->panadol->getKey(), 2, $this->box())]);

        $this->assertSame('TR-000001', $transfer->transfer_number);
        $this->assertSame($this->owner->getKey(), $transfer->user_id);
        $this->assertSame('Doctor ran short', $transfer->note);

        $line = $transfer->items->sole();
        $this->assertSame(['Box', 2.0, 10.0, 20.0, '1000.0000'], [$line->unit_name, (float) $line->quantity, (float) $line->unit_factor, (float) $line->base_quantity, $line->unit_cost]);

        $this->assertSame(80.0, $this->panadol->stockAt($this->general));
        $this->assertSame(30.0, $this->panadol->stockAt($this->doctors));

        // A medicine moves batch by batch: first expiry first out, the same batch arriving at DOC.
        $moved = $line->batches->sole();
        $this->assertSame(['PN1', 20.0], [$moved->batch_no, (float) $moved->quantity]);
        $out = StockMovement::query()->findOrFail($moved->out_movement_id);
        $in = StockMovement::query()->findOrFail($moved->in_movement_id);
        $this->assertSame([StockMovementType::TransferOut, $this->general->getKey(), -20.0, 80.0, 1_000], [$out->type, $out->location_id, (float) $out->quantity, (float) $out->balance_after, $out->unit_cost]);
        $this->assertSame([StockMovementType::TransferIn, $this->doctors->getKey(), 20.0, 30.0, 1_000], [$in->type, $in->location_id, (float) $in->quantity, (float) $in->balance_after, $in->unit_cost]);
        $this->assertTrue($out->reference()->firstOrFail()->is($transfer));
        $this->assertTrue($in->reference()->firstOrFail()->is($transfer));

        // The source keeps its average; the destination re-averages with it:
        // (10 x 1,300 + 20 x 1,000) / 30 = 1,100.
        $stock = app(StockService::class);
        $this->assertSame('1000.0000', $stock->averageCost($this->panadol, $this->general));
        $this->assertSame('1100.0000', $stock->averageCost($this->panadol, $this->doctors));
        $this->assertSame(1_027, $this->panadol->fresh()->cost_price, 'A transfer moves value, it does not change the blend.');

        $this->assertSame('TR-000002', $this->transfer([new TransferLine($this->panadol->getKey(), 5)])->transfer_number);

        Artisan::call('pos:stock-rebuild');
        $this->assertStringContainsString('all stock levels match', Artisan::output());
    }

    public function test_a_transfer_cannot_take_more_than_the_source_has(): void
    {
        try {
            $this->transfer([new TransferLine($this->panadol->getKey(), 11)], from: $this->doctors, to: $this->general);
            $this->fail('A transfer took more than was on hand.');
        } catch (PosException $exception) {
            $this->assertStringContainsString('Not enough stock', $exception->getMessage());
        }

        $this->assertSame(0, StockTransfer::query()->count());
        $this->assertSame(0, StockMovement::query()->whereIn('type', [StockMovementType::TransferIn, StockMovementType::TransferOut])->count());
        $this->assertSame(10.0, $this->panadol->stockAt($this->doctors));
        $this->assertSame(100.0, $this->panadol->stockAt($this->general));
    }

    public function test_transfers_check_units_locations_and_permission(): void
    {
        $refused = function (callable $attempt, string $message): void {
            try {
                $attempt();
                $this->fail("Expected: {$message}");
            } catch (PosException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        };

        $refused(fn () => $this->transfer([new TransferLine($this->panadol->getKey(), 0.5, $this->box())]), 'whole units only');
        $refused(fn () => $this->transfer([new TransferLine($this->panadol->getKey(), 1)], to: $this->general), 'two different locations');
        $refused(fn () => $this->transfer([]), 'at least one item');

        $store = app(LocationService::class)->create(['name' => 'Back store', 'code' => 'STORE'], $this->owner);
        app(LocationService::class)->update($store, ['name' => 'Back store', 'code' => 'STORE', 'is_active' => false], $this->owner);
        $refused(fn () => $this->transfer([new TransferLine($this->panadol->getKey(), 1)], to: $store->fresh()), 'not active');

        // Owner only: cashiers and a manager without stock.transfer are refused.
        $this->assertNotContains('stock.transfer', config('pos.roles.cashier'));
        $refused(fn () => $this->transfer([new TransferLine($this->panadol->getKey(), 1)], user: $this->cashier), 'permission');

        $manager = User::factory()->create(['email' => 'manager@clinic.test']);
        $manager->forceFill(['shop_id' => $this->shop->getKey()])->save();
        $manager->givePermissionTo(['admin.access', 'stock.view', 'stock.receive']);
        $refused(fn () => $this->transfer([new TransferLine($this->panadol->getKey(), 1)], user: $manager), 'permission');

        $this->assertSame(0, StockTransfer::query()->count());
    }

    public function test_the_owner_transfers_from_the_admin_screen(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);

        Livewire::test(CreateStockTransfer::class)
            ->assertFormSet(['from_location_id' => $this->general->getKey()])
            ->fillForm([
                'from_location_id' => $this->general->getKey(),
                'to_location_id' => $this->doctors->getKey(),
                'note' => 'Weekly top-up',
                'lines' => [['variant_id' => $this->panadol->getKey(), 'unit_id' => $this->box(), 'quantity' => 3]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $transfer = StockTransfer::query()->sole();
        $this->assertSame(30.0, (float) $transfer->items->sole()->base_quantity);
        $this->assertSame(70.0, $this->panadol->stockAt($this->general));

        $this->get(StockTransferResource::getUrl('view', ['record' => $transfer]))
            ->assertOk()
            ->assertSee('TR-000001')
            ->assertSee("Doctor's Medicines")
            ->assertSee('Weekly top-up');

        Livewire::test(ListStockTransfers::class)->assertCanSeeTableRecords([$transfer]);

        // More than on hand: refused with a message, nothing posted.
        Livewire::test(CreateStockTransfer::class)
            ->fillForm([
                'from_location_id' => $this->doctors->getKey(),
                'to_location_id' => $this->general->getKey(),
                'lines' => [['variant_id' => $this->panadol->getKey(), 'unit_id' => null, 'quantity' => 500]],
            ])
            ->call('create')
            ->assertNotified();

        $this->assertSame(1, StockTransfer::query()->count());
    }

    public function test_people_without_stock_transfer_can_read_but_not_make_transfers(): void
    {
        Filament::setCurrentPanel('admin');

        $manager = User::factory()->create(['email' => 'manager@clinic.test']);
        $manager->forceFill(['shop_id' => $this->shop->getKey()])->save();
        $manager->givePermissionTo(['admin.access', 'stock.view']);
        $this->actingAs($manager);

        $this->get(StockTransferResource::getUrl('index'))->assertOk();
        $this->get(StockTransferResource::getUrl('create'))->assertForbidden();
    }
}
