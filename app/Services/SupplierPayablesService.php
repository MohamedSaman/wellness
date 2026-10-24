<?php

namespace App\Services;

use App\Enums\GoodsReceiptStatus;
use App\Enums\PayableStatus;
use App\Exceptions\PosException;
use App\Models\GoodsReceipt;
use App\Models\Supplier;
use App\Models\SupplierAllocation;
use App\Models\SupplierCredit;
use App\Models\SupplierOpeningBalance;
use App\Models\SupplierPayable;
use App\Models\SupplierPayment;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Support\CalendarDay;
use App\Support\CurrentShop;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Supplier payables (purchasing module, docs/PHARMACY.md branch 6b): what
 * is owed invoice by invoice, when it is due, and what settled it.
 *
 *   - Every posted GRN (not a reversal) and every opening balance entry
 *     owed at go-live is an open item, a SupplierPayable, due on its
 *     invoice date (else the date received) + the supplier's payment terms.
 *   - Every payment, supplier return and opening credit is a
 *     SupplierCredit. Credits are allocated to open items
 *     (SupplierAllocation): a payment oldest due first unless the user
 *     picks, a return to the GRN its goods came from. What is not
 *     allocated stays as the supplier's credit, to allocate later.
 *   - Allocations are insert-only. Undoing one, reversing a payment, a GRN
 *     or an opening entry writes reversing allocations, so the payment's
 *     money goes back to credit or the invoice is owed again.
 *
 * So a supplier's balance (SupplierService::balance) always equals its open
 * items' outstanding less its credit left (SupplierService::reconcile).
 *
 * Locking: every change to allocations takes the supplier row first
 * (lockSupplier), before anything else is read, then the credit and the
 * payables it touches in id order. Callers that already hold other locks
 * (GRN reversal, supplier returns) take the shop row, then the supplier.
 * On MySQL the supplier lock also makes the transaction's snapshot see the
 * previous allocation, so two payments to one invoice can't both take what
 * is outstanding (MySQL group).
 */
