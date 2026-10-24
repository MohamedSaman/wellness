<?php

namespace Tests\Feature\Pos;

use App\Enums\DosageForm;
use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Enums\SaleStatus;
use App\Enums\StorageCondition;
use App\Exceptions\PosException;
use App\Filament\Resources\Sales\Pages\ViewSale;
use App\Models\SaleReturn;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PaymentInput;
use App\Services\Data\ReturnLine;
use App\Services\ReturnService;
use Filament\Facades\Filament;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;
use Tests\Feature\Pos\Concerns\WorksWithReceipts;

/**
 * Returns and refunds (docs/PHARMACY.md §9, branch 9): lines picked from the
 * bill, each in its sold unit or in base units, up to what was sold less what
 * already came back; the refund is what the line was charged (an OPD
 * medicine line: its stored share of the Medicines amount); each line's own
 * "Put back into stock?", off by default for fridge items, back to the batch
 * and location it was sold from; paid back by cash, card or bank transfer
 * with its reference; the owner only.
 */
class PharmacyReturnsTest extends BatchTestCase
{
    use WorksWithCheckout, WorksWithReceipts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stockTheClinic();
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);
    }

    private function returns(): ReturnService
    {
        return app(ReturnService::class);
    }

    public function test_an_opd_medicine_line_refunds_its_stored_share_not_its_list_price(): void
    {
        $sale = $this->opdBill();
        $capsules = $sale->items->firstWhere('sku', $this->amoxil->sku);
        $syrup = $sale->items->firstWhere('is_opd_medicine', true);
        $share = $capsules->line_total;

        // Weighed by list price (35,000 : 25,000) out of the Medicines amount of 85,000.
        $this->assertSame(85_000, $capsules->line_total + $syrup->line_total);
        $this->assertNotSame(35_000, $share);

        $first = $this->returns()->processLines($sale, [new ReturnLine($capsules->getKey(), 4)], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);
        $this->assertSame((int) round($share * 4 / 10), $first->refund_total);

        // The rest of the line and the syrup: the shares exactly, so the whole Medicines amount comes back.
        $rest = $this->returns()->processLines($sale, [
            new ReturnLine($capsules->getKey(), 6),
            new ReturnLine($syrup->getKey(), 1),
        ], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);

        $this->assertSame($share - $first->refund_total + $syrup->line_total, $rest->refund_total);
        $this->assertSame(85_000, $sale->fresh()?->refunded_total);
        $this->assertSame(SaleStatus::PartiallyReturned, $sale->fresh()?->status); // the consultation was not returned
    }

    public function test_tablets_come_back_from_a_box_in_base_units_on_the_return_form(): void
    {
        $sale = $this->billAtGeneral();
        $box = $sale->items->firstWhere('sku', $this->panadol->sku);
        $batch = $this->batch($this->panadol, 'PN2611');
        $this->assertSame(30.0, (float) $batch->quantity);

        $form = Livewire::test(ViewSale::class, ['record' => $sale->getRouteKey()])->mountAction('return');
        $rows = $this->formRows($form);
        $this->assertSame(['1 Box of 30 (30 Tablet)', '15 Capsule'], array_column($rows, 'remaining'));

        $form->setActionData(['items' => $this->withQuantities($rows, [$box->getKey() => [10, 'base']]), 'reason' => 'changed_mind', 'refund_method' => 'cash'])
            ->assertMountedActionModalSee('Refund: LKR 180.00')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $return = SaleReturn::query()->with('items')->sole();
        $this->assertSame(18_000, $return->refund_total);
        $this->assertSame(['0.333', '10.000'], [$return->items->sole()->quantity, $return->items->sole()->base_quantity]);
        $this->assertSame(40.0, (float) $batch->fresh()?->quantity);
        $this->assertLedgerMatches();

        // What is left is 20 tablets: one more is refused, 20 close the line exactly.
        $refused = Livewire::test(ViewSale::class, ['record' => $sale->getRouteKey()])->mountAction('return');
        $rows = $this->formRows($refused);
        $this->assertSame('0.667 Box of 30 (20 Tablet)', $rows[array_key_first($rows)]['remaining']);
        $refused->setActionData(['items' => $this->withQuantities($rows, [$box->getKey() => [21, 'base']]), 'reason' => 'changed_mind', 'refund_method' => 'cash'])
            ->assertMountedActionModalSee('Only 20 Tablet of PANADOL-TABLETS can still be returned.')
            ->callMountedAction();
        $this->assertSame(1, SaleReturn::query()->count());

        $last = $this->returns()->processLines($sale, [new ReturnLine($box->getKey(), 20, inBaseUnits: true)], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);
        $this->assertSame(36_000, $last->refund_total);
        $this->assertSame('1.000', $box->fresh()?->returned_quantity);
        $this->assertSame(54_000, (int) $box->returnItems()->sum('refund_amount'));
    }

    public function test_a_box_is_refused_in_part_boxes_but_whole_tablets_are_fine(): void
    {
        $sale = $this->billAtGeneral();
        $box = $sale->items->firstWhere('sku', $this->panadol->sku);

        $this->expectException(PosException::class);
        $this->returns()->processLines($sale, [new ReturnLine($box->getKey(), 0.5)], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);
    }

    public function test_a_fridge_item_is_not_put_back_by_default_and_each_line_decides(): void
    {
        $insulin = $this->drug('Insulatard Penfill', 100, 'IU/ml', 'Vial', [], 250_000, storage: StorageCondition::Fridge, form: DosageForm::Injection);
        $this->receive($insulin, 'IN0101', '05/2027', 5);
        $sale = app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->genTill(),
            cashier: $this->cashier,
            lines: [new CartLine($insulin->getKey(), 2), new CartLine($this->panadol->getKey(), 4)],
            payments: [new PaymentInput(PaymentMethod::Cash, 600_000)],
        ));
        [$vials, $tablets] = $sale->items->all();

        $form = Livewire::test(ViewSale::class, ['record' => $sale->getRouteKey()])->mountAction('return');
        $rows = $this->formRows($form);
        $this->assertSame([false, true], array_column($rows, 'restock'));
        $form->assertMountedActionModalSee('Fridge item or controlled drug: off unless you can vouch for it.');

        // Faulty goods: every line off; another reason: back to each line's own default.
        $form->setActionData(['reason' => 'faulty_damaged']);
        $this->assertSame([false, false], array_column($this->formRows($form), 'restock'));
        $form->setActionData(['reason' => 'changed_mind']);
        $rows = $this->formRows($form);
        $this->assertSame([false, true], array_column($rows, 'restock'));

        $form->setActionData(['items' => $this->withQuantities($rows, [$vials->getKey() => [1, 'sold'], $tablets->getKey() => [4, 'sold']]), 'refund_method' => 'cash'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $return = SaleReturn::query()->with('items')->sole();
        $this->assertSame([false, true], $return->items->sortBy('sale_item_id')->pluck('restocked')->values()->all());
        $this->assertSame(3.0, (float) $this->batch($insulin, 'IN0101')->quantity); // the vial stays out
        $this->assertSame(60.0, (float) $this->batch($this->panadol, 'PN2611')->quantity); // the tablets are back
        $this->assertSame(250_000 + 8_000, $return->refund_total);
        $this->assertLedgerMatches();
    }

    public function test_stock_goes_back_to_the_batch_and_location_it_was_sold_from(): void
    {
        $sale = $this->opdBill();
        $capsules = $sale->items->firstWhere('sku', $this->amoxil->sku);

        $this->returns()->processLines($sale, [new ReturnLine($capsules->getKey(), 4, restock: true)], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);

        $this->assertSame(['AX1102' => 44.0], $this->batchQuantities($this->amoxil, $this->doctors));
        $this->assertSame(['AX0921' => 5.0, 'AX1102' => 50.0], $this->batchQuantities($this->amoxil, $this->general));
        $this->assertLedgerMatches();
    }

    public function test_a_bank_transfer_refund_needs_its_reference_and_prints_it(): void
    {
        $sale = $this->billAtGeneral();
        $line = [new ReturnLine($sale->items->first()->getKey(), 1)];

        try {
            $this->returns()->processLines($sale, $line, ReturnReason::ChangedMind, PaymentMethod::BankTransfer, $this->owner, refundReference: '  ');
            $this->fail('A bank transfer refund without its reference was accepted.');
        } catch (PosException $exception) {
            $this->assertSame('Enter the bank transfer reference of the refund.', $exception->getMessage());
        }

        $return = $this->returns()->processLines($sale, $line, ReturnReason::ChangedMind, PaymentMethod::BankTransfer, $this->owner, refundReference: ' BOC 449001 ');
        $this->assertSame('BOC 449001', $return->refund_reference);

        $this->get(route('pos.receipt.refund', ['sale' => $sale, 'saleReturn' => $return]))->assertInertia(fn (Assert $page) => $page
            ->component('pos/Receipt')
            ->where('title', 'Refund '.$return->return_no)
            ->where('thermal.lines', fn ($lines): bool => collect($lines)->pluck('text')->contains('  Ref BOC 449001')));

        // A cash refund has no reference; a card refund may have one.
        $sale = $this->billAtGeneral();
        $this->assertNull($this->returns()->processLines($sale, [new ReturnLine($sale->items->first()->getKey(), 1)], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner, refundReference: 'x')->refund_reference);
        $this->assertNull($this->returns()->processLines($sale, [new ReturnLine($sale->items->last()->getKey(), 1)], ReturnReason::ChangedMind, PaymentMethod::Card, $this->owner)->refund_reference);
    }

    public function test_the_form_asks_for_the_bank_transfer_reference(): void
    {
        $sale = $this->billAtGeneral();
        $form = Livewire::test(ViewSale::class, ['record' => $sale->getRouteKey()])->mountAction('return');

        $form->setActionData(['items' => $this->withQuantities($this->formRows($form), [$sale->items->first()->getKey() => [1, 'sold']]), 'reason' => 'changed_mind', 'refund_method' => 'bank_transfer'])
            ->callMountedAction()
            ->assertHasActionErrors(['refund_reference' => 'required']);

        $this->assertSame(0, SaleReturn::query()->count());
    }

    public function test_only_users_who_may_process_returns_can(): void
    {
        $sale = $this->billAtGeneral();

        $this->expectExceptionMessage('You do not have permission to process returns.');
        $this->returns()->processLines($sale, [new ReturnLine($sale->items->first()->getKey(), 1)], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->cashier);
    }

    public function test_the_refund_slip_belongs_to_its_sale(): void
    {
        $sale = $this->billAtGeneral();
        $other = $this->billAtGeneral();
        $return = $this->returns()->processLines($sale, [new ReturnLine($sale->items->first()->getKey(), 1)], ReturnReason::ChangedMind, PaymentMethod::Cash, $this->owner);

        $this->get(route('pos.receipt.refund', ['sale' => $other, 'saleReturn' => $return]))->assertNotFound();
        // A cashier sees only their own sales' slips (sales.view): this bill is theirs.
        $this->actingAs($this->cashier)->get(route('pos.receipt.refund', ['sale' => $sale, 'saleReturn' => $return]))->assertOk();
        $this->actingAs($this->cashierAt())->get(route('pos.receipt.refund', ['sale' => $sale, 'saleReturn' => $return]))->assertForbidden();
    }

    /**
     * The return form's rows, keyed as the repeater keys them.
     *
     * @return array<string, array<string, mixed>>
     */
    private function formRows(mixed $form): array
    {
        $rows = [];
        $form->assertActionDataSet(function (array $state) use (&$rows): array {
            $rows = $state['items'];

            return [];
        });

        return $rows;
    }

    /**
     * The rows with quantities (and units) typed in, everything else as the form filled it.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @param  array<int, array{0: float|int, 1: string}>  $quantities  sale_item_id => [quantity, 'sold'|'base']
     * @return array<string, array<string, mixed>>
     */
    private function withQuantities(array $rows, array $quantities): array
    {
        return array_map(fn (array $row): array => [
            ...$row,
            'quantity' => $quantities[(int) $row['sale_item_id']][0] ?? 0,
            'unit' => $quantities[(int) $row['sale_item_id']][1] ?? 'sold',
        ], $rows);
    }
}
