<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Filament\Resources\Sales\Pages\ViewSale;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\ReceiptService;
use App\Services\ReportService;
use App\Services\ReturnService;
use App\Services\ShiftService;
use App\Support\CurrentShop;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;

class ReceiptReprintTest extends AdminTestCase
{
    private function sale(int $quantity = 2, ?User $cashier = null): Sale
    {
        return app(CheckoutService::class)->checkout($this->cart(
            [new CartLine($this->variant('CPR8EA-9')->getKey(), $quantity)],
            cashier: $cashier,
        ));
    }

    private function reprint(Sale $sale, ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->cashier)
            ->postJson(route('pos.receipt.reprint', $sale), ['device' => $this->till->uuid]);
    }

    /** @return Builder<AuditLog> */
    private function reprints(): Builder
    {
        return AuditLog::query()->where('event', ReceiptService::REPRINT_EVENT);
    }

    public function test_a_reprint_is_logged_and_prints_as_a_duplicate(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 04:30:00', 'UTC'));
        $sale = $this->sale(cashier: $this->owner);

        $url = $this->reprint($sale)->assertOk()->json('receipt_url');

        $entry = $this->reprints()->sole();
        $this->assertSame($this->cashier->getKey(), (int) $entry->user_id);
        $this->assertSame($this->shop->getKey(), (int) $entry->shop_id);
        $this->assertSame($sale->getKey(), (int) $entry->auditable_id);
        $this->assertSame($sale->getKey(), $entry->new_values['sale_id']);
        $this->assertSame($this->till->getKey(), $entry->new_values['device_id']);
        $this->assertSame('T1', $entry->new_values['till']);

        $printedAt = $entry->created_at->timezone($this->shop->timezone)->format('Y-m-d H:i');

        $this->get($url)->assertInertia(fn (Assert $page) => $page
            ->component('pos/Receipt')
            ->where('sale.invoiceNo', $sale->invoice_no)
            ->where('sale.reprint.printedBy', $this->cashier->name)
            ->where('sale.reprint.printedAt', $printedAt));

        // The first print stays an original.
        $this->actingAs($this->owner)->get(route('pos.receipt', $sale))
            ->assertInertia(fn (Assert $page) => $page->where('sale.reprint', null));
    }

    public function test_a_voided_sale_reprints_as_voided_with_returned_items_marked(): void
    {
        $returned = $this->sale(3);
        app(ReturnService::class)->process($returned, [$returned->items()->value('id') => 1], ReturnReason::WrongItem, PaymentMethod::Cash, $this->owner);
        $voided = app(CheckoutService::class)->void($this->sale(), $this->owner, 'Mistake');

        $this->get($this->reprint($returned)->json('receipt_url'))->assertInertia(fn (Assert $page) => $page
            ->where('sale.status', 'partially_returned')
            ->where('sale.items.0.quantity', '3')
            ->where('sale.items.0.returnedQuantity', '1')
            ->where('sale.refundedTotal', $returned->fresh()->refunded_total));

        $this->get($this->reprint($voided)->json('receipt_url'))->assertInertia(fn (Assert $page) => $page
            ->where('sale.status', 'voided')
            ->where('sale.items.0.returnedQuantity', null));
    }

    public function test_a_cashier_cannot_reprint_another_shops_sale(): void
    {
        $sale = $this->sale();

        $other = Shop::query()->create(['name' => 'Other Shop', 'code' => 'OTHER']);
        $stranger = $this->cashier->replicate(['email'])->forceFill(['email' => 'stranger@example.test', 'shop_id' => $other->getKey()]);
        $stranger->save();
        $stranger->assignRole('cashier');
        $this->useShop(null);
        app()->forgetInstance(CurrentShop::class);

        $this->reprint($sale, $stranger)->assertNotFound();

        $this->assertSame(0, AuditLog::query()->where('event', ReceiptService::REPRINT_EVENT)->count());
    }

    public function test_reprinting_from_the_till_needs_pos_checkout(): void
    {
        $sale = $this->sale();
        $this->cashier->syncRoles([]);

        $this->reprint($sale)->assertForbidden();

        $this->assertSame(0, $this->reprints()->count());
    }

    public function test_a_duplicate_receipt_opens_only_for_whoever_reprinted_it(): void
    {
        $url = $this->reprint($this->sale())->json('receipt_url');

        $this->actingAs($this->owner)->get($url)->assertNotFound();
    }

    public function test_recent_sales_lists_this_tills_shift_and_today_searchable_by_invoice(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', $this->shop->timezone));
        $yesterdayOutsideShift = $this->sale();
        app(ShiftService::class)->open($this->till, $this->cashier, 0);
        $yesterdayInShift = $this->sale();

        $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00:00', $this->shop->timezone));
        $today = $this->sale();

        $otherTill = Device::query()->create(['location_id' => $this->location->getKey(), 'name' => 'Back counter', 'code' => 'T2']);
        $mainTill = $this->till;
        $this->till = $otherTill;
        $onOtherTill = $this->sale();
        $this->till = $mainTill;

        $list = fn (?string $search = null) => $this->actingAs($this->cashier)
            ->getJson(route('pos.recent-sales', ['device' => $this->till->uuid, 'q' => $search]))
            ->assertOk()
            ->json('sales.*.invoice_no');

        $this->assertSame([$today->invoice_no, $yesterdayInShift->invoice_no], $list());
        $this->assertSame([$yesterdayInShift->invoice_no], $list($yesterdayInShift->invoice_no));
        $this->assertNotContains($yesterdayOutsideShift->invoice_no, $list($yesterdayOutsideShift->invoice_no));
        $this->assertNotContains($onOtherTill->invoice_no, $list());
    }

    public function test_owner_reprints_from_the_admin_sale_page(): void
    {
        $sale = $this->sale();
        $this->actingAs($this->owner);

        Livewire::test(ViewSale::class, ['record' => $sale->getRouteKey()])
            ->callAction('reprint')
            ->assertHasNoActionErrors();

        $entry = $this->reprints()->sole();
        $this->assertSame($this->owner->getKey(), (int) $entry->user_id);
        $this->assertNull($entry->new_values['device_id']);
    }

    public function test_the_admin_reprint_action_needs_sales_view_all(): void
    {
        $sale = $this->sale();
        $this->actingAs($this->cashier);

        Livewire::test(ViewSale::class, ['record' => $sale->getRouteKey()])
            ->assertActionHidden('reprint');
    }

    public function test_sales_by_cashier_counts_reprints_by_who_reprinted(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00:00', $this->shop->timezone));
        $sale = $this->sale();
        $this->reprint($sale);
        $this->reprint($sale);
        $this->reprint($sale, $this->owner);

        $rows = collect(app(ReportService::class)->salesByCashier(ReportPeriod::today($this->shop)))->keyBy('cashier');

        $this->assertSame(2, $rows[$this->cashier->name]['reprints']);
        $this->assertSame(1, $rows[$this->owner->name]['reprints']);
        $this->assertSame(0, $rows[$this->owner->name]['sales_count']);
    }
}