class SupplierPayablesService
{
    public const AGING_BUCKETS = [
        'current' => 'Not yet due',
        '1_30' => '1–30 days',
        '31_60' => '31–60 days',
        '61_90' => '61–90 days',
        'over_90' => 'Over 90 days',
    ];

    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly BatchService $batches,
        private readonly AuditObserver $audit,
    ) {}

    public function today(): CarbonImmutable
    {
        return $this->batches->today();
    }

    /** $from + the supplier's payment terms (none: due that day). */
    public function dueOn(Supplier $supplier, CarbonInterface|string $from): CarbonImmutable
    {
        $day = $from instanceof CarbonInterface ? CarbonImmutable::parse($from->toDateString()) : CalendarDay::parse($from);

        return $day->addDays((int) ($supplier->payment_terms_days ?? 0));
    }

    /** A GRN's due date: its invoice date, else the day received, + the supplier's terms. */
    public function receiptDueOn(GoodsReceipt $receipt): CarbonImmutable
    {
        return $this->dueOn($receipt->supplier, $receipt->supplier_invoice_date ?? $receipt->received_on);
    }

    // ------------------------------------------------------------------
    // Called by the services that post the documents, in their transaction.
    // ------------------------------------------------------------------

    /** A GRN was posted: it is owed from now on. A GRN worth nothing owes nothing. */
    public function receiptPosted(GoodsReceipt $receipt): ?SupplierPayable
    {
        if ($receipt->isReversal() || $receipt->total <= 0) {
            return null;
        }

        return SupplierPayable::query()->create([
            'supplier_id' => $receipt->supplier_id,
            'goods_receipt_id' => $receipt->getKey(),
            'invoice_no' => $receipt->supplier_invoice_no,
            'invoice_date' => $receipt->supplier_invoice_date?->toDateString(),
            'document_date' => $receipt->received_on->toDateString(),
            'due_on' => $this->receiptDueOn($receipt)->toDateString(),
            'amount' => $receipt->total,
            'status' => PayableStatus::Unpaid,
        ]);
    }

    /**
     * A GRN was reversed: nothing is owed on it any more, and what was paid
     * or credited against it goes back to the supplier's credit. The caller
     * holds the supplier's lock.
     */
    public function receiptReversed(GoodsReceipt $original, GoodsReceipt $reversal, ?User $user): void
    {
        $payable = SupplierPayable::query()->where('goods_receipt_id', $original->getKey())->lockForUpdate()->first();

        if ($payable !== null) {
            $this->closePayable($payable, "{$original->grn_number} reversed by {$reversal->grn_number}", $user);
        }
    }

    /** An opening entry: an amount owed is an open item, a credit owed to the shop is a credit. */
    public function openingEntered(SupplierOpeningBalance $entry): void
    {
        if ($entry->isReversal()) {
            return;
        }

        if ($entry->amount > 0) {
            SupplierPayable::query()->create([
                'supplier_id' => $entry->supplier_id,
                'supplier_opening_balance_id' => $entry->getKey(),
                'invoice_no' => $entry->invoice_no,
                'invoice_date' => $entry->invoice_date?->toDateString(),
                'document_date' => $entry->as_of->toDateString(),
                'due_on' => ($entry->due_on ?? $entry->as_of)->toDateString(),
                'amount' => $entry->amount,
                'status' => PayableStatus::Unpaid,
            ]);

            return;
        }

        SupplierCredit::query()->create([
            'supplier_id' => $entry->supplier_id,
            'supplier_opening_balance_id' => $entry->getKey(),
            'credit_on' => $entry->as_of->toDateString(),
            'amount' => -$entry->amount,
        ]);
    }

    /** An opening entry was reversed. The caller holds the supplier's lock. */
    public function openingReversed(SupplierOpeningBalance $entry, ?User $user): void
    {
        $note = 'Opening entry of '.$entry->as_of->format('d/m/Y').' reversed';
        $payable = SupplierPayable::query()->where('supplier_opening_balance_id', $entry->getKey())->lockForUpdate()->first();
        $credit = SupplierCredit::query()->where('supplier_opening_balance_id', $entry->getKey())->lockForUpdate()->first();

        if ($payable !== null) {
            $this->closePayable($payable, $note, $user);
        }

        if ($credit !== null) {
            $this->closeCredit($credit, $note, $user);
        }
    }

    /**
     * A payment was recorded: it becomes a credit, allocated by $plan
     * (payable id => amount; null = oldest due first; [] = none). The rest
     * stays as credit. The caller holds the supplier's lock.
     *
     * @param  array<int, int>|null  $plan
     */
    public function paymentRecorded(SupplierPayment $payment, ?array $plan, ?User $user): SupplierCredit
    {
        $credit = SupplierCredit::query()->create([
            'supplier_id' => $payment->supplier_id,
            'supplier_payment_id' => $payment->getKey(),
            'credit_on' => $payment->paid_on->toDateString(),
            'amount' => $payment->amount,
        ]);

        $this->apply($credit, $plan ?? $this->suggest((int) $payment->supplier_id, $payment->amount), $user, $payment->paid_on->toDateString());

        return $credit->refresh();
    }

    /** A payment was reversed: its allocations are undone, the invoices are owed again. The caller holds the supplier's lock. */
    public function paymentReversed(SupplierPayment $payment, ?User $user): void
    {
        $credit = SupplierCredit::query()->where('supplier_payment_id', $payment->getKey())->lockForUpdate()->first();

        if ($credit !== null) {
            $this->closeCredit($credit, 'Payment of '.$payment->paid_on->format('d/m/Y').' reversed', $user);
        }
    }

    /**
     * A supplier return was posted: its credit goes against the GRN the
     * goods came from, as far as that GRN is still owed; the rest is credit.
     * The caller holds the supplier's lock.
     */
    public function returnPosted(SupplierReturn $return, ?User $user): ?SupplierCredit
    {
        if ($return->total <= 0) {
            return null;
        }

        $credit = SupplierCredit::query()->create([
            'supplier_id' => $return->supplier_id,
            'supplier_return_id' => $return->getKey(),
            'credit_on' => $return->returned_on->toDateString(),
            'amount' => $return->total,
        ]);

        $payable = $return->goods_receipt_id === null ? null : SupplierPayable::query()
            ->where('goods_receipt_id', $return->goods_receipt_id)
            ->whereIn('status', PayableStatus::openValues())
            ->first();

        if ($payable !== null) {
            $this->apply($credit, [(int) $payable->getKey() => min($return->total, $payable->outstanding())], $user, $return->returned_on->toDateString(), "Return {$return->return_number}");
        }

        return $credit->refresh();
    }

    // ------------------------------------------------------------------
    // What the user does (purchasing.manage).
    // ------------------------------------------------------------------

    /**
     * Allocate what is left of a credit (a payment, a return, an opening
     * credit) to open invoices of the same supplier: $plan is payable id =>
     * amount, or null for oldest due first.
     *
     * @param  array<int, int>|null  $plan
     * @return Collection<int, SupplierAllocation>
     */
    public function allocate(SupplierCredit $credit, ?array $plan, User $user): Collection
    {
        $this->authorize($user);

        return DB::transaction(function () use ($credit, $plan, $user): Collection {
            $this->lockSupplier((int) $credit->supplier_id);
            $credit = SupplierCredit::query()->whereKey($credit->getKey())->firstOrFail();
            $left = $credit->unallocated();

            if ($left <= 0) {
                throw new PosException('Nothing is left of that credit to allocate.');
            }

            if ($plan === null && ($plan = $this->suggest((int) $credit->supplier_id, $left)) === []) {
                throw new PosException('The supplier has no invoice to allocate it to.');
            }

            if (array_sum($plan) <= 0) {
                throw new PosException('Say how much goes to which invoice.');
            }

            return $this->apply($credit, $plan, $user, $this->today()->toDateString());
        });
    }

    /** Undo an allocation made by mistake: the invoice is owed again and the money is credit again. */
    public function reverseAllocation(SupplierAllocation $allocation, string $reason, User $user): SupplierAllocation
    {
        $this->authorize($user);
        $reason = trim($reason);

        if ($reason === '') {
            throw new PosException('Say why the allocation is being undone.');
        }

        return DB::transaction(function () use ($allocation, $reason, $user): SupplierAllocation {
            $this->lockSupplier((int) $allocation->supplier_id);
            $allocation = SupplierAllocation::query()->whereKey($allocation->getKey())->firstOrFail();

            if ($allocation->isReversal() || SupplierAllocation::query()->where('reversal_of_id', $allocation->getKey())->exists()) {
                throw new PosException('That allocation has already been undone, or is itself an undo.');
            }

            return $this->undo($allocation, "Undone: {$reason}", $user);
        });
    }

    /** Move an open invoice's due date (agreed with the supplier). A reason is required; both go to the audit log. */
    public function changeDueDate(SupplierPayable $payable, string $dueOn, string $reason, User $user): SupplierPayable
    {
        $this->authorize($user);
        $day = CalendarDay::parse($dueOn);
        $reason = trim($reason);

        if ($reason === '') {
            throw new PosException('Say why the due date is changing.');
        }

        if (mb_strlen($reason) > 200) {
            throw new PosException('The reason can have at most 200 characters.');
        }

        return DB::transaction(function () use ($payable, $day, $user, $reason): SupplierPayable {
            $payable = SupplierPayable::query()->whereKey($payable->getKey())->lockForUpdate()->firstOrFail();

            if (! $payable->status->isOpen()) {
                throw new PosException('Only an unpaid or part-paid invoice has a due date to change.');
            }

            if ($day->lessThan($payable->invoice_date ?? $payable->document_date)) {
                throw new PosException('The due date can\'t be before the invoice date.');
            }

            $old = $payable->due_on->toDateString();

            if ($old === $day->toDateString()) {
                throw new PosException('It is already due on '.$day->format('d/m/Y').'.');
            }

            $payable->update(['due_on' => $day->toDateString()]);
            $this->audit->record('due_date_changed', $payable, ['due_on' => $old], ['due_on' => $day->toDateString(), 'reason' => $reason], (int) $user->getKey());

            return $payable;
        });
    }

    // ------------------------------------------------------------------
    // Reading.
    // ------------------------------------------------------------------

    /**
     * A supplier's open invoices, oldest due first.
     *
     * @return Collection<int, SupplierPayable>
     */
    public function openPayables(int $supplierId): Collection
    {
        return $this->openQuery()->where('supplier_id', $supplierId)->with(['goodsReceipt'])->get();
    }

    /**
     * $amount spread over a supplier's open invoices, oldest due first.
     *
     * @return array<int, int> payable id => amount
     */
    public function suggest(int $supplierId, int $amount): array
    {
        $plan = [];

        foreach ($this->openQuery()->where('supplier_id', $supplierId)->get() as $payable) {
            if ($amount <= 0) {
                break;
            }

            $take = min($amount, $payable->outstanding());
            $plan[(int) $payable->getKey()] = $take;
            $amount -= $take;
        }

        return $plan;
    }

    /**
     * Open invoices, credit left and what is overdue.
     *
     * @return array{outstanding: int, overdue: int, credit: int}
     */
    public function summary(int $supplierId): array
    {
        $today = $this->today()->toDateString();
        $open = SupplierPayable::query()->where('supplier_id', $supplierId)->whereIn('status', PayableStatus::openValues());

        return [
            'outstanding' => (int) (clone $open)->sum(DB::raw('amount - allocated')),
            'overdue' => (int) (clone $open)->where('due_on', '<', $today)->sum(DB::raw('amount - allocated')),
            'credit' => $this->creditLeft($supplierId),
        ];
    }

    public function creditLeft(int $supplierId): int
    {
        return (int) SupplierCredit::query()->where('supplier_id', $supplierId)->where('is_reversed', false)->sum(DB::raw('amount - allocated'));
    }

    /**
     * Credits with something left to allocate.
     *
     * @return Collection<int, SupplierCredit>
     */
    public function openCredits(int $supplierId): Collection
    {
        return SupplierCredit::query()
            ->where('supplier_id', $supplierId)
            ->where('is_reversed', false)
            ->whereColumn('allocated', '<', 'amount')
            ->with(['payment', 'supplierReturn'])
            ->orderBy('credit_on')
            ->orderBy('id')
            ->get();
    }

    /**
     * What is owed by how late it is: not yet due, 1–30, 31–60, 61–90 and
     * over 90 days past the due date; all suppliers or one.
     *
     * @return array{current: int, '1_30': int, '31_60': int, '61_90': int, over_90: int, total: int}
     */
    public function aging(?int $supplierId = null): array
    {
        $today = $this->today();
        $buckets = ['current' => 0, '1_30' => 0, '31_60' => 0, '61_90' => 0, 'over_90' => 0];

        $this->openQuery()
            ->when($supplierId !== null, fn (Builder $query) => $query->where('supplier_id', $supplierId))
            ->get(['id', 'due_on', 'amount', 'allocated', 'status'])
            ->each(function (SupplierPayable $payable) use ($today, &$buckets): void {
                $buckets[self::bucketOf($payable->daysOverdue($today))] += $payable->outstanding();
            });

        return [...$buckets, 'total' => array_sum($buckets)];
    }

    /**
     * aging() for every supplier that is owed something or holds credit,
     * with the credit left and the balance (outstanding - credit, which is
     * SupplierService::balance(), see reconcile()), largest balance first:
     * the supplier aging report (docs/PHARMACY.md §9, branch 11).
     *
     * @return list<array{supplier_id: int, supplier: string, current: int, '1_30': int, '31_60': int, '61_90': int, over_90: int, total: int, credit: int, balance: int}>
     */
    public function agingBySupplier(): array
    {
        $today = $this->today();
        $rows = [];
        $row = fn (int $supplierId): array => ['supplier_id' => $supplierId, 'current' => 0, '1_30' => 0, '31_60' => 0, '61_90' => 0, 'over_90' => 0, 'total' => 0, 'credit' => 0];

        $this->openQuery()->get(['id', 'supplier_id', 'due_on', 'amount', 'allocated', 'status'])
            ->each(function (SupplierPayable $payable) use ($today, &$rows, $row): void {
                $id = (int) $payable->supplier_id;
                $rows[$id] ??= $row($id);
                $rows[$id][self::bucketOf($payable->daysOverdue($today))] += $payable->outstanding();
                $rows[$id]['total'] += $payable->outstanding();
            });

        SupplierCredit::query()->toBase()
            ->where('is_reversed', false)
            ->selectRaw('supplier_id, SUM(amount - allocated) as credit')
            ->groupBy('supplier_id')
            ->get()
            ->each(function ($credit) use (&$rows, $row): void {
                if ((int) $credit->credit !== 0) {
                    $id = (int) $credit->supplier_id;
                    $rows[$id] ??= $row($id);
                    $rows[$id]['credit'] = (int) $credit->credit;
                }
            });

        $names = Supplier::query()->whereIn('id', array_keys($rows))->pluck('name', 'id');

        return array_values(collect($rows)
            ->map(fn (array $values): array => [
                'supplier_id' => $values['supplier_id'],
                'supplier' => (string) ($names[$values['supplier_id']] ?? ''),
                'current' => $values['current'],
                '1_30' => $values['1_30'],
                '31_60' => $values['31_60'],
                '61_90' => $values['61_90'],
                'over_90' => $values['over_90'],
                'total' => $values['total'],
                'credit' => $values['credit'],
                'balance' => $values['total'] - $values['credit'],
            ])
            ->sortBy([['balance', 'desc'], ['supplier', 'asc']])
            ->values()
            ->all());
    }

    /** @return 'current'|'1_30'|'31_60'|'61_90'|'over_90' */
    public static function bucketOf(int $daysOverdue): string
    {
        return match (true) {
            $daysOverdue <= 0 => 'current',
            $daysOverdue <= 30 => '1_30',
            $daysOverdue <= 60 => '31_60',
            $daysOverdue <= 90 => '61_90',
            default => 'over_90',
        };
    }

    /**
     * The open invoices of one aging bucket.
     *
     * @param  Builder<SupplierPayable>  $query
     * @return Builder<SupplierPayable>
     */
    public function whereBucket(Builder $query, string $bucket): Builder
    {
        $today = $this->today();
        $day = fn (int $days): string => $today->subDays($days)->toDateString();

        return match ($bucket) {
            'current' => $query->where('due_on', '>=', $today->toDateString()),
            '1_30' => $query->where('due_on', '<', $today->toDateString())->where('due_on', '>=', $day(30)),
            '31_60' => $query->where('due_on', '<', $day(30))->where('due_on', '>=', $day(60)),
            '61_90' => $query->where('due_on', '<', $day(60))->where('due_on', '>=', $day(90)),
            'over_90' => $query->where('due_on', '<', $day(90)),
            default => $query,
        };
    }

    /**
     * Open invoices due from today to 6 days ahead ("this week"), and those past their due date.
     *
     * @return array{due_this_week: array{count: int, amount: int}, overdue: array{count: int, amount: int}, week_ends: CarbonImmutable}
     */
    public function dueSoon(): array
    {
        $today = $this->today();
        $weekEnds = $today->addDays(6);
        $sum = fn (Builder $query): array => ['count' => (clone $query)->count(), 'amount' => (int) (clone $query)->sum(DB::raw('amount - allocated'))];

        return [
            // Dates compared as from-inclusive, to-exclusive, as SQLite keeps a time on them.
            'due_this_week' => $sum($this->openQuery()->where('due_on', '>=', $today->toDateString())->where('due_on', '<', $weekEnds->addDay()->toDateString())),
            'overdue' => $sum($this->openQuery()->where('due_on', '<', $today->toDateString())),
            'week_ends' => $weekEnds,
        ];
    }

    /**
     * A supplier statement for $from to $to: the balance brought forward,
     * every document in the period in date order (the date received, paid,
     * returned or as of; with the supplier's invoice date where there is
     * one) with the running balance, and the balance at the end. Positive:
     * the shop owes the supplier.
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable, opening: int, lines: list<array{date: CarbonImmutable, invoice_date: CarbonImmutable|null, document: string, detail: string|null, debit: int, credit: int, balance: int}>, closing: int}
     */
    public function statement(Supplier $supplier, string $from, string $to): array
    {
        $start = CalendarDay::parse($from);
        $end = CalendarDay::parse($to);

        if ($end->lessThan($start)) {
            throw new PosException('The statement ends before it starts.');
        }

        $entries = $this->statementEntries($supplier);
        $opening = 0;
        $lines = [];

        foreach ($entries as $entry) {
            if ($entry['date']->lessThan($start)) {
                $opening += $entry['amount'];
            }
        }

        $balance = $opening;

        foreach ($entries as $entry) {
            if ($entry['date']->lessThan($start) || $entry['date']->greaterThan($end)) {
                continue;
            }

            $balance += $entry['amount'];
            $lines[] = [
                'date' => $entry['date'],
                'invoice_date' => $entry['invoice_date'],
                'document' => $entry['document'],
                'detail' => $entry['detail'],
                'debit' => max($entry['amount'], 0),
                'credit' => max(-$entry['amount'], 0),
                'balance' => $balance,
            ];
        }

        return ['from' => $start, 'to' => $end, 'opening' => $opening, 'lines' => $lines, 'closing' => $balance];
    }

    /**
     * Every document that moves the supplier's balance, in date order
     * (then in the order entered): + owed, − paid or credited.
     *
     * @return list<array{date: CarbonImmutable, invoice_date: CarbonImmutable|null, order: string, document: string, detail: string|null, amount: int}>
     */
    private function statementEntries(Supplier $supplier): array
    {
        $id = (int) $supplier->getKey();
        $day = fn (CarbonInterface $date): CarbonImmutable => CarbonImmutable::parse($date->toDateString());
        $entries = [];

        foreach (SupplierOpeningBalance::query()->where('supplier_id', $id)->get() as $entry) {
            // An opening invoice is listed at its own invoice date, as on the supplier's
            // statement, so the balance brought forward matches theirs; the day it was
            // entered (go-live, an import) is a detail. Lump sums and reversals keep their day.
            $atInvoiceDate = $entry->invoice_date !== null && ! $entry->isReversal();
            $entered = $atInvoiceDate && ! $entry->invoice_date->isSameDay($entry->as_of) ? 'Entered '.$entry->as_of->format('d/m/Y') : null;

            $entries[] = [
                'date' => $day($atInvoiceDate ? $entry->invoice_date : $entry->as_of),
                'invoice_date' => $entry->invoice_date === null ? null : $day($entry->invoice_date),
                'order' => $entry->created_at?->format('YmdHisu').'1'.sprintf('%012d', $entry->getKey()),
                'document' => match (true) {
                    $entry->isReversal() => 'Opening entry reversed',
                    $entry->invoice_no !== null => 'Opening invoice '.$entry->invoice_no,
                    default => 'Opening balance',
                },
                'detail' => implode(' · ', array_filter([$entry->note, $entered], fn (?string $part): bool => filled($part))) ?: null,
                'amount' => $entry->amount,
            ];
        }

        foreach (GoodsReceipt::query()->where('supplier_id', $id)->where('status', GoodsReceiptStatus::Posted)->with('reversalOf')->get() as $receipt) {
            $invoice = $receipt->supplier_invoice_no === null ? null : 'Invoice '.$receipt->supplier_invoice_no;
            $entries[] = [
                'date' => $day($receipt->received_on),
                'invoice_date' => $receipt->supplier_invoice_date === null ? null : $day($receipt->supplier_invoice_date),
                'order' => $receipt->posted_at?->format('YmdHisu').'2'.sprintf('%012d', $receipt->getKey()),
                'document' => (string) $receipt->grn_number,
                'detail' => $receipt->isReversal() ? 'Reverses '.$receipt->reversalOf?->grn_number : $invoice,
                'amount' => $receipt->total,
            ];
        }

        foreach (SupplierReturn::query()->where('supplier_id', $id)->get() as $return) {
            $entries[] = [
                'date' => $day($return->returned_on),
                'invoice_date' => null,
                'order' => $return->created_at?->format('YmdHisu').'3'.sprintf('%012d', $return->getKey()),
                'document' => 'Return '.$return->return_number,
                'detail' => $return->credit_note_no === null ? $return->reason->label() : $return->reason->label().', credit note '.$return->credit_note_no,
                'amount' => -$return->total,
            ];
        }

        foreach (SupplierPayment::query()->where('supplier_id', $id)->get() as $payment) {
            $entries[] = [
                'date' => $day($payment->paid_on),
                'invoice_date' => null,
                'order' => $payment->created_at?->format('YmdHisu').'4'.sprintf('%012d', $payment->getKey()),
                'document' => $payment->isReversal() ? 'Payment reversed' : 'Payment',
                'detail' => trim($payment->method->label().' '.($payment->reference ?? '')),
                'amount' => -$payment->amount,
            ];
        }

        usort($entries, fn (array $a, array $b): int => [$a['date']->toDateString(), $a['order']] <=> [$b['date']->toDateString(), $b['order']]);

        return $entries;
    }

    /**
     * Bring documents from before payables were tracked in (the migration):
     * an open item or credit for each one that lacks it, reversed ones
     * closed, and each supplier's credits allocated oldest due first.
     * Safe to run again: nothing is created twice.
     */
    public function bringIn(): void
    {
        DB::transaction(function (): void {
            $receipts = GoodsReceipt::query()->where('status', GoodsReceiptStatus::Posted)->whereNull('reversal_of_id')->whereDoesntHave('payable')->with(['supplier', 'reversal'])->orderBy('id')->get();

            foreach ($receipts as $receipt) {
                $payable = $this->receiptPosted($receipt);

                if ($payable !== null && $receipt->reversal !== null) {
                    $payable->update(['status' => PayableStatus::Reversed]);
                }
            }

            $openings = SupplierOpeningBalance::query()->whereNull('reversal_of_id')->whereDoesntHave('payable')->whereDoesntHave('credit')->with('reversal')->orderBy('id')->get();

            foreach ($openings as $entry) {
                $this->openingEntered($entry);

                if ($entry->reversal !== null) {
                    SupplierPayable::query()->where('supplier_opening_balance_id', $entry->getKey())->update(['status' => PayableStatus::Reversed]);
                    SupplierCredit::query()->where('supplier_opening_balance_id', $entry->getKey())->update(['is_reversed' => true]);
                }
            }

            foreach (SupplierPayment::query()->whereNull('reversal_of_id')->whereDoesntHave('credit')->withExists('reversal')->orderBy('id')->get() as $payment) {
                SupplierCredit::query()->create([
                    'supplier_id' => $payment->supplier_id,
                    'supplier_payment_id' => $payment->getKey(),
                    'credit_on' => $payment->paid_on->toDateString(),
                    'amount' => $payment->amount,
                    'is_reversed' => (bool) $payment->getAttribute('reversal_exists'),
                ]);
            }

            foreach (SupplierReturn::query()->where('total', '>', 0)->whereDoesntHave('credit')->orderBy('id')->get() as $return) {
                SupplierCredit::query()->create([
                    'supplier_id' => $return->supplier_id,
                    'supplier_return_id' => $return->getKey(),
                    'credit_on' => $return->returned_on->toDateString(),
                    'amount' => $return->total,
                ]);
            }

            $credits = SupplierCredit::query()->where('is_reversed', false)->whereColumn('allocated', '<', 'amount')->orderBy('credit_on')->orderBy('id')->get();

            foreach ($credits as $credit) {
                $plan = $this->suggest((int) $credit->supplier_id, $credit->unallocated());

                if ($plan !== []) {
                    $this->apply($credit, $plan, null, $credit->credit_on->toDateString(), 'Brought in oldest due first');
                }
            }
        });
    }

    // ------------------------------------------------------------------

    /** @return Builder<SupplierPayable> open items, oldest due first */
    private function openQuery(): Builder
    {
        return SupplierPayable::query()
            ->whereIn('status', PayableStatus::openValues())
            ->orderBy('due_on')
            ->orderBy('document_date')
            ->orderBy('id');
    }

    /**
     * Set $plan (payable id => amount) of a credit against open invoices of
     * its supplier. All or nothing: an amount above what is outstanding, or
     * above what is left of the credit, is refused.
     *
     * @param  array<int, int>  $plan
     * @return Collection<int, SupplierAllocation>
     */
    private function apply(SupplierCredit $credit, array $plan, ?User $user, string $day, ?string $note = null): Collection
    {
        $credit = SupplierCredit::query()->whereKey($credit->getKey())->lockForUpdate()->firstOrFail();
        $plan = array_filter($plan, fn (int $amount): bool => $amount !== 0);
        $made = new Collection;

        if ($plan === []) {
            return $made;
        }

        if (min($plan) < 0) {
            throw new PosException('An allocation can\'t be below zero.');
        }

        if ($credit->is_reversed) {
            throw new PosException('That credit has been reversed.');
        }

        if (array_sum($plan) > $credit->unallocated()) {
            throw new PosException('That is more than is left to allocate: '.$this->money($credit->unallocated()).'.');
        }

        ksort($plan);
        $payables = SupplierPayable::query()
            ->with('goodsReceipt')
            ->where('supplier_id', $credit->supplier_id)
            ->whereKey(array_keys($plan))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($plan as $payableId => $amount) {
            /** @var SupplierPayable|null $payable */
            $payable = $payables->get($payableId);

            if ($payable === null) {
                throw new PosException('That invoice is not owed to this supplier.');
            }

            if (! $payable->status->isOpen() || $amount > $payable->outstanding()) {
                throw new PosException('Only '.$this->money($payable->outstanding()).' is outstanding on '.$this->describe($payable).'.');
            }

            $made->push(SupplierAllocation::query()->create([
                'supplier_id' => $credit->supplier_id,
                'supplier_credit_id' => $credit->getKey(),
                'supplier_payable_id' => $payable->getKey(),
                'amount' => $amount,
                'allocated_on' => $day,
                'note' => $note,
                'user_id' => $user?->getKey(),
            ]));

            $payable->update(['allocated' => $payable->allocated + $amount, 'status' => PayableStatus::of($payable->amount, $payable->allocated + $amount)]);
        }

        $credit->update(['allocated' => $credit->allocated + array_sum($plan)]);

        return $made;
    }

    /** Undo every standing allocation to an invoice, then mark it reversed. */
    private function closePayable(SupplierPayable $payable, string $note, ?User $user): void
    {
        foreach ($this->standing()->where('supplier_payable_id', $payable->getKey())->lockForUpdate()->get() as $allocation) {
            $this->undo($allocation, $note, $user);
        }

        $payable->refresh()->update(['status' => PayableStatus::Reversed]);
    }

    /** Undo every standing allocation of a credit, then mark it reversed. */
    private function closeCredit(SupplierCredit $credit, string $note, ?User $user): void
    {
        foreach ($this->standing()->where('supplier_credit_id', $credit->getKey())->lockForUpdate()->get() as $allocation) {
            $this->undo($allocation, $note, $user);
        }

        $credit->refresh()->update(['is_reversed' => true]);
    }

    /** @return Builder<SupplierAllocation> allocations not undone, in the order made */
    private function standing(): Builder
    {
        return SupplierAllocation::query()->whereNull('reversal_of_id')->whereDoesntHave('reversal')->orderBy('id');
    }

    private function undo(SupplierAllocation $allocation, string $note, ?User $user): SupplierAllocation
    {
        $reversal = SupplierAllocation::query()->create([
            'supplier_id' => $allocation->supplier_id,
            'supplier_credit_id' => $allocation->supplier_credit_id,
            'supplier_payable_id' => $allocation->supplier_payable_id,
            'amount' => -$allocation->amount,
            'allocated_on' => $this->today()->toDateString(),
            'reversal_of_id' => $allocation->getKey(),
            'note' => $note,
            'user_id' => $user?->getKey(),
        ]);

        $payable = SupplierPayable::query()->whereKey($allocation->supplier_payable_id)->lockForUpdate()->firstOrFail();
        $allocated = $payable->allocated - $allocation->amount;
        $payable->update([
            'allocated' => $allocated,
            'status' => $payable->status === PayableStatus::Reversed ? PayableStatus::Reversed : PayableStatus::of($payable->amount, $allocated),
        ]);

        $credit = SupplierCredit::query()->whereKey($allocation->supplier_credit_id)->lockForUpdate()->firstOrFail();
        $credit->update(['allocated' => $credit->allocated - $allocation->amount]);

        return $reversal;
    }

    /** "GRN-000003 (invoice HPL-55210)", "opening invoice INV-9". */
    public function describe(SupplierPayable $payable): string
    {
        $label = $payable->goods_receipt_id !== null ? $payable->documentLabel() : lcfirst($payable->documentLabel());

        return $label.($payable->invoice_no === null ? '' : ($payable->goods_receipt_id !== null ? " (invoice {$payable->invoice_no})" : " {$payable->invoice_no}"));
    }

    /** The supplier row, locked: allocations of one supplier one at a time. */
    public function lockSupplier(int $supplierId): Supplier
    {
        return Supplier::query()->whereKey($supplierId)->lockForUpdate()->firstOrFail();
    }

    private function money(int $minor): string
    {
        return $this->currentShop->getOrFail()->formatMoney($minor);
    }

    private function authorize(User $user): void
    {
        if (! $user->can('purchasing.manage')) {
            throw new PosException('You do not have permission to pay suppliers.');
        }

        if (! $this->currentShop->getOrFail()->hasModule('purchasing')) {
            throw new PosException('This needs the purchasing module.');
        }
    }
}
