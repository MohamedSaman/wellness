<?php

namespace Tests\Feature\Pos;

use App\Filament\Resources\Devices\Pages\ManageDevices;
use App\Filament\Resources\Sales\Pages\ListSales;
use App\Filament\Resources\Sales\Pages\ViewSale;
use App\Models\Sale;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\OfflineSaleSyncService;
use Illuminate\Support\Str;
use Livewire\Livewire;

class AdminOfflineSyncTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The flagged sale's time is checked against the clock; keep it still.
        $this->freezeSecond();
    }

    /** An offline sale sold below today's price list, so it is flagged. */
    private function flaggedSale(): Sale
    {
        $plug = $this->variant('CPR8EA-9');
        $uuid = (string) Str::uuid();

        app(OfflineSaleSyncService::class)->sync($this->till, [[
            'uuid' => $uuid,
            'invoice_no' => 'T1-000900',
            'sold_at' => now()->subHour()->toIso8601String(),
            'cashier_id' => $this->cashier->getKey(),
            'lines' => [['variant_id' => $plug->getKey(), 'quantity' => 1, 'unit_price' => 120_000]],
            'payments' => [['method' => 'cash', 'amount' => 120_000]],
            'grand_total' => 120_000,
        ]]);

        return Sale::query()->where('uuid', $uuid)->firstOrFail();
    }

    public function test_flagged_offline_sales_are_listed_for_review(): void
    {
        $flagged = $this->flaggedSale();
        $normal = app(CheckoutService::class)->checkout($this->cart([new CartLine($this->variant('CPR8EA-9')->getKey(), 1)]));

        $this->actingAs($this->owner);

        Livewire::test(ListSales::class)
            ->set('activeTab', 'needs_review')
            ->assertCanSeeTableRecords([$flagged])
            ->assertCanNotSeeTableRecords([$normal]);

        Livewire::test(ViewSale::class, ['record' => $flagged->getRouteKey()])
            ->assertSee('CPR8EA-9 sold at');
    }

    public function test_owner_marks_a_flagged_sale_reviewed_without_changing_it(): void
    {
        $sale = $this->flaggedSale();
        $this->actingAs($this->owner);

        Livewire::test(ViewSale::class, ['record' => $sale->getRouteKey()])
            ->callAction('markReviewed')
            ->assertHasNoActionErrors();

        $sale->refresh();

        $this->assertSame($this->owner->getKey(), $sale->reviewed_by);
        $this->assertNotNull($sale->reviewed_at);
        $this->assertSame(120_000, $sale->grand_total);
        $this->assertSame(0, Sale::query()->awaitingReview()->count());
    }

    public function test_cashier_cannot_mark_a_sale_reviewed(): void
    {
        $sale = $this->flaggedSale();
        $this->actingAs($this->cashier);

        Livewire::test(ViewSale::class, ['record' => $sale->getRouteKey()])
            ->assertActionHidden('markReviewed');
    }

    public function test_owner_revokes_a_tills_offline_access(): void
    {
        $this->till->issueSyncToken('Front counter browser');
        $this->actingAs($this->owner);

        Livewire::test(ManageDevices::class)
            ->callTableAction('revokeOfflineAccess', $this->till)
            ->assertHasNoTableActionErrors();

        $this->assertSame(0, $this->till->tokens()->count());
    }

    public function test_cashier_cannot_open_the_tills_screen(): void
    {
        $this->actingAs($this->cashier)->get(ManageDevices::getUrl())->assertForbidden();
    }
}
