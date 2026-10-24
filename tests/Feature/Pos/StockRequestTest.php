<?php

namespace Tests\Feature\Pos;

use App\Enums\StockRequestStatus;
use App\Exceptions\PosException;
use App\Models\ProductVariant;
use App\Models\StockRequest;
use App\Models\StockTransfer;
use App\Models\StockTransferItemBatch;
use App\Models\User;
use App\Services\Data\FulfilLine;
use App\Services\Data\RequestLine;
use App\Services\LocationService;
use App\Services\StockRequestService;
use Database\Seeders\Concerns\SeedsDemoShop;

/**
 * OPD stock requests (docs/PHARMACY.md §9, branch 10): a cashier at Doctor's
 * Medicines asks General Pharmacy for stock in any unit; the owner sends it
 * in one go or in parts, each a posted transfer first expiry first out;
 * requested -> partly sent -> sent, or cancelled. Who may ask, see, send and
 * cancel depends on permissions and allowed locations.
 */
class StockRequestTest extends BatchTestCase
{
    use SeedsDemoShop;

    private User $opd;

    protected function setUp(): void
    {
        parent::setUp();

        // The OPD's cashier works at Doctor's Medicines only; the shop's cashier at General Pharmacy.
        $this->opd = $this->user($this->shop, 'OPD Cashier', 'opd@clinic.test', 'cashier');
        app(LocationService::class)->setAllowedLocations($this->opd, [(int) $this->doctors->getKey()], $this->owner);
        app(LocationService::class)->setAllowedLocations($this->cashier, [(int) $this->general->getKey()], $this->owner);
    }

    private function requests(): StockRequestService
    {
        return app(StockRequestService::class);
    }

    /** @param  list<RequestLine>  $lines */
    private function ask(array $lines, ?User $by = null): StockRequest
    {
        return $this->requests()->create($this->general, $this->doctors, $lines, $by ?? $this->opd, 'For tomorrow');
    }

    public function test_the_opd_asks_general_pharmacy_in_any_unit(): void
    {
        $panadol = $this->medicine();
        $request = $this->ask([new RequestLine($panadol->getKey(), 2, $this->boxOf($panadol))]);

        $this->assertSame('RQ-000001', $request->request_number);
        $this->assertSame(StockRequestStatus::Requested, $request->status);
        $this->assertSame((int) $this->opd->getKey(), (int) $request->requested_by);
        $item = $request->items->sole();
        $this->assertSame('Box', $item->unit_name);
        $this->assertSame(2.0, (float) $item->quantity);
        $this->assertSame(20.0, (float) $item->base_quantity);
        $this->assertSame(20.0, $item->outstandingBase());
        $this->assertSame(0, StockTransfer::query()->count(), 'Asking moves no stock.');
    }

    public function test_a_request_is_filled_in_parts_first_expiry_first_out(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'SOON', '2026-12-31', 15);
        $this->receive($panadol, 'LATER', '2027-06-30', 100);
        $this->foundExpired($panadol, 'OLD', '2026-09-30', 50);
        $request = $this->ask([new RequestLine($panadol->getKey(), 4, $this->boxOf($panadol))]);
        $item = $request->items->sole();

        // First 20 tablets: all of SOON, then 5 of LATER; never the expired batch.
        $first = $this->requests()->fulfil($request, [new FulfilLine((int) $item->getKey(), 20, $this->baseOf($panadol))], $this->owner);

        $request->refresh();
        $this->assertSame(StockRequestStatus::PartlySent, $request->status);
        $this->assertNull($request->closed_at);
        $this->assertSame(20.0, (float) $item->fresh()?->sent_base_quantity);
        $this->assertTrue($first->stockRequest()->is($request));
        $this->assertSame('For RQ-000001', $first->note);
        $this->assertSame(['SOON' => 15.0, 'LATER' => 5.0], StockTransferItemBatch::query()->orderBy('id')->pluck('quantity', 'batch_no')->map(fn ($q): float => (float) $q)->all());
        $this->assertSame(['SOON' => 15.0, 'LATER' => 5.0], $this->batchQuantities($panadol, $this->doctors));

        // The rest, two boxes (the unit asked for), from LATER.
        $second = $this->requests()->fulfil($request, [new FulfilLine((int) $item->getKey(), 2)], $this->owner);

