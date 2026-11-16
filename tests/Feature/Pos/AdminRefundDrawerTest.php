<?php

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Filament\Resources\Sales\Pages\ViewSale;
use App\Models\AuditLog;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\ReturnLine;
use App\Services\ReturnService;
use App\Services\ShiftService;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithReceipts;

/**
 * Admin refunds and voids (docs/PHARMACY.md, branch 18): a cash refund
 * from the admin's sale page asks which open till's drawer paid it (the
 * selling till's by default, required while any till is open, a warning
 * when none is), and comes off that drawer only; returns and voids are
 * written to the audit log.
 */
class AdminRefundDrawerTest extends BatchTestCase
{
    use WorksWithCheckout, WorksWithReceipts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stockTheClinic();
    }

    /** A cash sale on T1 of two Panadol tablets (40.00). */
    private function cashSale(): Sale
    {
        return $this->sell([new CartLine($this->panadol->getKey(), 2)]);
    }

    public function test_the_refund_comes_off_the_drawer_picked_and_that_one_only(): void
    {
        $shifts = app(ShiftService::class);
        $general = $shifts->open($this->till, $this->cashier, 100_000);
        $opd = $this->docTill();
        $docCashier = $this->cashierAt($this->doctors);
        $opdShift = $shifts->open($opd, $docCashier, 50_000);
        $sale = $this->cashSale();
        $item = $sale->items->firstOrFail();

        $this->actingAs($this->owner);
        Livewire::test(ViewSale::class, ['record' => $sale->getRouteKey()])
            ->mountAction('return')
            ->assertActionDataSet(['shift_id' => $general->getKey()])
            ->setActionData([
                'items' => [['sale_item_id' => $item->getKey(), 'quantity' => 1, 'restock' => true]],
                'refund_method' => 'cash',
                'reason' => 'changed_mind',
                'shift_id' => $opdShift->getKey(),
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $return = SaleReturn::query()->sole();
        $this->assertSame([(int) $opdShift->getKey(), (int) $opd->getKey()], [(int) $return->shift_id, (int) $return->device_id]);
        $this->assertSame(50_000 - $return->refund_total, $shifts->expectedCash($opdShift));
        $this->assertSame(100_000 + $sale->grand_total, $shifts->expectedCash($general));
    }

    public function test_a_drawer_is_required_while_a_till_is_open(): void
    {
        app(ShiftService::class)->open($this->till, $this->cashier, 0);
        $sale = $this->cashSale();

        $this->actingAs($this->owner);
        Livewire::test(ViewSale::class, ['record' => $sale->getRouteKey()])
            ->callAction('return', data: [
                'items' => [['sale_item_id' => $sale->items->firstOrFail()->getKey(), 'quantity' => 1, 'restock' => true]],
                'refund_method' => 'cash',
                'reason' => 'changed_mind',
                'shift_id' => null,
            ])
            ->assertHasActionErrors(['shift_id' => 'required']);

        $this->assertSame(0, SaleReturn::query()->count());
    }

    public function test_with_no_till_open_it_warns_and_the_refund_belongs_to_no_drawer(): void
    {
        $sale = $this->cashSale();

        $this->actingAs($this->owner);
        Livewire::test(ViewSale::class, ['record' => $sale->getRouteKey()])
            ->mountAction('return')
            ->assertActionDataSet(['shift_id' => null])
            ->setActionData([
                'items' => [['sale_item_id' => $sale->items->firstOrFail()->getKey(), 'quantity' => 1, 'restock' => true]],
                'refund_method' => 'cash',
                'reason' => 'changed_mind',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertNull(SaleReturn::query()->sole()->shift_id);
        $this->assertNull(SaleReturn::query()->sole()->device_id);
    }

    public function test_returns_and_voids_are_audited(): void
    {
        $shift = app(ShiftService::class)->open($this->till, $this->cashier, 0);
        $returned = $this->cashSale();
        $return = app(ReturnService::class)->processLines(
            $returned,
            [new ReturnLine($returned->items->firstOrFail()->getKey(), 1)],
            ReturnReason::ChangedMind,
            PaymentMethod::Cash,
            $this->owner,
        );

        $entry = AuditLog::query()->where('event', 'sale_returned')->sole();
        $this->assertSame((int) $this->owner->getKey(), (int) $entry->user_id);
        $this->assertSame([$return->return_no, $returned->invoice_no, $return->refund_total, 'cash', 'changed_mind', $shift->getKey(), 'T1'], [
            $entry->new_values['return_no'], $entry->new_values['invoice_no'], $entry->new_values['refund_total'],
            $entry->new_values['refund_method'], $entry->new_values['reason'], $entry->new_values['shift_id'], $entry->new_values['till'],
        ]);
        $this->assertSame((int) $this->till->getKey(), (int) $return->device_id);

        $voided = $this->cashSale();
        app(CheckoutService::class)->void($voided, $this->owner, 'Rang up twice');

        $entry = AuditLog::query()->where('event', 'sale_voided')->sole();
        $this->assertSame((int) $this->owner->getKey(), (int) $entry->user_id);
        $this->assertSame((int) $voided->getKey(), (int) $entry->auditable_id);
        $this->assertSame(['completed', 'voided', 'Rang up twice', $voided->grand_total], [
            $entry->old_values['status'], $entry->new_values['status'], $entry->new_values['reason'], $entry->new_values['grand_total'],
        ]);
    }
}
