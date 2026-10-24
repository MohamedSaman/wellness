<?php

namespace App\Services;

use App\Enums\GoodsReceiptStatus;
use App\Enums\SupplierPaymentMethod;
use App\Exceptions\PosException;
use App\Models\GoodsReceipt;
use App\Models\Supplier;
use App\Models\SupplierOpeningBalance;
use App\Models\SupplierPayment;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Support\CalendarDay;
use App\Support\CurrentShop;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Suppliers (suppliers module) and what the shop owes them (purchasing
 * module). Every change needs purchasing.manage and is audited. A supplier
 * is deactivated, never deleted.
 *
 * The balance is deliberately simple, with no accounting behind it:
 *   opening balance (owed at go-live)
 *   + posted goods received totals (a reversing GRN counts negative)
 *   - supplier return credits - payments recorded.
 * Positive means the shop owes the supplier. Opening balances and payments
 * are never edited or deleted: a mistake is put right by a reversing entry
 * (the amount negated), so the history stays whole and audited.
 *
 * Invoice by invoice (SupplierPayablesService): each GRN and opening entry
 * is an open item with a due date, each payment is allocated to them
 * (oldest due first unless picked), and what is not allocated is the
 * supplier's credit. The balance always equals the open items' outstanding
 * less that credit (reconcile()).
 */
