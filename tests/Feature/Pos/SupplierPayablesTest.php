<?php

namespace Tests\Feature\Pos;

use App\Enums\PayableStatus;
use App\Enums\SupplierPaymentMethod;
use App\Enums\SupplierReturnReason;
use App\Exceptions\PosException;
use App\Models\AuditLog;
use App\Models\GoodsReceipt;
use App\Models\Supplier;
use App\Models\SupplierAllocation;
use App\Models\SupplierCredit;
use App\Models\SupplierPayable;
use App\Models\SupplierPayment;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Services\Data\ReceiptLine;
use App\Services\Data\SupplierReturnLine;
use App\Services\GoodsReceiptService;
use App\Services\SupplierPayablesService;
use App\Services\SupplierReturnService;
use App\Services\SupplierService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Pos\Concerns\WorksWithPurchasing;

/**
 * Supplier payables (branch 6b): every posted GRN and opening entry is an
 * invoice with a due date, payments and credits are allocated to them
 * (oldest due first unless picked), and the supplier's balance always
 * equals what is outstanding less the credit left. Today is 2026-10-18.
 */
class SupplierPayablesTest extends BatchTestCase
{
    use WorksWithPurchasing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->owner);
    }

    private function payables(): SupplierPayablesService
    {
        return app(SupplierPayablesService::class);
    }

    private function suppliers(): SupplierService
    {
        return app(SupplierService::class);
    }

    /** @param  array<int, int>|null  $allocations */
    private function pay(Supplier $supplier, int $amount, ?array $allocations = null, ?string $paidOn = null, ?User $user = null): SupplierPayment
    {
        return $this->suppliers()->recordPayment($supplier, $amount, SupplierPaymentMethod::BankTransfer, $user ?? $this->owner, $paidOn, 'TRF', allocations: $allocations);
    }

    private function payableOf(GoodsReceipt $receipt): SupplierPayable
    {
        return SupplierPayable::query()->where('goods_receipt_id', $receipt->getKey())->sole();
    }

    /** @return array{0: PayableStatus, 1: int} status and outstanding */
    private function state(GoodsReceipt|SupplierPayable $item): array
    {
        $payable = $item instanceof GoodsReceipt ? $this->payableOf($item) : $item->fresh();

        return [$payable?->status, $payable?->outstanding()];
    }

    private function creditOf(SupplierPayment $payment): SupplierCredit
    {
        return SupplierCredit::query()->where('supplier_payment_id', $payment->getKey())->sole();
    }

    /** The balance equals the open invoices' outstanding less the credit left, worked out both ways. */
    private function assertBalanced(Supplier ...$suppliers): void
    {
        foreach ($suppliers as $supplier) {
            $check = $this->suppliers()->reconcile($supplier);
            $outstanding = (int) SupplierPayable::query()->where('supplier_id', $supplier->getKey())->get()->sum(fn (SupplierPayable $payable): int => $payable->outstanding());
            $credit = (int) SupplierCredit::query()->where('supplier_id', $supplier->getKey())->get()->sum(fn (SupplierCredit $credit): int => $credit->unallocated());
            $allocations = (int) SupplierAllocation::query()->where('supplier_id', $supplier->getKey())->sum('amount');

            $this->assertSame(0, $check['difference'], "{$supplier->name}: balance {$check['balance']}, outstanding {$check['outstanding']}, credit {$check['credit']}");
            $this->assertSame([$check['outstanding'], $check['credit']], [$outstanding, $credit]);
            $this->assertSame($check['balance'], $outstanding - $credit);
            // The counters on both sides are the sums of the allocation rows.
            $this->assertSame($allocations, (int) SupplierPayable::query()->where('supplier_id', $supplier->getKey())->sum('allocated'));
            $this->assertSame($allocations, (int) SupplierCredit::query()->where('supplier_id', $supplier->getKey())->sum('allocated'));
        }
    }

    /** @param  callable(): mixed  $attempt */
    private function refused(callable $attempt, string $message): void
    {
        try {
            $attempt();
            $this->fail("Expected: {$message}");
        } catch (PosException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    public function test_a_posted_grn_is_due_on_its_invoice_date_plus_the_supplier_terms(): void
    {
        $item = $this->medicine();
        $hemas = $this->supplier();
        $cash = $this->supplier('Cash Chemists', ['payment_terms_days' => null]);

        $invoiced = $this->bill($hemas, $item, 100_000, 'HPL-1', '2026-10-10', '2026-10-12');
        $noInvoiceDate = $this->bill($hemas, $item, 50_000, receivedOn: '2026-10-15');
        $cod = $this->bill($cash, $item, 20_000);
        $draft = $this->draftReceipt($hemas, [new ReceiptLine($item->getKey(), 1, unitCost: 5_000, batchNo: 'D', expiry: '12/2027')]);

        $payable = $this->payableOf($invoiced);
        $this->assertSame(
            ['HPL-1', '2026-10-10', '2026-10-12', '2026-11-09', 100_000, 0, PayableStatus::Unpaid, 100_000, 'GRN-000001'],
            [$payable->invoice_no, $payable->invoice_date?->toDateString(), $payable->document_date->toDateString(), $payable->due_on->toDateString(), $payable->amount, $payable->allocated, $payable->status, $payable->outstanding(), $payable->documentLabel()],
        );
        $this->assertSame('2026-11-14', $this->payableOf($noInvoiceDate)->due_on->toDateString(), 'No invoice date: from the day received.');
        $this->assertSame('2026-10-18', $this->payableOf($cod)->due_on->toDateString(), 'No terms: due the day it came.');
        $this->assertNull($draft->payable, 'A draft owes nothing.');
        $this->assertSame(['outstanding' => 150_000, 'overdue' => 0, 'credit' => 0], $this->payables()->summary((int) $hemas->getKey()));

        // New terms apply to new deliveries, not to due dates already set.
        $this->suppliers()->update($hemas, ['payment_terms_days' => 60], $this->owner);
        $this->assertSame('2026-11-09', $payable->fresh()?->due_on->toDateString());
        $this->assertSame('2026-12-17', $this->payableOf($this->bill($hemas, $item, 1_000))->due_on->toDateString());
        $this->assertBalanced($hemas, $cash);
    }

    public function test_a_due_date_is_moved_by_purchasing_managers_and_audited(): void
    {
        $payable = $this->payableOf($this->bill($this->supplier(), $this->medicine(), 100_000, 'HPL-1', '2026-10-10'));

        $this->payables()->changeDueDate($payable, '30/11/2026', '  Agreed with Nimal ', $this->owner);

        $this->assertSame('2026-11-30', $payable->fresh()?->due_on->toDateString());
        $audit = AuditLog::query()->where('event', 'due_date_changed')->sole();
        $this->assertSame([$payable->getMorphClass(), $payable->getKey(), $this->owner->getKey()], [$audit->auditable_type, $audit->auditable_id, $audit->user_id]);
        $this->assertSameIgnoringKeyOrder(['due_on' => '2026-11-09'], $audit->old_values);
        $this->assertSameIgnoringKeyOrder(['due_on' => '2026-11-30', 'reason' => 'Agreed with Nimal'], $audit->new_values);

        $this->refused(fn () => $this->payables()->changeDueDate($payable, '2026-10-09', 'Agreed', $this->owner), 'can\'t be before the invoice date');
        $this->refused(fn () => $this->payables()->changeDueDate($payable, '2026-11-30', 'Agreed', $this->owner), 'already due on 30/11/2026');
        $this->refused(fn () => $this->payables()->changeDueDate($payable, 'soon', 'Agreed', $this->owner), 'is not a date');
        $this->refused(fn () => $this->payables()->changeDueDate($payable, '2026-12-01', '   ', $this->owner), 'Say why the due date is changing');
        $this->refused(fn () => $this->payables()->changeDueDate($payable, '2026-12-01', str_repeat('x', 201), $this->owner), 'at most 200 characters');
        $this->assertSame('2026-11-30', $payable->fresh()?->due_on->toDateString(), 'Refused changes leave the date as it was.');
        $this->refused(fn () => $this->payables()->changeDueDate($payable, '2026-12-01', 'Agreed', $this->cashier), 'permission');

        $this->pay($payable->supplier, 100_000);
        $this->refused(fn () => $this->payables()->changeDueDate($payable, '2026-12-01', 'Agreed', $this->owner), 'Only an unpaid or part-paid invoice');

        // Payments only move the counters: no audit noise on the invoice itself.
        $this->assertSame(1, AuditLog::query()->where('auditable_type', $payable->getMorphClass())->count());
    }

    public function test_a_payment_pays_the_oldest_due_first_and_the_rest_stays_as_credit(): void
    {
        $item = $this->medicine();
        $hemas = $this->supplier();
        $a = $this->bill($hemas, $item, 100_000, 'A', '2026-09-01'); // due 01/10
        $b = $this->bill($hemas, $item, 60_000, 'B', '2026-10-01');  // due 31/10
        $c = $this->bill($hemas, $item, 40_000, 'C', '2026-09-15');  // due 15/10

        $first = $this->pay($hemas, 150_000);

        $this->assertSame([[PayableStatus::Paid, 0], [PayableStatus::Paid, 0], [PayableStatus::PartPaid, 50_000]], [$this->state($a), $this->state($c), $this->state($b)]);
        $this->assertSame(0, $this->creditOf($first)->unallocated());
        $this->assertSame([3, 150_000], [SupplierAllocation::query()->count(), (int) SupplierAllocation::query()->sum('amount')]);
        $this->assertSame('2026-10-18', SupplierAllocation::query()->firstOrFail()->allocated_on->toDateString());

        // Paying more than is owed: the rest is the supplier's credit.
        $second = $this->pay($hemas, 80_000);
        $this->assertSame([PayableStatus::Paid, 0], $this->state($b));
        $this->assertSame(30_000, $this->creditOf($second)->unallocated());
        $this->assertSame(['outstanding' => 0, 'overdue' => 0, 'credit' => 30_000], $this->payables()->summary((int) $hemas->getKey()));
        $this->assertSame(-30_000, $this->suppliers()->balance($hemas)['balance']);

        // A new invoice is not paid from the credit until someone allocates it.
        $d = $this->bill($hemas, $item, 25_000, 'D');
        $this->assertSame([PayableStatus::Unpaid, 25_000], $this->state($d));
        $this->assertBalanced($hemas);

        $this->payables()->allocate($this->creditOf($second), null, $this->owner);

        $this->assertSame([PayableStatus::Paid, 0], $this->state($d));
        $this->assertSame(5_000, $this->creditOf($second)->unallocated());
        $this->assertSame([$this->creditOf($second)->getKey()], array_map(fn (SupplierCredit $credit): int => (int) $credit->getKey(), $this->payables()->openCredits((int) $hemas->getKey())->all()));
        $this->refused(fn () => $this->payables()->allocate($this->creditOf($second), null, $this->owner), 'has no invoice');
        $this->refused(fn () => $this->payables()->allocate($this->creditOf($first), null, $this->owner), 'Nothing is left of that credit');
        $this->assertBalanced($hemas);
    }

    public function test_a_payment_is_allocated_by_hand_part_payments_too(): void
    {
        $item = $this->medicine();
        $hemas = $this->supplier();
        $spc = $this->supplier('State Pharmaceuticals');
        $a = $this->bill($hemas, $item, 100_000, 'A', '2026-09-01');
        $b = $this->bill($hemas, $item, 60_000, 'B', '2026-10-01');
        $theirs = $this->bill($spc, $item, 10_000);

        // The newer invoice first, in part: the older one stays unpaid, the rest is credit.
        $payment = $this->pay($hemas, 50_000, [$this->payableOf($b)->getKey() => 30_000]);

        $this->assertSame([[PayableStatus::Unpaid, 100_000], [PayableStatus::PartPaid, 30_000]], [$this->state($a), $this->state($b)]);
        $this->assertSame(20_000, $this->creditOf($payment)->unallocated());

        $count = SupplierPayment::query()->count();
        $this->refused(fn () => $this->pay($hemas, 200_000, [$this->payableOf($a)->getKey() => 100_001]), 'Only LKR 1,000.00 is outstanding on GRN-000001 (invoice A)');
        $this->refused(fn () => $this->pay($hemas, 50_000, [$this->payableOf($a)->getKey() => 60_000]), 'more than is left to allocate: LKR 500.00');
        $this->refused(fn () => $this->pay($hemas, 50_000, [$this->payableOf($theirs)->getKey() => 10_000]), 'not owed to this supplier');
        $this->refused(fn () => $this->pay($hemas, 50_000, [$this->payableOf($a)->getKey() => -1]), 'below zero');
        $this->assertSame($count, SupplierPayment::query()->count(), 'A refused payment is not recorded.');

        // Nothing allocated: all of it is credit.
        $advance = $this->pay($hemas, 10_000, []);
        $this->assertSame(10_000, $this->creditOf($advance)->unallocated());

        // Credit is allocated later by hand, across several invoices.
        $this->payables()->allocate($this->creditOf($payment), [$this->payableOf($a)->getKey() => 15_000, $this->payableOf($b)->getKey() => 5_000], $this->owner);
        $this->assertSame([[PayableStatus::PartPaid, 85_000], [PayableStatus::PartPaid, 25_000]], [$this->state($a), $this->state($b)]);
        $this->refused(fn () => $this->payables()->allocate($this->creditOf($advance), [$this->payableOf($a)->getKey() => 0], $this->owner), 'Say how much');
        $this->assertBalanced($hemas, $spc);
    }

    public function test_reversing_a_payment_reverses_its_allocations_and_an_allocation_can_be_undone(): void
    {
        $item = $this->medicine();
        $hemas = $this->supplier();
        $a = $this->bill($hemas, $item, 100_000, 'A', '2026-09-01');
        $b = $this->bill($hemas, $item, 60_000, 'B', '2026-10-01');

        $wrong = $this->pay($hemas, 130_000);
        $this->assertSame([[PayableStatus::Paid, 0], [PayableStatus::PartPaid, 30_000]], [$this->state($a), $this->state($b)]);

        $this->suppliers()->reversePayment($wrong, 'Bounced', $this->owner);

        $this->assertSame([[PayableStatus::Unpaid, 100_000], [PayableStatus::Unpaid, 60_000]], [$this->state($a), $this->state($b)]);
        $this->assertTrue($this->creditOf($wrong)->is_reversed);
        $this->assertSame(0, $this->creditOf($wrong)->unallocated());
        $this->assertSame([100_000, 30_000, -100_000, -30_000], SupplierAllocation::query()->orderBy('id')->pluck('amount')->all());
        $this->assertSame('Payment of 18/10/2026 reversed', SupplierAllocation::query()->whereNotNull('reversal_of_id')->firstOrFail()->note);
        $this->assertNull(SupplierCredit::query()->whereHas('payment', fn ($query) => $query->whereNotNull('reversal_of_id'))->first(), 'The reversing payment is no credit.');
        $this->assertBalanced($hemas);

        // An allocation to the wrong invoice is undone and made again.
        $right = $this->pay($hemas, 100_000);
        $allocation = SupplierAllocation::query()->where('supplier_credit_id', $this->creditOf($right)->getKey())->sole();
        $this->assertTrue($allocation->payable->is($this->payableOf($a)));

        $this->refused(fn () => $this->payables()->reverseAllocation($allocation, ' ', $this->owner), 'Say why');
        $undo = $this->payables()->reverseAllocation($allocation, 'Meant for invoice B', $this->owner);

        $this->assertSame([-100_000, 'Undone: Meant for invoice B'], [$undo->amount, $undo->note]);
        $this->assertSame([PayableStatus::Unpaid, 100_000], $this->state($a));
        $this->assertSame(100_000, $this->creditOf($right)->unallocated());
        $this->refused(fn () => $this->payables()->reverseAllocation($allocation, 'Again', $this->owner), 'already been undone');
        $this->refused(fn () => $this->payables()->reverseAllocation($undo, 'Undo the undo', $this->owner), 'itself an undo');

        $this->payables()->allocate($this->creditOf($right), [$this->payableOf($b)->getKey() => 60_000, $this->payableOf($a)->getKey() => 40_000], $this->owner);
        $this->assertSame([[PayableStatus::PartPaid, 60_000], [PayableStatus::Paid, 0]], [$this->state($a), $this->state($b)]);

        // Allocations are never edited or deleted.
        foreach ([fn () => $allocation->update(['amount' => 1]), fn () => $allocation->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('An allocation was edited or deleted.');
            } catch (\LogicException) {
                // expected
            }
        }

        $this->assertSame(-100_000, AuditLog::query()->where('auditable_type', $undo->getMorphClass())->where('auditable_id', $undo->getKey())->where('event', 'created')->sole()->new_values['amount'] ?? null);
        $this->assertBalanced($hemas);
    }

    public function test_opening_balances_and_opening_invoices_are_paid_like_grns(): void
    {
        $item = $this->medicine();
        $hemas = $this->supplier();

        $lump = $this->suppliers()->recordOpeningBalance($hemas, 50_000, $this->owner, '2026-10-01', 'Old books');
        $old7 = $this->suppliers()->recordOpeningBalance($hemas, 30_000, $this->owner, '2026-10-01', invoiceNo: 'OLD-7', invoiceDate: '2026-09-20');
        $old8 = $this->suppliers()->recordOpeningBalance($hemas, 20_000, $this->owner, '2026-10-01', invoiceNo: 'OLD-8', invoiceDate: '2026-09-25', dueOn: '2026-11-30');
        $grn = $this->bill($hemas, $item, 40_000, 'NEW-1', '2026-10-18');

        $open = fn ($entry): SupplierPayable => SupplierPayable::query()->where('supplier_opening_balance_id', $entry->getKey())->sole();
        // A lump sum is due on its as-of date + the supplier's 30 days' terms.
        $this->assertSame(['Opening balance', null, '2026-10-31', '2026-10-31', 50_000], [$open($lump)->documentLabel(), $open($lump)->invoice_no, $lump->due_on?->toDateString(), $open($lump)->due_on->toDateString(), $open($lump)->amount]);
        $this->assertSame(['Opening invoice', 'OLD-7', '2026-09-20', '2026-10-20'], [$open($old7)->documentLabel(), $open($old7)->invoice_no, $open($old7)->invoice_date?->toDateString(), $open($old7)->due_on->toDateString()]);
        $this->assertSame('2026-11-30', $open($old8)->due_on->toDateString());
        $this->assertSame(140_000, $this->suppliers()->balance($hemas)['balance']);
        $this->assertTrue($this->suppliers()->openingBalance($hemas)?->is($lump), 'The lump sum is the opening balance; invoices are listed apart.');

        $this->refused(fn () => $this->suppliers()->recordOpeningBalance($hemas, 1_000, $this->owner, invoiceNo: 'old-7'), 'already has opening invoice old-7');
        $this->refused(fn () => $this->suppliers()->recordOpeningBalance($hemas, -1_000, $this->owner, invoiceNo: 'OLD-9'), 'An opening invoice is an amount owed');
        $this->refused(fn () => $this->suppliers()->recordOpeningBalance($hemas, 1_000, $this->owner, invoiceNo: 'OLD-9', invoiceDate: '2026-10-10', dueOn: '2026-10-01'), 'can\'t be before the invoice date');
        $this->refused(fn () => $this->suppliers()->recordOpeningBalance($hemas, 1_000, $this->owner, invoiceDate: '2026-10-10'), 'invoice number with its date');
        $this->refused(fn () => $this->suppliers()->recordOpeningBalance($hemas, 1_000, $this->owner), 'already has an opening balance');

        // Oldest due first: OLD-7 (20/10), the lump sum (31/10), the GRN (17/11), OLD-8 (30/11).
        $this->pay($hemas, 90_000);
        $this->assertSame([[PayableStatus::Paid, 0], [PayableStatus::Paid, 0], [PayableStatus::PartPaid, 30_000], [PayableStatus::Unpaid, 20_000]], [$this->state($open($lump)), $this->state($open($old7)), $this->state($grn), $this->state($open($old8))]);

        // Reversing a paid opening invoice: what paid it is credit again; its number can be entered again.
        $this->suppliers()->reverseOpeningBalance($old7, 'Already paid before go-live', $this->owner);
        $this->assertSame([PayableStatus::Reversed, 0], $this->state($open($old7)));
        $this->assertSame(['outstanding' => 50_000, 'overdue' => 0, 'credit' => 30_000], $this->payables()->summary((int) $hemas->getKey()));
        $this->suppliers()->recordOpeningBalance($hemas, 3_000, $this->owner, '2026-10-01', invoiceNo: 'OLD-7', invoiceDate: '2026-09-20');
        $this->assertBalanced($hemas);

        // The lump sum's due date can be entered, earlier too (it may have been due before go-live); no terms: due on the as-of date.
        $nimal = $this->supplier('Nimal Distributors', ['payment_terms_days' => null]);
        $this->assertSame('2026-10-01', $this->suppliers()->recordOpeningBalance($nimal, 9_000, $this->owner, '2026-10-01')->due_on?->toDateString());
        $kandy = $this->supplier('Kandy Medicals', ['payment_terms_days' => 60]);
        $early = $this->suppliers()->recordOpeningBalance($kandy, 9_000, $this->owner, '2026-10-01', dueOn: '2026-09-15');
        $this->assertSame(['2026-09-15', '2026-09-15'], [$early->due_on?->toDateString(), $open($early)->due_on->toDateString()]);

        // A credit the supplier owed the shop at go-live is credit to allocate.
        $spc = $this->supplier('State Pharmaceuticals');
        $credit = $this->suppliers()->recordOpeningBalance($spc, -15_000, $this->owner, '2026-10-01', dueOn: '2026-10-05');
        $this->assertSame([15_000, null], [SupplierCredit::query()->where('supplier_opening_balance_id', $credit->getKey())->sole()->amount, $credit->due_on]);
        $theirs = $this->bill($spc, $this->medicine('Amoxil 250 mg Capsules'), 10_000);
        $this->payables()->allocate(SupplierCredit::query()->where('supplier_opening_balance_id', $credit->getKey())->sole(), null, $this->owner);
        $this->assertSame([PayableStatus::Paid, 0], $this->state($theirs));
        $this->assertSame(-5_000, $this->suppliers()->balance($spc)['balance']);
        $this->assertBalanced($hemas, $spc);
    }

    public function test_a_reversed_grn_owes_nothing_and_what_was_paid_on_it_is_credit_again(): void
    {
        $hemas = $this->supplier();
        $grn = $this->bill($hemas, $this->medicine(), 100_000, 'A');
        $payment = $this->pay($hemas, 60_000);

        $reversal = app(GoodsReceiptService::class)->reverse($grn, 'Entered twice', $this->owner);

        $this->assertSame([PayableStatus::Reversed, 0], $this->state($grn));
        $this->assertNull($reversal->payable, 'The reversing GRN is not an invoice.');
        $this->assertSame(60_000, $this->creditOf($payment)->unallocated());
        $this->assertSame('GRN-000001 reversed by GRN-000002', SupplierAllocation::query()->whereNotNull('reversal_of_id')->sole()->note);
        $this->assertSame(-60_000, $this->suppliers()->balance($hemas)['balance']);
        $this->assertBalanced($hemas);
        $this->refused(fn () => $this->payables()->changeDueDate($this->payableOf($grn), '2026-12-01', 'Agreed', $this->owner), 'Only an unpaid or part-paid');
    }

    public function test_a_supplier_return_reduces_the_grn_it_came_from_or_becomes_credit(): void
    {
        $item = $this->medicine();
        $hemas = $this->supplier();
        $returns = app(SupplierReturnService::class);
        $grn = $this->postReceipt($hemas, [new ReceiptLine($item->getKey(), 10, unitCost: 1_000, batchNo: 'R1', expiry: '12/2027')]);
        $batch = (int) $grn->items->sole()->stock_batch_id;
        $back = fn (int $quantity, ?GoodsReceipt $from = null, ?int $batchId = null): SupplierReturn => $returns->create($hemas, $this->general, SupplierReturnReason::Damaged, [new SupplierReturnLine($item->getKey(), $quantity, batchId: $batchId ?? $batch)], $this->owner, from: $from);

        // The batch came in on one GRN: the return's credit goes against it.
        $first = $back(2);
        $this->assertSame($grn->getKey(), $first->goods_receipt_id);
        $this->assertSame([PayableStatus::PartPaid, 8_000], $this->state($grn));
        $this->assertSame(0, SupplierCredit::query()->where('supplier_return_id', $first->getKey())->sole()->unallocated());

        // Once the GRN is paid, a return is credit for later.
        $this->pay($hemas, 8_000);
        $second = $back(3);
        $this->assertSame([PayableStatus::Paid, 0], $this->state($grn));
        $this->assertSame(3_000, SupplierCredit::query()->where('supplier_return_id', $second->getKey())->sole()->unallocated());

        // Named by hand: against another of the supplier's GRNs.
        $other = $this->bill($hemas, $item, 50_000, 'B');
        $third = $back(1, $other);
        $this->assertSame([$other->getKey(), PayableStatus::PartPaid, 49_000], [$third->goods_receipt_id, ...$this->state($other)]);

        // A batch topped up by two GRNs: which one is not known, so it is credit.
        $twice = $this->postReceipt($hemas, [new ReceiptLine($item->getKey(), 5, unitCost: 1_000, batchNo: 'R2', expiry: '12/2027')]);
        $this->postReceipt($hemas, [new ReceiptLine($item->getKey(), 5, unitCost: 1_000, batchNo: 'R2', expiry: '12/2027')]);
        $fourth = $back(1, batchId: (int) $twice->items->sole()->stock_batch_id);
        $this->assertNull($fourth->goods_receipt_id);
        $this->assertSame(1_000, SupplierCredit::query()->where('supplier_return_id', $fourth->getKey())->sole()->unallocated());

        $spc = $this->supplier('State Pharmaceuticals');
        $this->refused(fn () => $back(1, $this->bill($spc, $item, 1_000)), 'Pick a posted goods received note from Hemas Pharmaceuticals');
        $this->assertBalanced($hemas, $spc);
    }

    public function test_the_balance_always_equals_open_invoices_less_credit(): void
    {
        $item = $this->medicine();
        $hemas = $this->supplier();
        $spc = $this->supplier('State Pharmaceuticals', ['payment_terms_days' => 45]);
        $steps = [
            fn () => $this->suppliers()->recordOpeningBalance($hemas, 75_000, $this->owner, '2026-09-30'),
            fn () => $this->suppliers()->recordOpeningBalance($hemas, 12_500, $this->owner, '2026-09-30', invoiceNo: 'OLD-1', invoiceDate: '2026-09-02'),
            fn () => $this->suppliers()->recordOpeningBalance($spc, -9_999, $this->owner, '2026-09-30'),
            fn () => $this->bill($hemas, $item, 123_456, 'H-1', '2026-10-01'),
            fn () => $this->bill($spc, $item, 33_333, 'S-1'),
            fn () => $this->pay($hemas, 100_001),
            fn () => $this->pay($spc, 50_000, []),
            fn () => $this->payables()->allocate(SupplierCredit::query()->where('supplier_id', $spc->getKey())->whereNotNull('supplier_payment_id')->sole(), null, $this->owner),
            fn () => $this->bill($hemas, $item, 7_777, 'H-2'),
            fn () => app(SupplierReturnService::class)->create($hemas, $this->general, SupplierReturnReason::Expired, [new SupplierReturnLine($item->getKey(), 1, batchId: (int) GoodsReceipt::query()->where('supplier_invoice_no', 'H-2')->sole()->items->sole()->stock_batch_id)], $this->owner),
            fn () => $this->suppliers()->reversePayment(SupplierPayment::query()->where('supplier_id', $hemas->getKey())->firstOrFail(), 'Wrong amount', $this->owner),
            fn () => $this->pay($hemas, 250_000),
            fn () => app(GoodsReceiptService::class)->reverse(GoodsReceipt::query()->where('supplier_invoice_no', 'S-1')->sole(), 'Wrong supplier', $this->owner),
            fn () => $this->payables()->reverseAllocation(SupplierAllocation::query()->whereNull('reversal_of_id')->whereDoesntHave('reversal')->where('supplier_id', $hemas->getKey())->latest('id')->firstOrFail(), 'Check', $this->owner),
            fn () => $this->suppliers()->reverseOpeningBalance($this->suppliers()->openingBalance($hemas) ?? throw new \LogicException('No opening balance'), 'Typed wrong', $this->owner),
        ];

        foreach ($steps as $step) {
            $step();
            $this->assertBalanced($hemas, $spc);
        }

        // 12,500 + 123,456 + 7,777 owed, the 7,777 returned, 250,000 paid; the rest reversed.
        $this->assertSame(-114_044, $this->suppliers()->reconcile($hemas)['balance']);
        // 9,999 credit at go-live and 50,000 paid; the only GRN reversed.
        $this->assertSame(-59_999, $this->suppliers()->reconcile($spc)['balance']);
    }

    public function test_what_is_owed_is_aged_by_days_past_due(): void
    {
        $item = $this->medicine();
        $hemas = $this->supplier();  // 30 days' terms
        $spc = $this->supplier('State Pharmaceuticals');
        $due = fn (string $invoiceDate, int $amount, ?Supplier $supplier = null): GoodsReceipt => $this->bill($supplier ?? $hemas, $item, $amount, 'INV-'.$amount, $invoiceDate);

        $due('2026-09-20', 1_000);      // due 20/10: not yet due, this week
        $due('2026-09-24', 2_000);      // due 24/10: the last day of this week
        $due('2026-09-25', 4_000);      // due 25/10: not yet due, next week
        $due('2026-09-18', 8_000);      // due 18/10: due today, this week
        $late = $due('2026-09-10', 16_000); // due 10/10: 8 days late
        $due('2026-08-09', 32_000);     // due 08/09: 40 days late
        $due('2026-07-10', 64_000);     // due 09/08: 70 days late
        $due('2026-05-31', 128_000);    // due 30/06: 110 days late
        $due('2026-09-10', 256_000, $spc);

        $this->pay($hemas, 6_000, [$this->payableOf($late)->getKey() => 6_000]);

        $this->assertSame(['current' => 15_000, '1_30' => 10_000, '31_60' => 32_000, '61_90' => 64_000, 'over_90' => 128_000, 'total' => 249_000], $this->payables()->aging((int) $hemas->getKey()));
        $this->assertSame(505_000, $this->payables()->aging()['total']);
        $soon = $this->payables()->dueSoon();
        $this->assertSame(['count' => 3, 'amount' => 11_000], $soon['due_this_week']);
        $this->assertSame(['count' => 5, 'amount' => 490_000], $soon['overdue']);
        $this->assertSame('2026-10-24', $soon['week_ends']->toDateString());
        $this->assertSame(8, $this->payableOf($late)->daysOverdue($this->payables()->today()));

        foreach (['current' => 4, '1_30' => 2, '31_60' => 1, '61_90' => 1, 'over_90' => 1] as $bucket => $count) {
            $this->assertSame($count, $this->payables()->whereBucket(SupplierPayable::query()->whereIn('status', PayableStatus::openValues()), $bucket)->count(), $bucket);
        }
    }

    public function test_the_statement_runs_the_balance_from_the_balance_brought_forward(): void
    {
        $item = $this->medicine();
        $hemas = $this->supplier();
        $this->suppliers()->recordOpeningBalance($hemas, 50_000, $this->owner, '2026-09-30', 'Old books');
        $grn = $this->postReceipt($hemas, [new ReceiptLine($item->getKey(), 10, unitCost: 10_000, batchNo: 'S1', expiry: '12/2027')], invoiceNo: 'A-1', invoiceDate: '2026-10-04', receivedOn: '2026-10-05');
        $payment = $this->pay($hemas, 70_000, paidOn: '2026-10-10');
        app(SupplierReturnService::class)->create($hemas, $this->general, SupplierReturnReason::Damaged, [new SupplierReturnLine($item->getKey(), 1, batchId: (int) $grn->items->sole()->stock_batch_id)], $this->owner, creditNoteNo: 'CN-4', returnedOn: '2026-10-12');
        $this->bill($hemas, $item, 40_000, 'A-2', receivedOn: '2026-10-15');
        $this->suppliers()->reversePayment($payment, 'Bounced', $this->owner);

        $statement = $this->payables()->statement($hemas, '2026-10-06', '2026-10-18');

        // Brought forward: the opening balance and GRN-000001.
        $this->assertSame(150_000, $statement['opening']);
        $this->assertSame(
            [
                ['10/10/2026', null, 'Payment', 'Bank transfer TRF', 0, 70_000, 80_000],
                ['12/10/2026', null, 'Return SR-000001', 'Damaged, credit note CN-4', 0, 10_000, 70_000],
                ['15/10/2026', null, 'GRN-000002', 'Invoice A-2', 40_000, 0, 110_000],
                ['18/10/2026', null, 'Payment reversed', 'Bank transfer TRF', 70_000, 0, 180_000],
            ],
            array_map(fn (array $line): array => [$line['date']->format('d/m/Y'), $line['invoice_date']?->format('d/m/Y'), $line['document'], $line['detail'], $line['debit'], $line['credit'], $line['balance']], $statement['lines']),
        );
        $this->assertSame([180_000, 180_000], [$statement['closing'], $this->suppliers()->balance($hemas)['balance']]);

        $september = $this->payables()->statement($hemas, '2026-09-01', '2026-10-05');
        $this->assertSame(0, $september['opening']);
        // The date received and the supplier's invoice date side by side; an opening invoice keeps its invoice date too.
        $this->assertSame([['30/09/2026', null, 'Opening balance', 'Old books', 50_000], ['05/10/2026', '04/10/2026', 'GRN-000001', 'Invoice A-1', 100_000]], array_map(fn (array $line): array => [$line['date']->format('d/m/Y'), $line['invoice_date']?->format('d/m/Y'), $line['document'], $line['detail'], $line['debit']], $september['lines']));
        $this->assertSame(150_000, $september['closing']);

        // An opening invoice is listed at its own invoice date, not the day it was entered (shown in the details),
        // so the balance brought forward is the supplier's: from 13/09 it is already owed.
        $this->suppliers()->recordOpeningBalance($hemas, 5_000, $this->owner, '2026-09-30', 'Go-live', invoiceNo: 'OLD-3', invoiceDate: '2026-09-12');
        $line = fn (array $line): array => [$line['date']->format('d/m/Y'), $line['invoice_date']?->format('d/m/Y'), $line['document'], $line['detail'], $line['debit']];
        $this->assertSame([['12/09/2026', '12/09/2026', 'Opening invoice OLD-3', 'Go-live · Entered 30/09/2026', 5_000]], array_map($line, $this->payables()->statement($hemas, '2026-09-12', '2026-09-12')['lines']));
        $this->assertSame([['30/09/2026', null, 'Opening balance', 'Old books', 50_000]], array_map($line, $this->payables()->statement($hemas, '2026-09-30', '2026-09-30')['lines']));
        $this->assertSame(5_000, $this->payables()->statement($hemas, '2026-09-13', '2026-09-29')['opening']);
        $this->assertSame([185_000, 185_000], [$this->payables()->statement($hemas, '2026-09-01', '2026-10-18')['closing'], $this->suppliers()->balance($hemas)['balance']]);

        $this->refused(fn () => $this->payables()->statement($hemas, '2026-10-18', '2026-10-01'), 'ends before it starts');
    }

    public function test_documents_from_before_payables_are_brought_in_once(): void
    {
        $item = $this->medicine();
        $hemas = $this->supplier();
        $spc = $this->supplier('State Pharmaceuticals');
        $this->suppliers()->recordOpeningBalance($hemas, 50_000, $this->owner, '2026-09-30');
        $kept = $this->bill($hemas, $item, 100_000, 'A', '2026-10-01');
        $reversed = $this->bill($hemas, $item, 30_000, 'B');
        app(GoodsReceiptService::class)->reverse($reversed, 'Twice', $this->owner);
        $this->suppliers()->reversePayment($this->pay($hemas, 5_000), 'Wrong', $this->owner);
        $this->pay($hemas, 80_000);
        $this->suppliers()->recordOpeningBalance($spc, -2_000, $this->owner, '2026-09-30');
        $this->bill($spc, $item, 3_000);

        // As before branch 6b: the documents, but no invoices, credits or allocations.
        DB::table('supplier_allocations')->whereNotNull('reversal_of_id')->delete();
        DB::table('supplier_allocations')->delete();
        DB::table('supplier_credits')->delete();
        DB::table('supplier_payables')->delete();

        $this->payables()->bringIn();
        $this->payables()->bringIn();

        $this->assertSame([4, 3], [SupplierPayable::query()->count(), SupplierCredit::query()->count()]);
        $this->assertSame([PayableStatus::Paid, 0], $this->state(SupplierPayable::query()->whereNotNull('supplier_opening_balance_id')->where('supplier_id', $hemas->getKey())->sole()));
        $this->assertSame([PayableStatus::PartPaid, 70_000], $this->state($kept));
        $this->assertSame([PayableStatus::Reversed, 0], $this->state($reversed));
        $this->assertTrue(SupplierCredit::query()->whereHas('payment', fn ($query) => $query->where('amount', 5_000))->sole()->is_reversed);
        $this->assertSame(1_000, $this->suppliers()->reconcile($spc)['outstanding']);
        $this->assertBalanced($hemas, $spc);
    }

    public function test_only_purchasing_managers_pay_and_allocate(): void
    {
        $hemas = $this->supplier();
        $payable = $this->payableOf($this->bill($hemas, $this->medicine(), 10_000));
        $credit = $this->creditOf($this->pay($hemas, 20_000, []));
        $viewer = User::factory()->create(['email' => 'books@clinic.test']);
        $viewer->forceFill(['shop_id' => $this->shop->getKey()])->save();
        $viewer->givePermissionTo(['admin.access', 'payables.view']);

        foreach ([$this->cashier, $viewer] as $user) {
            $this->refused(fn () => $this->pay($hemas, 1_000, user: $user), 'permission');
            $this->refused(fn () => $this->payables()->allocate($credit, null, $user), 'permission to pay suppliers');
            $this->refused(fn () => $this->payables()->changeDueDate($payable, '2026-12-01', 'Agreed', $user), 'permission to pay suppliers');
        }

        $allocation = $this->payables()->allocate($credit, null, $this->owner)->sole();
        $this->refused(fn () => $this->payables()->reverseAllocation($allocation, 'No', $viewer), 'permission to pay suppliers');
        $this->assertTrue($this->owner->can('payables.view'));
        $this->assertFalse($this->cashier->can('payables.view'));
    }
}
