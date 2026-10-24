<?php

namespace Tests\Feature\Pos;

use App\Enums\PayableStatus;
use App\Enums\SupplierPaymentMethod;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Filament\Resources\GoodsReceipts\Pages\CreateGoodsReceipt;
use App\Filament\Resources\GoodsReceipts\Pages\ListGoodsReceipts;
use App\Filament\Resources\GoodsReceipts\Pages\ViewGoodsReceipt;
use App\Filament\Resources\SupplierPayables\Pages\ListSupplierPayables;
use App\Filament\Resources\SupplierPayables\SupplierPayableResource;
use App\Filament\Resources\SupplierPayables\Widgets\PayablesAging;
use App\Filament\Resources\SupplierReturns\Pages\CreateSupplierReturn;
use App\Filament\Resources\Suppliers\Pages\ViewSupplier;
use App\Filament\Resources\Suppliers\RelationManagers\AllocationsRelationManager;
use App\Filament\Resources\Suppliers\RelationManagers\PayablesRelationManager;
use App\Filament\Resources\Suppliers\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Widgets\SupplierPayablesDue;
use App\Models\AuditLog;
use App\Models\GoodsReceipt;
use App\Models\Supplier;
use App\Models\SupplierAllocation;
use App\Models\SupplierPayable;
use App\Models\SupplierPayment;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Services\Data\ReceiptLine;
use App\Services\SupplierService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Pos\Concerns\WorksWithPurchasing;

/**
 * The payables screens (pharmacy clinic, today 2026-10-18): what is owed
 * across suppliers with aging and filters, paying invoices from the
 * supplier page and the Payables screen, allocating credit and undoing
 * allocations, due dates on the GRN page, the dashboard tile, the
 * printable statement, and who may see and do what.
 */