class SupplierService
{
    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly BatchService $batches,
        private readonly SupplierPayablesService $payables,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data, User $user): Supplier
    {
        $this->authorize($user, 'suppliers');

        return Supplier::query()->create($this->attributes($data));
    }

    /** @param  array<string, mixed>  $data */
    public function update(Supplier $supplier, array $data, User $user): Supplier
    {
        $this->authorize($user, 'suppliers');

        $supplier->update($this->attributes($data, $supplier));

        return $supplier;
    }

    /**
     * @return array{opening: int, purchases: int, returns: int, payments: int, balance: int}
     */
    public function balance(Supplier $supplier): array
    {
        return $this->balances([(int) $supplier->getKey()])[(int) $supplier->getKey()];
    }

    /**
     * Balances of several suppliers in four queries (the supplier list).
     *
     * @param  list<int>  $supplierIds
     * @return array<int, array{opening: int, purchases: int, returns: int, payments: int, balance: int}>
     */
    public function balances(array $supplierIds): array
    {
        $opening = SupplierOpeningBalance::query()->whereIn('supplier_id', $supplierIds)
            ->groupBy('supplier_id')->selectRaw('supplier_id, SUM(amount) as amount')->pluck('amount', 'supplier_id');
        $purchases = GoodsReceipt::query()->whereIn('supplier_id', $supplierIds)->where('status', GoodsReceiptStatus::Posted)
            ->groupBy('supplier_id')->selectRaw('supplier_id, SUM(total) as amount')->pluck('amount', 'supplier_id');
        $returns = SupplierReturn::query()->whereIn('supplier_id', $supplierIds)
            ->groupBy('supplier_id')->selectRaw('supplier_id, SUM(total) as amount')->pluck('amount', 'supplier_id');
        $payments = SupplierPayment::query()->whereIn('supplier_id', $supplierIds)
            ->groupBy('supplier_id')->selectRaw('supplier_id, SUM(amount) as amount')->pluck('amount', 'supplier_id');

        $balances = [];

        foreach ($supplierIds as $id) {
            $row = [
                'opening' => (int) ($opening[$id] ?? 0),
                'purchases' => (int) ($purchases[$id] ?? 0),
                'returns' => (int) ($returns[$id] ?? 0),
                'payments' => (int) ($payments[$id] ?? 0),
            ];

            $balances[$id] = [...$row, 'balance' => $row['opening'] + $row['purchases'] - $row['returns'] - $row['payments']];
        }

        return $balances;
    }

    /**
     * Whether the supplier's balance tallies with its invoices: the balance,
     * the open items' outstanding, the credit left, and the difference
     * (always 0).
     *
     * @return array{balance: int, outstanding: int, credit: int, difference: int}
     */
    public function reconcile(Supplier $supplier): array
    {
        $balance = $this->balance($supplier)['balance'];
        $open = $this->payables->summary((int) $supplier->getKey());

        return [
            'balance' => $balance,
            'outstanding' => $open['outstanding'],
            'credit' => $open['credit'],
            'difference' => $balance - ($open['outstanding'] - $open['credit']),
        ];
    }

    /** The supplier's standing lump-sum opening balance: entered and not reversed. */
    public function openingBalance(Supplier $supplier): ?SupplierOpeningBalance
    {
        return $this->standingOpening($supplier)->whereNull('invoice_no')->first();
    }

    /**
     * Every standing opening entry (the lump sum and opening invoices).
     *
     * @return Builder<SupplierOpeningBalance>
     */
    public function standingOpening(Supplier $supplier): Builder
    {
        return SupplierOpeningBalance::query()
            ->where('supplier_id', $supplier->getKey())
            ->whereNull('reversal_of_id')
            ->whereDoesntHave('reversal');
    }

    /**
     * What the supplier was owed at go-live, as a lump sum entered once (a
     * negative amount is a credit the supplier owed the shop; a second one
     * is refused while the first stands; reverse it first), or as unpaid
     * invoices one by one ($invoiceNo, with its date and due date; due
     * by default on the invoice date + the supplier's terms). Each is paid
     * like a GRN; a lump sum is due on its as-of date + the supplier's
     * terms unless given (a credit is not due).
     *
     * @param  int  $amount  minor units
     */
    public function recordOpeningBalance(Supplier $supplier, int $amount, User $user, ?string $asOf = null, ?string $note = null, ?string $invoiceNo = null, ?string $invoiceDate = null, ?string $dueOn = null): SupplierOpeningBalance
    {
        $this->authorize($user, 'purchasing');

        if ($amount === 0) {
            throw new PosException('An opening balance can\'t be zero: leave it out instead.');
        }

        $day = $this->notInFuture($asOf, 'An opening balance');
        $invoiceNo = $this->text($invoiceNo, 50);
        $invoiceDay = blank($invoiceDate) ? null : $this->notInFuture($invoiceDate, 'An invoice');

        if ($invoiceNo === null && $invoiceDay !== null) {
            throw new PosException('Enter the invoice number with its date.');
        }

        if ($invoiceNo !== null && $amount < 0) {
            throw new PosException('An opening invoice is an amount owed: enter a credit as a negative opening balance.');
        }

        // A credit is not due; a lump sum may already have been due before go-live.
        $due = $amount < 0 || blank($dueOn) ? null : CalendarDay::parse((string) $dueOn);

        if ($due !== null && $invoiceDay !== null && $due->lessThan($invoiceDay)) {
            throw new PosException('The due date can\'t be before the invoice date.');
        }

        return DB::transaction(function () use ($supplier, $amount, $user, $day, $note, $invoiceNo, $invoiceDay, $due): SupplierOpeningBalance {
            // One entry at a time per supplier.
            $locked = $this->lockSupplier((int) $supplier->getKey());

            if ($invoiceNo === null && ($standing = $this->openingBalance($locked)) !== null) {
                throw new PosException("{$locked->name} already has an opening balance of ".$this->money($standing->amount).' as of '.$standing->as_of->format('d/m/Y').'. Reverse it first to enter another.');
            }

            if ($invoiceNo !== null && $this->standingOpening($locked)->whereRaw('LOWER(invoice_no) = ?', [mb_strtolower($invoiceNo)])->exists()) {
                throw new PosException("{$locked->name} already has opening invoice {$invoiceNo}.");
            }

            $entry = $locked->openingBalances()->create([
                'as_of' => $day->toDateString(),
                'amount' => $amount,
                'invoice_no' => $invoiceNo,
                'invoice_date' => $invoiceDay?->toDateString(),
                'due_on' => $amount < 0 ? null : ($due ?? $this->payables->dueOn($locked, $invoiceDay ?? $day))->toDateString(),
                'note' => $this->text($note),
                'user_id' => $user->getKey(),
            ]);

            $this->payables->openingEntered($entry);

            return $entry;
        });
    }

    /** Put a wrong opening balance right: an entry with the amount negated, dated today. */
    public function reverseOpeningBalance(SupplierOpeningBalance $entry, string $reason, User $user): SupplierOpeningBalance
    {
        $this->authorize($user, 'purchasing');
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($entry, $reason, $user): SupplierOpeningBalance {
            // Locked before anything is read, so on MySQL the check below sees a reversal just committed.
            $locked = $this->lockSupplier((int) $entry->supplier_id);
            // As stored, whatever the caller's instance holds.
            $entry = SupplierOpeningBalance::query()->whereKey($entry->getKey())->firstOrFail();

            if ($entry->isReversal() || SupplierOpeningBalance::query()->where('reversal_of_id', $entry->getKey())->exists()) {
                throw new PosException('That opening balance has already been reversed, or is itself a reversal.');
            }

            $reversal = $locked->openingBalances()->create([
                'as_of' => $this->batches->today()->toDateString(),
                'amount' => -$entry->amount,
                'invoice_no' => $entry->invoice_no,
                'invoice_date' => $entry->invoice_date?->toDateString(),
                'note' => ($entry->invoice_no === null ? 'Reverses the opening balance of ' : "Reverses opening invoice {$entry->invoice_no} of ")."{$entry->as_of->format('d/m/Y')}: {$reason}",
                'reversal_of_id' => $entry->getKey(),
                'user_id' => $user->getKey(),
            ]);

            // What was paid against it goes back to the supplier's credit.
            $this->payables->openingReversed($entry, $user);

            return $reversal;
        });
    }

    /**
     * Record a payment to a supplier: it lowers the balance and is allocated
     * to the supplier's open invoices by $allocations (payable id =>
     * amount, part payments allowed), or oldest due first when null. What
     * is not allocated stays as the supplier's credit, to allocate later.
     * An allocation above what is outstanding on an invoice is refused, so
     * two payments at once can't over-pay one (the supplier row is locked).
     *
     * @param  int  $amount  minor units
     * @param  array<int, int>|null  $allocations  payable id => minor units
     */
    public function recordPayment(Supplier $supplier, int $amount, SupplierPaymentMethod $method, User $user, ?string $paidOn = null, ?string $reference = null, ?string $notes = null, ?array $allocations = null): SupplierPayment
    {
        $this->authorize($user, 'purchasing');

        if ($amount <= 0) {
            throw new PosException('A payment must be more than zero.');
        }

        $day = $this->notInFuture($paidOn, 'A payment');

        return DB::transaction(function () use ($supplier, $amount, $method, $user, $day, $reference, $notes, $allocations): SupplierPayment {
            // Locked before anything is read: the invoices' outstanding is then the latest.
            $locked = $this->lockSupplier((int) $supplier->getKey());

            $payment = $locked->payments()->create([
                'paid_on' => $day->toDateString(),
                'amount' => $amount,
                'method' => $method,
                'reference' => $this->text($reference, 100),
                'notes' => $this->text($notes),
                'user_id' => $user->getKey(),
            ]);

            $this->payables->paymentRecorded($payment, $allocations, $user);

            return $payment;
        });
    }

    /**
     * A payment recorded by mistake: a payment with the amount negated, dated
     * today, takes it back out of the balance, and its allocations are
     * undone. Then the right one is recorded.
     */
    public function reversePayment(SupplierPayment $payment, string $reason, User $user): SupplierPayment
    {
        $this->authorize($user, 'purchasing');
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($payment, $reason, $user): SupplierPayment {
            // Locked before anything is read, so on MySQL the check below sees a reversal just committed.
            $locked = $this->lockSupplier((int) $payment->supplier_id);
            // As stored, whatever the caller's instance holds.
            $payment = SupplierPayment::query()->whereKey($payment->getKey())->firstOrFail();

            if ($payment->isReversal() || SupplierPayment::query()->where('reversal_of_id', $payment->getKey())->exists()) {
                throw new PosException('That payment has already been reversed, or is itself a reversal.');
            }

            $reversal = $locked->payments()->create([
                'paid_on' => $this->batches->today()->toDateString(),
                'amount' => -$payment->amount,
                'method' => $payment->method,
                'reference' => $payment->reference,
                'notes' => "Reverses the payment of {$payment->paid_on->format('d/m/Y')}: {$reason}",
                'reversal_of_id' => $payment->getKey(),
                'user_id' => $user->getKey(),
            ]);

            // The invoices it paid are owed again.
            $this->payables->paymentReversed($payment, $user);

            return $reversal;
        });
    }

    /** The supplier row, locked: opening balances, payments and allocations of one supplier one at a time. */
    private function lockSupplier(int $supplierId): Supplier
    {
        return $this->payables->lockSupplier($supplierId);
    }

    private function notInFuture(?string $value, string $what): CarbonImmutable
    {
        $day = blank($value) ? $this->batches->today() : CalendarDay::parse((string) $value);

        if ($day->greaterThan($this->batches->today())) {
            throw new PosException("{$what} can't be dated in the future.");
        }

        return $day;
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new PosException('Say why it is being reversed.');
        }

        return $reason;
    }

    private function money(int $minor): string
    {
        return $this->currentShop->getOrFail()->formatMoney($minor);
    }

    private function authorize(User $user, string $module): void
    {
        if (! $user->can('purchasing.manage')) {
            throw new PosException('You do not have permission to manage suppliers.');
        }

        if (! $this->currentShop->getOrFail()->hasModule($module)) {
            throw new PosException("This needs the {$module} module.");
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?Supplier $supplier = null): array
    {
        $name = Str::squish((string) ($data['name'] ?? $supplier->name ?? ''));

        if ($name === '' || Str::length($name) > 150) {
            throw new PosException('Enter the supplier\'s name (at most 150 characters).');
        }

        $taken = Supplier::query()
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
            ->when($supplier !== null, fn ($query) => $query->whereKeyNot($supplier?->getKey()))
            ->exists();

        if ($taken) {
            throw new PosException("There is already a supplier called {$name}.");
        }

        $email = $this->text($data['email'] ?? null, 150);

        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new PosException("\"{$email}\" is not an email address.");
        }

        $terms = $data['payment_terms_days'] ?? null;

        if (filled($terms) && (! is_numeric($terms) || (int) $terms != $terms || (int) $terms < 0 || (int) $terms > 365)) {
            throw new PosException('Payment terms are a number of days from 0 to 365.');
        }

        return [
            'name' => $name,
            'contact_name' => $this->text($data['contact_name'] ?? null, 150),
            'phone' => $this->text($data['phone'] ?? null, 50),
            'email' => $email,
            'address' => $this->text($data['address'] ?? null),
            'tax_number' => $this->text($data['tax_number'] ?? null, 50),
            'payment_terms_days' => filled($terms) ? (int) $terms : null,
            'notes' => $this->text($data['notes'] ?? null),
            'is_active' => (bool) ($data['is_active'] ?? $supplier->is_active ?? true),
        ];
    }

    private function text(mixed $value, ?int $max = null): ?string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        if ($max !== null && Str::length($text) > $max) {
            throw new PosException("\"{$text}\" is too long (at most {$max} characters).");
        }

        return $text;
    }
}
