<?php

namespace Tests\Feature\Pos;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Device;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\Shop;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Support\CurrentShop;
use App\Support\TillSelection;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

class PosShiftAndPageTest extends PosTestCase
{
    private function openShift(string $float = '5000'): void
    {
        $this->actingAs($this->cashier)
            ->postJson(route('pos.shift.open'), ['device' => $this->till->uuid, 'opening_cash' => $float])
            ->assertCreated();
    }

    public function test_cashier_opens_a_shift_with_an_opening_float(): void
    {
        $this->actingAs($this->cashier)
            ->postJson(route('pos.shift.open'), ['device' => $this->till->uuid, 'opening_cash' => '5,000.50'])
            ->assertUnprocessable();

        $this->actingAs($this->cashier)
            ->postJson(route('pos.shift.open'), ['device' => $this->till->uuid, 'opening_cash' => '5000.50'])
            ->assertCreated()
            ->assertJsonPath('shift.opening_cash', 500_050)
            ->assertJsonPath('shift.opened_by', $this->cashier->name);

        $this->assertSame(1, Shift::query()->whereNull('closed_at')->count());
    }

    public function test_a_second_shift_cannot_be_opened_on_the_same_till(): void
    {
        $this->openShift();

        $this->actingAs($this->cashier)
            ->postJson(route('pos.shift.open'), ['device' => $this->till->uuid, 'opening_cash' => '100'])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'already open'));
    }

    public function test_closing_a_shift_shows_expected_cash_and_the_difference(): void
    {
        $this->openShift('5000');

        app(CheckoutService::class)->checkout($this->cart([new CartLine($this->variant('CPR8EA-9')->getKey(), 2)]));
        // LKR 2,900 sale, paid with a large note: change comes out of the drawer, so the drawer gains 2,900.

        $this->actingAs($this->cashier)
            ->getJson(route('pos.shift.expected', ['device' => $this->till->uuid, 'counted_cash' => '7800']))
            ->assertOk()
            ->assertJsonPath('expected_cash', 790_000)
            ->assertJsonPath('difference', -10_000);

        $this->actingAs($this->cashier)
            ->postJson(route('pos.shift.close'), ['device' => $this->till->uuid, 'counted_cash' => '7800'])
            ->assertOk()
            ->assertJsonPath('closed.expected_cash', 790_000)
            ->assertJsonPath('closed.counted_cash', 780_000)
            ->assertJsonPath('closed.difference', -10_000);

        $this->assertSame(0, Shift::query()->whereNull('closed_at')->count());
    }

    public function test_closing_needs_an_open_shift_and_a_count(): void
    {
        $this->actingAs($this->cashier)
            ->postJson(route('pos.shift.close'), ['device' => $this->till->uuid, 'counted_cash' => '100'])
            ->assertUnprocessable();

        $this->openShift();

        $this->actingAs($this->cashier)
            ->postJson(route('pos.shift.close'), ['device' => $this->till->uuid])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('counted_cash');
    }

    public function test_selling_requires_an_open_shift_when_the_shop_demands_it(): void
    {
        $this->shop->update(['settings' => ['require_open_shift' => true]]);

        $payload = [
            'device' => $this->till->uuid,
            'uuid' => (string) Str::uuid(),
            'lines' => [['variant_id' => $this->variant('CPR8EA-9')->getKey(), 'quantity' => 1]],
            'payments' => [['method' => 'cash', 'amount' => '5000']],
        ];

        $this->actingAs($this->cashier)->postJson(route('pos.checkout'), $payload)->assertUnprocessable();

        $this->openShift();
        $this->actingAs($this->cashier)->postJson(route('pos.checkout'), $payload)->assertOk();
    }

    public function test_the_page_lists_tills_and_remembers_the_chosen_one_in_a_cookie(): void
    {
        $this->actingAs($this->cashier)->get(route('pos.index'))->assertInertia(fn (Assert $page) => $page
            ->component('pos/Index')
            ->where('till', null)
            ->where('shift', null)
            ->has('tills', 1)
            ->where('tills.0.code', 'T1')
            ->where('shop.currency', 'LKR')
            ->where('cashier.name', $this->cashier->name));

        $this->actingAs($this->cashier)
            ->post(route('pos.till.store'), ['device' => $this->till->uuid])
            ->assertRedirect(route('pos.index'))
            ->assertCookie(TillSelection::COOKIE);
    }

    public function test_the_page_shows_the_remembered_till_and_its_open_shift(): void
    {
        $this->openShift();

        $this->actingAs($this->cashier)
            ->withCookie(TillSelection::COOKIE, $this->till->uuid)
            ->get(route('pos.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('till.code', 'T1')
                ->where('shift.opening_cash', 500_000)
                ->where('tills.0.shiftOpen', true));
    }

    public function test_a_remembered_till_from_another_shop_is_ignored(): void
    {
        $other = Shop::query()->create(['name' => 'Other Shop', 'code' => 'OTHER']);
        $foreign = (new Device)->forceFill([
            'shop_id' => $other->getKey(),
            'location_id' => $this->location->getKey(),
            'name' => 'Foreign',
            'code' => 'X1',
        ]);
        $foreign->save();

        $this->actingAs($this->cashier)
            ->withCookie(TillSelection::COOKIE, $foreign->uuid)
            ->get(route('pos.index'))
            ->assertInertia(fn (Assert $page) => $page->where('till', null));

        $this->actingAs($this->cashier)
            ->postJson(route('pos.till.store'), ['device' => $foreign->uuid])
            ->assertUnprocessable();
    }

    public function test_the_page_needs_the_checkout_permission(): void
    {
        $noRole = User::factory()->create();
        $noRole->forceFill(['shop_id' => $this->shop->getKey()])->save();

        $this->actingAs($noRole)->get(route('pos.index'))->assertForbidden();
        auth()->logout();
        $this->get(route('pos.index'))->assertRedirect(route('login'));
    }

    public function test_cashiers_land_on_pos_and_owners_on_admin_after_login(): void
    {
        $this->post(route('login'), ['email' => 'cashier@pos.test', 'password' => 'password'])->assertRedirect('/pos');
        auth()->logout();
        $this->post(route('login'), ['email' => 'owner@pos.test', 'password' => 'password'])->assertRedirect('/admin');
    }

    /**
     * The sign-in form is an Inertia page and /admin is not. Following a plain redirect inside
     * Inertia shows the whole panel in Inertia's modal (blank widgets, URL stuck on /login),
     * so Inertia requests headed for the panel get a full page load instead.
     */
    public function test_signing_in_from_the_inertia_form_loads_the_admin_panel_as_a_full_page(): void
    {
        $this->withHeaders(['X-Inertia' => 'true'])
            ->post(route('login'), ['email' => 'owner@pos.test', 'password' => 'password'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', url('/admin'));
    }

    public function test_inertia_visits_to_the_old_dashboard_load_the_admin_panel_as_a_full_page(): void
    {
        $this->actingAs($this->owner)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())])
            ->get(route('dashboard'))
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', url('/admin'));
    }

    public function test_redirects_between_inertia_pages_stay_inertia_visits(): void
    {
        $this->withHeaders(['X-Inertia' => 'true'])
            ->post(route('login'), ['email' => 'cashier@pos.test', 'password' => 'password'])
            ->assertRedirect('/pos');
    }

    public function test_the_old_dashboard_url_sends_owners_to_admin_and_cashiers_to_the_till(): void
    {
        $this->actingAs($this->owner)->get(route('dashboard'))->assertRedirect('/admin');
        $this->actingAs($this->cashier)->get(route('dashboard'))->assertRedirect('/pos');
    }

    public function test_the_receipt_page_has_everything_a_customer_needs(): void
    {
        $this->shop->update([
            'address' => '12 Galle Road',
            'tax_number' => 'TIN-123',
            'receipt_header' => 'Welcome!',
            'receipt_footer' => 'No returns after 14 days',
            'timezone' => 'Asia/Colombo',
        ]);

        $sale = app(CheckoutService::class)->checkout($this->cart([new CartLine($this->variant('CPR8EA-9')->getKey(), 2)]));

        $this->actingAs($this->cashier)->get(route('pos.receipt', $sale))->assertInertia(fn (Assert $page) => $page
            ->component('pos/Receipt')
            ->where('shop.name', $this->shop->name)
            ->where('shop.address', '12 Galle Road')
            ->where('shop.taxNumber', 'TIN-123')
            ->where('shop.header', 'Welcome!')
            ->where('shop.footer', 'No returns after 14 days')
            ->where('sale.invoiceNo', $sale->invoice_no)
            ->where('sale.cashier', $this->cashier->name)
            ->where('sale.grandTotal', 290_000)
            ->where('sale.changeDue', $sale->change_due)
            ->where('sale.soldAt', $sale->sold_at->timezone('Asia/Colombo')->format('Y-m-d H:i'))
            ->has('sale.items', 1)
            ->where('sale.items.0.quantity', '2')
            ->has('sale.payments', 1)
            ->missing('sale.items.0.unit_cost'));
    }

    public function test_a_cashier_cannot_open_someone_elses_receipt_but_the_owner_can(): void
    {
        $sale = app(CheckoutService::class)->checkout($this->cart([new CartLine($this->variant('CPR8EA-9')->getKey(), 1)], cashier: $this->owner));

        $this->actingAs($this->cashier)->get(route('pos.receipt', $sale))->assertForbidden();
        $this->actingAs($this->owner)->get(route('pos.receipt', $sale))->assertOk();
        $this->assertSame(1, Sale::query()->count());
    }

    public function test_a_receipt_from_another_shop_is_not_found(): void
    {
        $sale = app(CheckoutService::class)->checkout($this->cart([new CartLine($this->variant('CPR8EA-9')->getKey(), 1)]));

        $other = Shop::query()->create(['name' => 'Other Shop', 'code' => 'OTHER']);
        $stranger = $this->owner->replicate(['email'])->forceFill(['email' => 'stranger@example.test', 'shop_id' => $other->getKey()]);
        $stranger->save();
        $stranger->assignRole('owner');
        $this->useShop(null);
        app()->forgetInstance(CurrentShop::class);

        $this->actingAs($stranger)->get(route('pos.receipt', $sale))->assertNotFound();
    }
}
