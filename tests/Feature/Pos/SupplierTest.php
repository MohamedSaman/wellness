<?php

namespace Tests\Feature\Pos;

use App\Enums\SupplierPaymentMethod;
use App\Enums\SupplierReturnReason;
use App\Exceptions\PosException;
use App\Models\AuditLog;
use App\Models\Supplier;
use App\Models\SupplierOpeningBalance;
use App\Models\SupplierPayment;
use App\Services\Data\ReceiptLine;
use App\Services\Data\SupplierReturnLine;
use App\Services\SupplierReturnService;
use App\Services\SupplierService;
use Tests\Feature\Pos\Concerns\WorksWithPurchasing;

/**
 * Suppliers (suppliers module) and the simple supplier balance (purchasing):
 * posted goods received minus returns minus payments, no accounting behind it.
 */
class SupplierTest extends BatchTestCase
{
    use WorksWithPurchasing;

    private function suppliers(): SupplierService
    {
        return app(SupplierService::class);
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

    public function test_a_supplier_keeps_its_details_and_changes_are_audited(): void
    {
        $this->actingAs($this->owner);

        $supplier = $this->suppliers()->create([
            'name' => '  Hemas   Pharmaceuticals ',
            'contact_name' => 'Nimal Perera',
            'phone' => '+94 11 000 0000',
            'email' => 'orders@hemas.test',
            'address' => '36 Bristol Street, Colombo 1',
            'tax_number' => 'VAT-114455',
            'payment_terms_days' => '45',
            'notes' => 'Delivers Tuesdays',
        ], $this->owner);

        $this->assertSame(
            ['Hemas Pharmaceuticals', 'Nimal Perera', '+94 11 000 0000', 'orders@hemas.test', '36 Bristol Street, Colombo 1', 'VAT-114455', 45, 'Delivers Tuesdays', true],
            [$supplier->name, $supplier->contact_name, $supplier->phone, $supplier->email, $supplier->address, $supplier->tax_number, $supplier->payment_terms_days, $supplier->notes, $supplier->is_active],
        );

        $this->suppliers()->update($supplier, ['name' => 'Hemas Pharmaceuticals', 'payment_terms_days' => 30, 'is_active' => false], $this->owner);

        $audit = AuditLog::query()->where('auditable_type', $supplier->getMorphClass())->where('event', 'updated')->sole();
        $this->assertSameIgnoringKeyOrder(['payment_terms_days' => 45, 'contact_name' => 'Nimal Perera', 'phone' => '+94 11 000 0000', 'email' => 'orders@hemas.test', 'address' => '36 Bristol Street, Colombo 1', 'tax_number' => 'VAT-114455', 'notes' => 'Delivers Tuesdays', 'is_active' => true], $audit->old_values);
        $this->assertFalse($supplier->fresh()?->is_active);
        $this->assertSame([], Supplier::options(), 'Inactive suppliers are not offered.');
    }

    public function test_supplier_details_are_checked(): void
    {
        $this->supplier('Hemas Pharmaceuticals');

        $this->refused(fn () => $this->supplier('hemas pharmaceuticals'), 'already a supplier called');
        $this->refused(fn () => $this->supplier(' '), 'Enter the supplier\'s name');
        $this->refused(fn () => $this->supplier('A', ['email' => 'not-an-email']), 'is not an email address');
        $this->refused(fn () => $this->supplier('B', ['payment_terms_days' => 400]), 'from 0 to 365');
        $this->refused(fn () => $this->suppliers()->create(['name' => 'C'], $this->cashier), 'permission');

        $this->assertSame(1, Supplier::query()->count());
    }

    public function test_an_opening_balance_is_entered_once_and_corrected_by_reversing_it(): void
    {
        $this->actingAs($this->owner);
        $panadol = $this->medicine();
        $supplier = $this->supplier();
        $this->postReceipt($supplier, [new ReceiptLine($panadol->getKey(), 10, unitCost: 1_000, batchNo: 'A', expiry: '12/2027')]);

        $opening = $this->suppliers()->recordOpeningBalance($supplier, 250_000, $this->owner, '2026-10-01', 'Statement of 30/09/2026');

        $this->assertSame(['2026-10-01', 250_000, 'Statement of 30/09/2026', $this->owner->getKey()], [$opening->as_of->toDateString(), $opening->amount, $opening->note, $opening->user_id]);
        $this->assertTrue($this->suppliers()->openingBalance($supplier)?->is($opening));
        $this->assertSame(['opening' => 250_000, 'purchases' => 10_000, 'returns' => 0, 'payments' => 0, 'balance' => 260_000], $this->suppliers()->balance($supplier));
        $this->assertSame(250_000, AuditLog::query()->where('auditable_type', $opening->getMorphClass())->where('event', 'created')->sole()->new_values['amount'] ?? null);

        // Entered once; never edited or deleted.
        $this->refused(fn () => $this->suppliers()->recordOpeningBalance($supplier, 1_000, $this->owner), 'already has an opening balance of LKR 2,500.00 as of 01/10/2026. Reverse it first');
        foreach ([fn () => $opening->update(['amount' => 1]), fn () => $opening->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('An opening balance was edited or deleted.');
            } catch (\LogicException) {
                // expected
            }
        }

        // A mistake: reversed (the amount negated, dated today), then the right one entered.
        $reversal = $this->suppliers()->reverseOpeningBalance($opening, 'Typed one zero too many', $this->owner);
        $this->assertSame([-250_000, $opening->getKey(), '2026-10-18', 'Reverses the opening balance of 01/10/2026: Typed one zero too many'], [$reversal->amount, $reversal->reversal_of_id, $reversal->as_of->toDateString(), $reversal->note]);
        $this->assertNull($this->suppliers()->openingBalance($supplier));
        $this->assertSame(10_000, $this->suppliers()->balance($supplier)['balance']);
        $this->refused(fn () => $this->suppliers()->reverseOpeningBalance($opening, 'Again', $this->owner), 'already been reversed');
        $this->refused(fn () => $this->suppliers()->reverseOpeningBalance($reversal, 'Undo', $this->owner), 'itself a reversal');

        $right = $this->suppliers()->recordOpeningBalance($supplier, 25_000, $this->owner, '2026-10-01');
        $this->assertTrue($this->suppliers()->openingBalance($supplier)?->is($right));
        $this->assertSame(['opening' => 25_000, 'purchases' => 10_000, 'returns' => 0, 'payments' => 0, 'balance' => 35_000], $this->suppliers()->balance($supplier));
        $this->assertSame(3, SupplierOpeningBalance::query()->count());

        // A credit the supplier owed the shop is negative; zero, the future and other users are refused.
        $other = $this->supplier('State Pharmaceuticals');
        $this->assertSame(-5_000, $this->suppliers()->recordOpeningBalance($other, -5_000, $this->owner)->amount);
        $this->refused(fn () => $this->suppliers()->recordOpeningBalance($this->supplier('C'), 0, $this->owner), 'can\'t be zero');
        $this->refused(fn () => $this->suppliers()->recordOpeningBalance($this->supplier('D'), 100, $this->owner, '2026-10-19'), 'in the future');
        $this->refused(fn () => $this->suppliers()->recordOpeningBalance($this->supplier('E'), 100, $this->cashier), 'permission');
        $this->refused(fn () => $this->suppliers()->reverseOpeningBalance($right, 'No', $this->cashier), 'permission');
    }

    public function test_the_balance_is_goods_received_less_returns_and_payments(): void
    {
        $this->actingAs($this->owner);
        $panadol = $this->medicine();
        $supplier = $this->supplier();
        $other = $this->supplier('State Pharmaceuticals');

        $grn = $this->postReceipt($supplier, [new ReceiptLine($panadol->getKey(), 100, unitCost: 1_000, batchNo: 'A', expiry: '12/2027')]);
        $this->postReceipt($other, [new ReceiptLine($panadol->getKey(), 10, unitCost: 1_000, batchNo: 'B', expiry: '12/2027')]);
        $this->draftReceipt($supplier, [new ReceiptLine($panadol->getKey(), 10, unitCost: 5_000)]);
        app(SupplierReturnService::class)->create($supplier, $this->general, SupplierReturnReason::Damaged, [new SupplierReturnLine($panadol->getKey(), 10, batchId: $grn->items->sole()->stock_batch_id)], $this->owner);
        $payment = $this->suppliers()->recordPayment($supplier, 30_000, SupplierPaymentMethod::Cheque, $this->owner, '2026-10-17', 'CHQ 004512');

        $this->assertSame(['opening' => 0, 'purchases' => 100_000, 'returns' => 10_000, 'payments' => 30_000, 'balance' => 60_000], $this->suppliers()->balance($supplier));
        $this->assertSame(10_000, $this->suppliers()->balances([(int) $other->getKey()])[(int) $other->getKey()]['balance']);
        $this->assertSame(['2026-10-17', 'CHQ 004512', SupplierPaymentMethod::Cheque], [$payment->paid_on->toDateString(), $payment->reference, $payment->method]);

        // A payment is never edited or deleted; a wrong one is reversed by a negative payment.
        foreach ([fn () => $payment->update(['amount' => 1]), fn () => $payment->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('A payment was edited or deleted.');
            } catch (\LogicException) {
                // expected
            }
        }

        $this->refused(fn () => $this->suppliers()->reversePayment($payment, ' ', $this->owner), 'Say why');
        $reversal = $this->suppliers()->reversePayment($payment, 'Cheque bounced', $this->owner);

        $this->assertSame([-30_000, $payment->getKey(), '2026-10-18', 'CHQ 004512', 'Reverses the payment of 17/10/2026: Cheque bounced'], [$reversal->amount, $reversal->reversal_of_id, $reversal->paid_on->toDateString(), $reversal->reference, $reversal->notes]);
        $this->assertSame(2, SupplierPayment::query()->count());
        $this->assertSame(90_000, $this->suppliers()->balance($supplier)['balance']);
        $this->assertSame(-30_000, AuditLog::query()->where('auditable_type', $payment->getMorphClass())->where('auditable_id', $reversal->getKey())->where('event', 'created')->sole()->new_values['amount'] ?? null);
        $this->refused(fn () => $this->suppliers()->reversePayment($payment, 'Again', $this->owner), 'already been reversed');
        $this->refused(fn () => $this->suppliers()->reversePayment($reversal, 'Undo', $this->owner), 'itself a reversal');

        $this->refused(fn () => $this->suppliers()->recordPayment($supplier, 0, SupplierPaymentMethod::Cash, $this->owner), 'more than zero');
        $this->refused(fn () => $this->suppliers()->recordPayment($supplier, 100, SupplierPaymentMethod::Cash, $this->owner, '2026-10-19'), 'in the future');
        $this->refused(fn () => $this->suppliers()->recordPayment($supplier, 100, SupplierPaymentMethod::Cash, $this->cashier), 'permission');
    }
}