        $request->refresh();
        $this->assertSame(StockRequestStatus::Sent, $request->status);
        $this->assertNotNull($request->closed_at);
        $this->assertSame(['TR-000001', 'TR-000002'], $request->transfers()->pluck('transfer_number')->all());
        $this->assertSame('Box', $second->items->sole()->unit_name);
        $this->assertSame(['SOON' => 15.0, 'LATER' => 25.0], $this->batchQuantities($panadol, $this->doctors));
        $this->assertSame(['LATER' => 75.0, 'OLD' => 50.0], $this->batchQuantities($panadol));
        $this->assertSame(0.0, $item->fresh()?->outstandingBase());
        $this->assertLedgerMatches();

        try {
            $this->requests()->fulfil($request, [new FulfilLine((int) $item->getKey(), 1)], $this->owner);
            $this->fail('A sent request was sent again.');
        } catch (PosException $exception) {
            $this->assertSame('RQ-000001 is Sent: there is nothing more to send.', $exception->getMessage());
        }
    }

    public function test_a_named_batch_and_several_items_go_in_one_transfer(): void
    {
        $panadol = $this->medicine();
        $amoxil = $this->medicine('Amoxil 250 mg Capsules');
        $this->receive($panadol, 'SOON', '2026-12-31', 15);
        $this->receive($panadol, 'LATER', '2027-06-30', 100);
        $this->receive($amoxil, 'AM1', '2027-06-30', 40);
        $request = $this->ask([new RequestLine($panadol->getKey(), 10), new RequestLine($amoxil->getKey(), 30)]);
        [$p, $a] = [$request->items[0], $request->items[1]];

        $transfer = $this->requests()->fulfil($request, [
            new FulfilLine((int) $p->getKey(), 10, batchId: (int) $this->batch($panadol, 'LATER')->getKey()),
            new FulfilLine((int) $a->getKey(), 0), // later
        ], $this->owner);

        $this->assertCount(1, $transfer->items);
        $this->assertSame(['LATER' => 10.0], $this->batchQuantities($panadol, $this->doctors));
        $this->assertSame(StockRequestStatus::PartlySent, $request->fresh()?->status);
    }

    public function test_never_more_than_is_outstanding_nor_more_than_general_pharmacy_has(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'A', '2026-12-31', 8);
        $request = $this->ask([new RequestLine($panadol->getKey(), 12)]);
        $item = $request->items->sole();

        try {
            $this->requests()->fulfil($request, [new FulfilLine((int) $item->getKey(), 2, $this->boxOf($panadol))], $this->owner);
            $this->fail('More than outstanding was sent.');
        } catch (PosException $exception) {
            $this->assertStringContainsString('only 12 Tablet are still to send', $exception->getMessage());
        }

        try {
            $this->requests()->fulfil($request, [new FulfilLine((int) $item->getKey(), 12)], $this->owner);
            $this->fail('More than General Pharmacy holds was sent.');
        } catch (PosException $exception) {
            $this->assertStringContainsString('Not enough stock', $exception->getMessage());
        }

        $this->assertSame(StockRequestStatus::Requested, $request->fresh()?->status);
        $this->assertSame(0.0, (float) $item->fresh()?->sent_base_quantity);
        $this->assertSame(0, StockTransfer::query()->count());
    }

    public function test_who_may_ask_for_which_location(): void
    {
        $panadol = $this->medicine();

        // A General Pharmacy cashier can't ask on the OPD's behalf.
        try {
            $this->ask([new RequestLine($panadol->getKey(), 1)], $this->cashier);
            $this->fail('A cashier asked for a location they may not use.');
        } catch (PosException $exception) {
            $this->assertSame("You can't request stock for Doctor's Medicines.", $exception->getMessage());
        }

        // The owner may use every location; without stock.request nobody may ask.
        $this->ask([new RequestLine($panadol->getKey(), 1)], $this->owner);
        $this->opd->syncRoles([]);
        $this->opd->givePermissionTo('pos.checkout');

        $this->expectExceptionMessage('You do not have permission to request stock.');
        $this->ask([new RequestLine($panadol->getKey(), 1)], $this->opd->fresh());
    }

    public function test_only_stock_transfer_sends_and_each_side_sees_its_own_requests(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'A', '2026-12-31', 10);
        $request = $this->ask([new RequestLine($panadol->getKey(), 5)]);

        try {
            $this->requests()->fulfil($request, [new FulfilLine((int) $request->items->sole()->getKey(), 5)], $this->opd);
            $this->fail('A cashier sent stock.');
        } catch (PosException $exception) {
            $this->assertSame('You do not have permission to send stock.', $exception->getMessage());
        }

        $this->assertTrue($this->opd->can('view', $request));
        $this->assertFalse($this->cashier->can('view', $request), 'General Pharmacy\'s cashier does not see the OPD\'s requests.');
        $this->assertTrue($this->owner->can('view', $request));
        $this->assertSame(1, $this->requests()->openCount($this->opd));
        $this->assertSame(0, $this->requests()->openCount($this->cashier));
        $this->assertSame(1, $this->requests()->openCount($this->owner));
    }

    public function test_cancelling(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'A', '2026-12-31', 10);
        $first = $this->ask([new RequestLine($panadol->getKey(), 5)]);
        $second = $this->ask([new RequestLine($panadol->getKey(), 5)]);

        // The OPD takes back a request nothing was sent for.
        $cancelled = $this->requests()->cancel($first, $this->opd);
        $this->assertSame(StockRequestStatus::Cancelled, $cancelled->status);
        $this->assertSame((int) $this->opd->getKey(), (int) $cancelled->cancelled_by);
        $this->assertNotNull($cancelled->closed_at);

        // Once stock has gone, only General Pharmacy (stock.transfer) can close it short.
        $this->requests()->fulfil($second, [new FulfilLine((int) $second->items->sole()->getKey(), 2)], $this->owner);

        try {
            $this->requests()->cancel($second, $this->opd);
            $this->fail('The OPD cancelled a partly sent request.');
        } catch (PosException $exception) {
            $this->assertSame("You can't cancel RQ-000002: stock has already been sent for it.", $exception->getMessage());
        }

        $this->requests()->cancel($second, $this->owner);
        $this->assertSame(StockRequestStatus::Cancelled, $second->fresh()?->status);
        $this->assertSame(2.0, (float) $second->items->sole()->fresh()?->sent_base_quantity, 'What was sent stays sent.');
        $this->assertSame(['A' => 2.0], $this->batchQuantities($panadol, $this->doctors));
        $this->assertSame(0, $this->requests()->openCount($this->owner));
    }

    public function test_a_request_names_another_location_active_items_and_each_once(): void
    {
        $panadol = $this->medicine();

        foreach ([
            fn () => $this->requests()->create($this->doctors, $this->doctors, [new RequestLine($panadol->getKey(), 1)], $this->owner),
            fn () => $this->ask([new RequestLine($panadol->getKey(), 1), new RequestLine($panadol->getKey(), 2)]),
            fn () => $this->ask([new RequestLine($panadol->getKey(), 1.5)]),
            fn () => $this->ask([]),
        ] as $i => $attempt) {
            try {
                $attempt();
                $this->fail("Attempt {$i} was accepted.");
            } catch (PosException) {
                // refused
            }
        }

        $this->assertSame(0, StockRequest::query()->count());
    }

    public function test_the_pick_list_prints_what_is_left_and_the_batches_to_pick(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'SOON', '2026-12-31', 15);
        $this->receive($panadol, 'LATER', '2027-06-30', 100);
        $this->foundExpired($panadol, 'OLD', '2026-09-30', 50);
        $request = $this->ask([new RequestLine($panadol->getKey(), 3, $this->boxOf($panadol))]);

        $this->actingAs($this->owner)->get(route('stock.print.request', $request))
            ->assertOk()
            ->assertSee('RQ-000001')
            ->assertSee('3 Box')
            ->assertSee('SOON · exp 12/2026 × 15', false)
            ->assertSee('LATER · exp 06/2027 × 15', false)
            ->assertDontSee('OLD · exp');

        $this->actingAs($this->opd)->get(route('stock.print.request', $request))->assertOk();
        $this->actingAs($this->cashier)->get(route('stock.print.request', $request))->assertForbidden();
    }

    private function baseOf(ProductVariant $variant): int
    {
        return (int) $variant->units()->where('is_base', true)->value('id');
    }
}