class AdminPayablesTest extends BatchTestCase
{
    use WorksWithPurchasing;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs($this->owner);
    }

    private function payableOf(GoodsReceipt $receipt): SupplierPayable
    {
        return SupplierPayable::query()->where('goods_receipt_id', $receipt->getKey())->sole();
    }

    /** @return array{0: Supplier, 1: Supplier, 2: array<string, GoodsReceipt>} two suppliers, invoices due from 40 days ago to next month */
    private function owed(): array
    {
        $item = $this->medicine();
        $hemas = $this->supplier();
        $spc = $this->supplier('State Pharmaceuticals');

        return [$hemas, $spc, [
            'late' => $this->bill($hemas, $item, 100_000, 'H-LATE', '2026-08-09'),   // due 08/09: 40 days late
            'week' => $this->bill($hemas, $item, 60_000, 'H-WEEK', '2026-09-21'),    // due 21/10: this week
            'later' => $this->bill($hemas, $item, 40_000, 'H-LATER', '2026-10-15'),  // due 14/11
            'spc' => $this->bill($spc, $item, 25_000, 'S-1', '2026-09-10'),          // due 10/10: 8 days late
        ]];
    }

    public function test_the_payables_screen_shows_what_is_owed_by_due_date_with_aging(): void
    {
        [$hemas, $spc, $grns] = $this->owed();
        $paid = $this->bill($hemas, $this->medicine('Amoxil 250 mg Capsules'), 5_000, 'H-PAID');
        app(SupplierService::class)->recordPayment($hemas, 5_000, SupplierPaymentMethod::Cash, $this->owner, allocations: [$this->payableOf($paid)->getKey() => 5_000]);
        $open = array_map(fn (GoodsReceipt $grn): SupplierPayable => $this->payableOf($grn), $grns);

        $this->get(SupplierPayableResource::getUrl('index'))->assertOk()->assertSee('Payables');

        Livewire::test(ListSupplierPayables::class)
            ->assertCanSeeTableRecords([$open['late'], $open['spc'], $open['week'], $open['later']], inOrder: true)
            ->assertCanNotSeeTableRecords([$this->payableOf($paid)])
            ->assertTableColumnStateSet('days_overdue', 40, $open['late'])
            ->assertTableColumnStateSet('outstanding', 100_000, $open['late'])
            ->filterTable('supplier_id', $spc->getKey())
            ->assertCanSeeTableRecords([$open['spc']])
            ->assertCanNotSeeTableRecords([$open['late']])
            ->removeTableFilters()
            ->filterTable('aging', '31_60')
            ->assertCanSeeTableRecords([$open['late']])
            ->assertCanNotSeeTableRecords([$open['spc'], $open['week']])
            ->removeTableFilters()
            ->filterTable('due_this_week')
            ->assertCanSeeTableRecords([$open['week']])
            ->assertCanNotSeeTableRecords([$open['later'], $open['late']])
            ->removeTableFilters()
            ->filterTable('overdue')
            ->assertCanSeeTableRecords([$open['late'], $open['spc']])
            ->assertCanNotSeeTableRecords([$open['week']])
            ->removeTableFilters()
            ->filterTable('status', [PayableStatus::Paid->value])
            ->assertCanSeeTableRecords([$this->payableOf($paid)]);

        // The aging totals follow the table: not yet due 1,000.00, 1–30 days 250.00, 31–60 days 1,000.00.
        Livewire::test(PayablesAging::class)
            ->assertSee(['Not yet due', 'LKR 1,000.00', '1–30 days', 'LKR 250.00', '31–60 days', 'Total outstanding', 'LKR 2,250.00']);
    }

    public function test_a_payment_from_the_payables_screen_pays_the_oldest_due_first(): void
    {
        [$hemas, , $grns] = $this->owed();

        Livewire::test(ListSupplierPayables::class)
            ->callAction('recordPayment', data: ['supplier_id' => $hemas->getKey(), 'amount' => '1200', 'paid_on' => '2026-10-18', 'method' => 'cheque', 'reference' => 'CHQ 77', 'allocate' => 'oldest'])
            ->assertHasNoActionErrors()
            ->assertNotified('Payment recorded');

        $this->assertSame([PayableStatus::Paid, PayableStatus::PartPaid, PayableStatus::Unpaid], [$this->payableOf($grns['late'])->status, $this->payableOf($grns['week'])->status, $this->payableOf($grns['later'])->status]);
        $this->assertSame(40_000, $this->payableOf($grns['week'])->outstanding());
        $this->assertSame('CHQ 77', SupplierPayment::query()->sole()->reference);

        // Moving a due date from the screen is audited.
        Livewire::test(ListSupplierPayables::class)
            ->callTableAction('changeDueDate', $this->payableOf($grns['later']), data: ['due_on' => '2026-12-15', 'reason' => 'Agreed with the rep'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('2026-12-15', $this->payableOf($grns['later'])->due_on->toDateString());
        $this->assertSame('Agreed with the rep', AuditLog::query()->where('event', 'due_date_changed')->sole()->new_values['reason'] ?? null);
    }

    public function test_a_payment_is_set_against_chosen_invoices_from_the_supplier_page(): void
    {
        [$hemas, , $grns] = $this->owed();

        Livewire::test(ViewSupplier::class, ['record' => $hemas->getRouteKey()])
            ->assertSee(['Unpaid invoices', 'LKR 2,000.00', 'Overdue', 'LKR 1,000.00'])
            ->mountAction('recordPayment')
            ->fillForm(['amount' => '700', 'paid_on' => '2026-10-18', 'method' => 'bank_transfer', 'allocate' => 'choose'])
            // Pre-filled oldest due first; the user moves it to the invoice due this week.
            ->assertSchemaStateSet(function (array $state) use ($grns): array {
                $rows = array_values($state['allocations']);
                $this->assertSame([$this->payableOf($grns['late'])->getKey(), '700.00'], [$rows[0]['payable_id'], $rows[0]['amount']]);
                $this->assertStringContainsString('GRN-000001 · invoice H-LATE · due 08/09/2026 (40 days late) · LKR 1,000.00 owed', $rows[0]['label']);

                return [];
            })
            ->fillForm(['allocations' => [
                'p'.$this->payableOf($grns['late'])->getKey() => ['payable_id' => $this->payableOf($grns['late'])->getKey(), 'label' => 'x', 'amount' => null],
                'p'.$this->payableOf($grns['week'])->getKey() => ['payable_id' => $this->payableOf($grns['week'])->getKey(), 'label' => 'x', 'amount' => '600'],
                'p'.$this->payableOf($grns['later'])->getKey() => ['payable_id' => $this->payableOf($grns['later'])->getKey(), 'label' => 'x', 'amount' => null],
            ]])
            ->assertMountedActionModalSee('Pays LKR 600.00 on 1 invoice; LKR 100.00 stays as credit.')
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Payment recorded');

        $this->assertSame([PayableStatus::Unpaid, PayableStatus::Paid], [$this->payableOf($grns['late'])->status, $this->payableOf($grns['week'])->status]);

        // The 100.00 left is allocated later, oldest due first.
        $page = Livewire::test(ViewSupplier::class, ['record' => $hemas->getRouteKey()])
            ->assertSee(['Credit to allocate', 'LKR 100.00'])
            ->assertActionVisible('allocateCredit')
            ->callAction('allocateCredit', data: ['allocate' => 'oldest'])
            ->assertHasNoActionErrors();
        $this->assertSame(90_000, $this->payableOf($grns['late'])->outstanding());
        Livewire::test(ViewSupplier::class, ['record' => $hemas->getRouteKey()])->assertActionHidden('allocateCredit');

        // An allocation to the wrong invoice is undone from the Allocations tab.
        $allocation = SupplierAllocation::query()->where('supplier_payable_id', $this->payableOf($grns['late'])->getKey())->sole();
        Livewire::test(AllocationsRelationManager::class, ['ownerRecord' => $hemas, 'pageClass' => ViewSupplier::class])
            ->assertCanSeeTableRecords([$allocation])
            ->callTableAction('undoAllocation', $allocation, data: ['reason' => 'Wrong invoice'])
            ->assertHasNoTableActionErrors();
        $this->assertSame(100_000, $this->payableOf($grns['late'])->outstanding());

        Livewire::test(PayablesRelationManager::class, ['ownerRecord' => $hemas, 'pageClass' => ViewSupplier::class])
            ->assertCanSeeTableRecords([$this->payableOf($grns['late']), $this->payableOf($grns['later'])])
            ->assertCanNotSeeTableRecords([$this->payableOf($grns['week'])]);
        Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $hemas, 'pageClass' => ViewSupplier::class])
            ->assertTableColumnStateSet('unallocated', 10_000, SupplierPayment::query()->sole());
    }

    public function test_a_lump_sum_opening_balance_is_due_after_the_supplier_terms_unless_changed(): void
    {
        $hemas = $this->supplier();  // 30 days' terms

        // The due date follows the as-of date + the terms, and can be changed before saving.
        Livewire::test(ViewSupplier::class, ['record' => $hemas->getRouteKey()])
            ->mountAction('openingBalance')
            ->assertSchemaStateSet(['as_of' => '2026-10-18', 'due_on' => '2026-11-17'])
            ->fillForm(['amount' => '1500', 'as_of' => '2026-10-01'])
            ->assertSchemaStateSet(['due_on' => '2026-10-31'])
            ->fillForm(['due_on' => '2026-11-15'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(['2026-11-15', 150_000], [SupplierPayable::query()->sole()->due_on->toDateString(), SupplierPayable::query()->sole()->amount]);
    }

    public function test_opening_invoices_are_entered_paid_and_reversed_from_the_supplier_page(): void
    {
        $hemas = $this->supplier();

        Livewire::test(ViewSupplier::class, ['record' => $hemas->getRouteKey()])
            ->callAction('openingInvoice', data: ['invoice_no' => 'OLD-7', 'invoice_date' => '2026-09-20', 'amount' => '300', 'as_of' => '2026-10-01'])
            ->assertHasNoActionErrors()
            ->assertNotified('Opening invoice OLD-7 entered');

        $payable = SupplierPayable::query()->sole();
        $this->assertSame(['OLD-7', '2026-10-20', 30_000], [$payable->invoice_no, $payable->due_on->toDateString(), $payable->amount]);

        app(SupplierService::class)->recordPayment($hemas, 10_000, SupplierPaymentMethod::Cash, $this->owner);
        $this->assertSame(PayableStatus::PartPaid, $payable->fresh()?->status);

        Livewire::test(PayablesRelationManager::class, ['ownerRecord' => $hemas, 'pageClass' => ViewSupplier::class])
            ->assertTableColumnStateSet('document', 'Opening invoice', $payable)
            ->callTableAction('reverseOpeningInvoice', $payable, data: ['reason' => 'Paid before go-live'])
            ->assertHasNoTableActionErrors();

        $this->assertSame([PayableStatus::Reversed, 10_000], [$payable->fresh()?->status, app(SupplierService::class)->reconcile($hemas)['credit']]);
    }

    public function test_a_grn_shows_its_payment_and_its_due_date_is_moved_there(): void
    {
        $panadol = $this->medicine();
        $hemas = $this->supplier();

        // The draft form says when it will be due.
        Livewire::test(CreateGoodsReceipt::class)
            ->fillForm(['supplier_id' => $hemas->getKey(), 'supplier_invoice_date' => '2026-10-10'])
            ->assertSee('Due 09/11/2026 (30 days\' terms from the invoice date).');

        $grn = $this->bill($hemas, $panadol, 100_000, 'HPL-1', '2026-10-10');
        app(SupplierService::class)->recordPayment($hemas, 40_000, SupplierPaymentMethod::Cash, $this->owner, reference: 'R-1');

        Livewire::test(ViewGoodsReceipt::class, ['record' => $grn->getRouteKey()])
            ->assertSee(['Payment', 'Part paid', 'Nov 9, 2026', 'LKR 600.00', 'Payment 18/10/2026 R-1: LKR 400.00'])
            ->callAction('changeDueDate', data: ['due_on' => '2026-11-30', 'reason' => ''])
            ->assertHasActionErrors(['reason' => 'required']);
        $this->assertSame('2026-11-09', $this->payableOf($grn)->due_on->toDateString(), 'No reason, no change.');

        Livewire::test(ViewGoodsReceipt::class, ['record' => $grn->getRouteKey()])
            ->callAction('changeDueDate', data: ['due_on' => '2026-11-30', 'reason' => 'Rep agreed on the phone'])
            ->assertHasNoActionErrors();
        $this->assertSame('2026-11-30', $this->payableOf($grn)->due_on->toDateString());

        Livewire::test(ListGoodsReceipts::class)
            ->assertTableColumnStateSet('payment', 'Part paid', $grn)
            ->filterTable('payment', PayableStatus::Unpaid->value)
            ->assertCanNotSeeTableRecords([$grn]);
    }

    public function test_a_return_picked_against_a_grn_reduces_what_it_owes(): void
    {
        $panadol = $this->medicine();
        $hemas = $this->supplier();
        $grn = $this->postReceipt($hemas, [new ReceiptLine($panadol->getKey(), 10, unitCost: 1_000, batchNo: 'A', expiry: '12/2027')], invoiceNo: 'HPL-1');
        $other = $this->bill($hemas, $panadol, 50_000, 'HPL-2');

        Livewire::test(CreateSupplierReturn::class)
            ->fillForm([
                'supplier_id' => $hemas->getKey(),
                'location_id' => $this->general->getKey(),
                'goods_receipt_id' => $other->getKey(),
                'reason' => 'damaged',
                'returned_on' => '2026-10-18',
                'lines' => [['variant_id' => $panadol->getKey(), 'batch_id' => $grn->items->sole()->stock_batch_id, 'quantity' => 2]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($other->getKey(), SupplierReturn::query()->sole()->goods_receipt_id);
        $this->assertSame([10_000, 48_000], [$this->payableOf($grn)->outstanding(), $this->payableOf($other)->outstanding()]);
    }

    public function test_the_dashboard_shows_what_is_overdue_and_due_this_week(): void
    {
        $this->owed();

        $this->get(Dashboard::getUrl())->assertOk()->assertSeeLivewire(SupplierPayablesDue::class);

        Livewire::test(SupplierPayablesDue::class)
            ->assertSee(['Overdue to suppliers', 'LKR 1,250.00', '2 invoices past the due date', 'Due this week', 'LKR 600.00', '1 invoice due by Sat 24/10']);
    }

    public function test_the_statement_prints_the_running_balance(): void
    {
        [$hemas] = $this->owed();
        app(SupplierService::class)->recordPayment($hemas, 30_000, SupplierPaymentMethod::BankTransfer, $this->owner, reference: 'TRF 9');

        Livewire::test(ViewSupplier::class, ['record' => $hemas->getRouteKey()])
            ->callAction('statement', data: ['from' => '2026-10-01', 'to' => '2026-10-18'])
            ->assertHasNoActionErrors();

        $this->get(route('purchasing.print.statement', ['supplier' => $hemas, 'from' => '2026-10-01', 'to' => '2026-10-18']))
            ->assertOk()
            ->assertSeeInOrder(['Supplier statement', 'Hemas Pharmaceuticals', 'Date', 'Invoice date', 'Document', 'Balance brought forward', '18/10/2026', '09/08/2026', 'GRN-000001', 'Invoice H-LATE', 'LKR 1,000.00', 'Payment', 'Bank transfer TRF 9', 'LKR 1,700.00', 'Balance at 18/10/2026', 'LKR 1,700.00', 'Unpaid invoices at 18/10/2026', 'H-LATE', '08/09/2026', '40'])
            ->assertDontSee('S-1');

        $this->get(route('purchasing.print.statement', ['supplier' => $hemas, 'from' => '2026-10-18', 'to' => '2026-10-01']))->assertStatus(422);
    }

    public function test_who_sees_and_pays_what(): void
    {
        [$hemas, , $grns] = $this->owed();
        $statement = route('purchasing.print.statement', ['supplier' => $hemas]);

        // A cashier sees nothing of it.
        $this->actingAs($this->cashier);
        $this->get(SupplierPayableResource::getUrl('index'))->assertForbidden();
        $this->get($statement)->assertForbidden();
        $this->assertFalse(SupplierPayablesDue::canView());

        // Someone who reads payables (payables.view) sees the screen, balances, statements and the tile, and pays nothing.
        $books = User::factory()->create(['email' => 'books@clinic.test']);
        $books->forceFill(['shop_id' => $this->shop->getKey()])->save();
        $books->givePermissionTo(['admin.access', 'payables.view']);
        $this->actingAs($books);

        $this->get(SupplierPayableResource::getUrl('index'))->assertOk();
        $this->get(SupplierResource::getUrl('view', ['record' => $hemas]))->assertOk()->assertSee('Balance owed');
        $this->get($statement)->assertOk();
        $this->assertTrue(SupplierPayablesDue::canView());
        $this->get(GoodsReceiptResource::getUrl('view', ['record' => $grns['late']]))->assertForbidden();

        Livewire::test(ListSupplierPayables::class)
            ->assertActionHidden('recordPayment')
            ->assertTableActionHidden('changeDueDate', $this->payableOf($grns['late']));
        Livewire::test(ViewSupplier::class, ['record' => $hemas->getRouteKey()])
            ->assertActionHidden('recordPayment')
            ->assertActionHidden('edit')
            ->assertActionVisible('statement');
    }
}
