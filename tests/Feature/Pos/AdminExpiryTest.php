<?php

namespace Tests\Feature\Pos;

use App\Filament\Resources\ExpiringStock\ExpiringStockResource;
use App\Filament\Resources\ExpiringStock\Pages\ListExpiringStock;
use App\Filament\Resources\StockWriteOffs\Pages\ListStockWriteOffs;
use App\Filament\Resources\StockWriteOffs\Pages\ViewStockWriteOff;
use App\Filament\Resources\SupplierReturns\Pages\CreateSupplierReturn;
use App\Filament\Resources\SupplierReturns\SupplierReturnResource;
use App\Models\Location;
use App\Models\StockTransfer;
use App\Models\StockWriteOff;
use App\Services\Data\ReceiptLine;
use App\Services\ExpiryService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithPurchasing;

/**
 * The Expiry screen (docs/PHARMACY.md §9, branch 10): the two lists as tabs
 * with their badges, days left and value at cost, and what can be done with
 * selected batches: write off (owner), move to a quarantine location, or
 * start a supplier return filled in from them.
 */
class AdminExpiryTest extends BatchTestCase
{
    use WorksWithPurchasing;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);
    }

    public function test_the_tabs_list_expiring_and_expired_batches_with_days_left_and_value(): void
    {
        $panadol = $this->medicine();
        $soon = $this->receive($panadol, 'SOON', '2026-12-31', 10, cost: 1_000);
        $later = $this->receive($panadol, 'LATER', '03/2028', 10);
        $old = $this->foundExpired($panadol, 'OLD', '2026-10-15', 2);

        $this->get(ExpiringStockResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Expiring within 90 days')
            ->assertSee('74 days')
            ->assertSee('LKR 100.00');

        Livewire::test(ListExpiringStock::class)
            ->assertCanSeeTableRecords([$soon])
            ->assertCanNotSeeTableRecords([$later, $old])
            ->set('activeTab', 'expired')
            ->assertCanSeeTableRecords([$old])
            ->assertCanNotSeeTableRecords([$soon])
            ->assertSee('Expired 3 days ago');

        $this->assertSame('1', ExpiringStockResource::getNavigationBadge());
    }

    public function test_the_screen_is_for_pharmacies_and_costs_for_cost_view_only(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'SOON', '2026-12-31', 10, cost: 1_000);

        $this->cashier->givePermissionTo('admin.access');

        $this->actingAs($this->cashier)->get(ExpiringStockResource::getUrl('index'))
            ->assertOk()
            ->assertSee('SOON')
            ->assertDontSee('LKR 100.00')
            ->assertDontSee('Write off');
    }

    public function test_selected_batches_are_written_off_and_the_note_opens(): void
    {
        $panadol = $this->medicine();
        $old = $this->foundExpired($panadol, 'OLD', '2026-10-15', 2);
        $older = $this->foundExpired($panadol, 'OLDER', '2026-09-30', 3);

        Livewire::test(ListExpiringStock::class, ['activeTab' => 'expired'])
            ->callTableBulkAction('writeOff', [$old, $older], data: ['reason' => 'expired', 'note' => 'Burnt'])
            ->assertHasNoTableBulkActionErrors()
            ->assertRedirect();

        $note = StockWriteOff::query()->sole();
        $this->assertSame(5_000, $note->total);
        $this->assertSame([], $this->batchQuantities($panadol));

        Livewire::test(ViewStockWriteOff::class, ['record' => $note->getRouteKey()])->assertOk()->assertSee('OLDER');
        Livewire::test(ListStockWriteOffs::class)->assertCanSeeTableRecords([$note]);
        $this->assertLedgerMatches();
    }

    public function test_selected_batches_move_to_a_quarantine_location_that_does_not_sell(): void
    {
        $panadol = $this->medicine();
        $soon = $this->receive($panadol, 'SOON', '2026-12-31', 10);
        $docSoon = $this->receive($panadol, 'SOON', '2026-12-31', 4, $this->doctors);
        $quarantine = Location::query()->where('code', 'QUAR')->sole(); // the pharmacy profile's Quarantine / Expired

        Livewire::test(ListExpiringStock::class)
            ->callTableBulkAction('move', [$soon, $docSoon], data: ['to_location_id' => $quarantine->getKey(), 'note' => 'Short expiry'])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame(2, StockTransfer::query()->where('to_location_id', $quarantine->getKey())->count());
        $this->assertSame([], $this->batchQuantities($panadol));
        $this->assertSame(['SOON' => 14.0], $this->batchQuantities($panadol, $quarantine));
        $this->assertFalse(Location::query()->findOrFail($quarantine->getKey())->is_sellable);
        // Still on the list, at the quarantine location, until it is written off or returned.
        $this->assertSame(1, app(ExpiryService::class)->query(ExpiryService::SOON, (int) $quarantine->getKey())->count());
        $this->assertLedgerMatches();
    }

    public function test_a_supplier_return_starts_filled_in_from_the_selected_batches(): void
    {
        $supplier = $this->supplier();
        $panadol = $this->medicine();
        $this->postReceipt($supplier, [new ReceiptLine($panadol->getKey(), 10, unitCost: 1_000, batchNo: 'SOON', expiry: '2026-12-31', acceptShortExpiry: true)]);
        $soon = $this->batch($panadol, 'SOON');

        Livewire::test(ListExpiringStock::class)
            ->callTableBulkAction('supplierReturn', [$soon])
            ->assertRedirect(SupplierReturnResource::getUrl('create', ['batches' => [$soon->getKey()]]));

        $this->get(SupplierReturnResource::getUrl('create', ['batches' => [$soon->getKey()]]))->assertOk();

        Livewire::withQueryParams(['batches' => [$soon->getKey()]])
            ->test(CreateSupplierReturn::class)
            ->assertSet('data.supplier_id', $supplier->getKey())
            ->assertSet('data.location_id', $this->general->getKey())
            ->assertSet('data.reason', 'near_expiry')
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame([], $this->batchQuantities($panadol));
    }

    public function test_bulk_actions_need_their_permissions(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'SOON', '2026-12-31', 10);
        $this->cashier->givePermissionTo('admin.access');
        $this->actingAs($this->cashier);

        Livewire::test(ListExpiringStock::class)
            ->assertTableBulkActionHidden('writeOff')
            ->assertTableBulkActionHidden('move')
            ->assertTableBulkActionHidden('supplierReturn');
    }
}
