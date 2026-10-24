<?php

namespace Tests\Feature\Pos;

use App\Enums\StockRequestStatus;
use App\Models\StockRequest;
use App\Models\User;
use App\Services\Data\BatchInput;
use App\Services\StockService;
use App\Support\TillSelection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Pos\Concerns\WorksWithBatches;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * Stock requests from the till (docs/PHARMACY.md §9, branch 10): the OPD's
 * cashier, who has no admin panel, asks General Pharmacy for stock at
 * /pos/stock-requests, sees the OPD's requests and cancels one nothing was
 * sent for. A General Pharmacy cashier can't ask on the OPD's behalf.
 */
class TillStockRequestsTest extends PharmacyTestCase
{
    use WorksWithBatches, WorksWithCheckout;

    private User $opd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->opd = $this->cashierAt($this->doctors);
    }

    public function test_the_till_offers_stock_requests_at_the_opd_only(): void
    {
        // At Doctor's Medicines' till: the OPD asks General Pharmacy.
        $this->actingAs($this->opd)
            ->withCookie(TillSelection::COOKIE, $this->docTill()->uuid)
            ->get(route('pos.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('cashier.canRequestStock', true));

        // Not at General Pharmacy's till (decided 2026-10-22): stock is received there
        // and requests are sent to it, so it has no one to ask.
        $this->actingAs($this->owner)
            ->withCookie(TillSelection::COOKIE, $this->genTill()->uuid)
            ->get(route('pos.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('till.code', $this->genTill()->code)->where('cashier.canRequestStock', false));

        // Nor before a till is chosen.
        $this->defaultCookies = [];
        $this->actingAs($this->cashierAt($this->general, $this->doctors))->get(route('pos.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('till', null)->where('cashier.canRequestStock', false));
    }

    public function test_the_opd_cashier_asks_for_stock_and_sees_the_request(): void
    {
        $panadol = $this->medicine();
        app(StockService::class)->stockIn($panadol, $this->general, 300, $this->owner, 1_000, batch: new BatchInput('P1', '03/2028'));
        $this->docTill();

        $this->actingAs($this->opd)
            ->withCookie(TillSelection::COOKIE, $this->docTill()->uuid)
            ->get(route('pos.stock-requests.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pos/StockRequests')
                ->has('locations', 1)
                ->where('locations.0.name', "Doctor's Medicines")
                ->where('defaultFor', $this->doctors->getKey())
                ->where('defaultFrom', $this->general->getKey())
                ->has('requests', 0));

        $this->getJson(route('pos.stock-requests.items', ['q' => 'panadol', 'for' => $this->doctors->getKey(), 'from' => $this->general->getKey()]))
            ->assertOk()
            ->assertJsonPath('items.0.id', $panadol->getKey())
            ->assertJsonPath('items.0.onHandThere', 300)
            ->assertJsonPath('items.0.onHandHere', 0)
            ->assertJsonPath('items.0.units.1.name', 'Box of 10')
            ->assertJsonMissingPath('items.0.cost');

        $this->postJson(route('pos.stock-requests.store'), [
            'for' => $this->doctors->getKey(),
            'from' => $this->general->getKey(),
            'note' => 'For the evening clinic',
            'lines' => [['variant_id' => $panadol->getKey(), 'unit_id' => $this->boxOf($panadol), 'quantity' => 3]],
        ])->assertCreated()->assertJsonPath('request.number', 'RQ-000001');

        $request = StockRequest::query()->sole();
        $this->assertSame((int) $this->opd->getKey(), (int) $request->requested_by);
        $this->assertSame(30.0, (float) $request->items->sole()->base_quantity);

        $this->get(route('pos.stock-requests.index'))->assertInertia(fn (Assert $page) => $page
            ->has('requests', 1)
            ->where('requests.0.number', 'RQ-000001')
            ->where('requests.0.status', 'requested')
            ->where('requests.0.canCancel', true)
            ->where('requests.0.items.0.quantity', '3 Box'));

        $this->postJson(route('pos.stock-requests.cancel', $request))->assertOk();
        $this->assertSame(StockRequestStatus::Cancelled, $request->fresh()?->status);
    }

    public function test_the_low_stock_suggestion_fills_the_request_up_to_par(): void
    {
        $panadol = $this->medicine();
        app(StockService::class)->stockIn($panadol, $this->doctors, 4, $this->owner, 1_000, batch: new BatchInput('P1', '03/2028'));
        app(StockService::class)->setReorderLevel($panadol, $this->doctors, 10);
        app(StockService::class)->setParLevel($panadol, $this->doctors, 30);

        $this->actingAs($this->opd)
            ->getJson(route('pos.stock-requests.suggest', ['for' => $this->doctors->getKey()]))
            ->assertOk()
            ->assertJsonPath('lines.0.item.id', $panadol->getKey())
            ->assertJsonPath('lines.0.quantity', 26);

        $this->getJson(route('pos.stock-requests.suggest', ['for' => $this->general->getKey()]))->assertUnprocessable();
    }

    public function test_a_general_pharmacy_cashier_cannot_ask_for_the_opd_nor_see_its_requests(): void
    {
        $panadol = $this->medicine();
        $gen = $this->cashierAt($this->general);

        $this->actingAs($this->opd)->postJson(route('pos.stock-requests.store'), [
            'for' => $this->doctors->getKey(), 'from' => $this->general->getKey(),
            'lines' => [['variant_id' => $panadol->getKey(), 'quantity' => 5]],
        ])->assertCreated();
        $request = StockRequest::query()->sole();

        $this->flushSession();
        $this->actingAs($gen)->postJson(route('pos.stock-requests.store'), [
            'for' => $this->doctors->getKey(), 'from' => $this->general->getKey(),
            'lines' => [['variant_id' => $panadol->getKey(), 'quantity' => 5]],
        ])->assertUnprocessable()->assertJsonPath('message', "You can't request stock for Doctor's Medicines.");

        $this->get(route('pos.stock-requests.index'))->assertInertia(fn (Assert $page) => $page->has('requests', 0));
        $this->postJson(route('pos.stock-requests.cancel', $request))->assertForbidden();
        $this->actingAs($gen)->get(route('stock.print.request', $request))->assertForbidden();
    }

    public function test_without_stock_request_there_is_no_page(): void
    {
        $this->opd->syncRoles([]);
        $this->opd->givePermissionTo('pos.checkout');

        $this->actingAs($this->opd->fresh() ?? $this->opd)->get(route('pos.stock-requests.index'))->assertForbidden();
    }
}
