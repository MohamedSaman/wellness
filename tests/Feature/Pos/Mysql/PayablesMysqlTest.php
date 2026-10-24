<?php

namespace Tests\Feature\Pos\Mysql;

use App\Enums\PayableStatus;
use App\Enums\SupplierPaymentMethod;
use App\Models\SupplierAllocation;
use App\Models\SupplierCredit;
use App\Models\SupplierPayable;
use App\Models\SupplierPayment;
use App\Services\GoodsReceiptService;
use App\Services\SupplierPayablesService;
use App\Services\SupplierService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Pos\Concerns\WorksWithPurchasing;

/**
 * Supplier payables on MySQL (branch 6b): every change to allocations
 * locks the supplier row first, so payments made at the same moment can't
 * both take what is outstanding on one invoice: it is never over-paid,
 * and a GRN reversed while it is being paid owes nothing afterwards.
 */
#[Group('mysql')]
class PayablesMysqlTest extends MysqlTestCase
{
    use WorksWithPurchasing;

    private function payable(): SupplierPayable
    {
        return SupplierPayable::query()->sole();
    }

    private function assertNeverOverpaid(): void
    {
        $payable = $this->payable();
        $allocated = (int) SupplierAllocation::query()->where('supplier_payable_id', $payable->getKey())->sum('amount');

        $this->assertSame($allocated, $payable->allocated, 'The counter is the sum of the allocations.');
        $this->assertLessThanOrEqual($payable->amount, $allocated);
        $this->assertSame((int) SupplierAllocation::query()->sum('amount'), (int) SupplierCredit::query()->sum('allocated'));
        $this->assertSame(0, app(SupplierService::class)->reconcile($payable->supplier)['difference']);
    }

    public function test_two_payments_allocated_to_one_invoice_at_once_cannot_over_pay_it(): void
    {
        $supplier = $this->supplier();
        $this->bill($supplier, $this->medicine(), 100_000, 'HPL-1');
        $id = (int) $this->payable()->getKey();

        // Four payments of 40.00 each, all for the same 100.00 invoice: two fit, the third doesn't.
        $outcomes = $this->race(4, fn () => app(SupplierService::class)->recordPayment($supplier, 40_000, SupplierPaymentMethod::BankTransfer, $this->owner, allocations: [$id => 40_000]));

        $this->assertSame(2, count(array_keys($outcomes, 'ok', true)), implode("\n", $outcomes));
        foreach (array_diff($outcomes, ['ok']) as $refused) {
            $this->assertStringContainsString('Only LKR 200.00 is outstanding on GRN-000001', $refused);
        }

        $this->assertSame([80_000, PayableStatus::PartPaid], [$this->payable()->allocated, $this->payable()->status]);
        $this->assertSame(2, SupplierPayment::query()->count(), 'A refused payment is not recorded.');
        $this->assertNeverOverpaid();
    }

    public function test_payments_oldest_due_first_at_once_pay_the_invoice_exactly_and_keep_the_rest_as_credit(): void
    {
        $supplier = $this->supplier();
        $this->bill($supplier, $this->medicine(), 100_000, 'HPL-1');

        $outcomes = $this->race(4, fn () => app(SupplierService::class)->recordPayment($supplier, 30_000, SupplierPaymentMethod::Cash, $this->owner));

        $this->assertSame(array_fill(0, 4, 'ok'), $outcomes);
        $this->assertSame([100_000, PayableStatus::Paid], [$this->payable()->allocated, $this->payable()->status]);
        $this->assertSame(20_000, app(SupplierPayablesService::class)->creditLeft((int) $supplier->getKey()));
        $this->assertNeverOverpaid();
    }

    public function test_a_grn_reversed_while_it_is_being_paid_owes_nothing_after(): void
    {
        $supplier = $this->supplier();
        $grn = $this->bill($supplier, $this->medicine(), 100_000, 'HPL-1');

        $outcomes = $this->race(3, fn (int $worker) => $worker === 0
            ? app(GoodsReceiptService::class)->reverse($grn, 'Entered twice', $this->owner)
            : app(SupplierService::class)->recordPayment($supplier, 50_000, SupplierPaymentMethod::Cash, $this->owner));

        $this->assertSame(array_fill(0, 3, 'ok'), $outcomes);
        $this->assertSame([PayableStatus::Reversed, 0, 0], [$this->payable()->status, $this->payable()->outstanding(), $this->payable()->allocated]);
        $this->assertSame(100_000, app(SupplierPayablesService::class)->creditLeft((int) $supplier->getKey()), 'Both payments are credit.');
        $this->assertNeverOverpaid();
    }
}
