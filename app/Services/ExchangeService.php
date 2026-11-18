<?php

namespace App\Services;

use App\Enums\BillType;
use App\Enums\CashMovementType;
use App\Enums\PaymentMethod;
use App\Enums\ReturnReason;
use App\Enums\SaleStatus;
use App\Exceptions\PosException;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Doctor;
use App\Models\Exchange;
use App\Models\Location;
use App\Models\NoReceiptReturn;
use App\Models\Patient;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Shift;
use App\Models\Shop;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Services\Data\CheckoutData;
use App\Services\Data\ExchangeData;
use App\Services\Data\PatientInput;
use App\Services\Data\PaymentInput;
use App\Services\Data\ReturnLine;
use App\Support\CurrentShop;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Returns and exchanges at the till (docs/PHARMACY.md, branch 19). A patient
 * brings a medicine back (not effective, side effects, wrong medicine) and
 * the doctor gives another: in one transaction, the return (of the bill's
 * lines through ReturnService, or without a bill through
 * NoReceiptReturnService, owner only) and the new bill (CheckoutService, the
 * same patient), paid with the return's credit (payment method
 * "exchange", Exchange credit) up to its total, plus whatever the patient
 * pays on top. What is left of the credit is refunded in cash from this
 * till's open shift, within the user's paid out limit (Shop settings → Cash
 * in and out), so the drawer moves by the difference only. No new items: a
 * plain return.
 *
 * Standard bills only (an OPD bill's medicines are one typed amount: a
 * follow-up), pharmacy only, online only. A bill older than the shop's
 * exchange_max_days needs pos.exchange.late. Numbered EX-000001, audited
 * (sale_exchanged), one EXCHANGE receipt; a retry with the same uuid returns
 * the same exchange.
 */
class ExchangeService
{
    public const EXCHANGED_EVENT = 'sale_exchanged';

    public const REPRINT_EVENT = 'exchange_reprinted';

    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly ReturnService $returns,
        private readonly NoReceiptReturnService $noReceipt,
        private readonly CheckoutService $checkout,
        private readonly CashMovementService $cash,
        private readonly AuditObserver $audit,
    ) {}

    public function enabled(): bool
    {
        return $this->shop()->hasModule('pharmacy');
    }

    public function create(ExchangeData $data): Exchange
    {
        if (! $this->enabled()) {
            throw new PosException('This shop does not take returns or exchanges at the till.');
        }

        if (! $data->user->can('pos.exchange')) {
            throw new PosException('You are not allowed to return or exchange items at the till.');
        }

        if (($existing = $this->existing($data)) !== null) {
            return $existing;
        }

        $this->checkRequest($data);

        // What the new items cost, priced exactly as checkout will (it refuses the bill if this changes).
        $newTotal = $data->newLines === [] ? 0 : (int) $this->checkout->quote($data->newLines, $data->saleDiscount, $data->user, $data->till)['grand_total'];

        try {
            return DB::transaction(fn (): Exchange => $this->record($data, $newTotal), attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            // The same exchange sent twice at once: the first one won.
            return $this->existing($data) ?? throw $exception;
        }
    }

    /**
     * Who may print an exchange's receipt: whoever made it, the shift's
     * cashier, a user who may read shifts, or one who may exchange at the
     * till (the till's This shift list).
     */
    public function mayRead(?User $user, Exchange $exchange): bool
    {
        return $user !== null && (
            (int) $exchange->user_id === (int) $user->getKey()
            || $user->can('shifts.manage')
            || $user->can('pos.exchange')
            || (int) $exchange->shift?->user_id === (int) $user->getKey()
        );
    }

    public function recordReprint(Exchange $exchange, User $user, ?Device $till): AuditLog
    {
        if (! $this->mayRead($user, $exchange)) {
            throw new PosException('You are not allowed to print this receipt.');
        }

        return $this->audit->record(self::REPRINT_EVENT, $exchange, [], [
            'number' => $exchange->number,
            'device_id' => $till?->getKey(),
            'till' => $till?->code,
            'source' => $till === null ? 'admin' : 'till',
        ], (int) $user->getKey());
    }

    /** A reprint entry of this exchange in the current shop, or null (audit logs are not tenant-scoped). */
    public function findReprint(Exchange $exchange, int $auditLogId): ?AuditLog
    {
        return AuditLog::query()
            ->with('user')
            ->whereKey($auditLogId)
            ->where('shop_id', $this->currentShop->id())
            ->where('event', self::REPRINT_EVENT)
            ->where('auditable_type', $exchange->getMorphClass())
            ->where('auditable_id', $exchange->getKey())
            ->first();
    }

    /**
     * The receipt for ReceiptLayout::exchange(), in shop time: the bill the
     * items came from, the items returned (as the return recorded them) with
     * their credit and where they went, the new bill as ReceiptService::sale()
     * prints it, and the money. With $reprint, marked as a reprint.
     *
     * @return array<string, mixed>
     */
    public function receipt(Exchange $exchange, ?AuditLog $reprint = null): array
    {
        $receipts = app(ReceiptService::class);
        $exchange->loadMissing(['originalSale', 'newSale', 'saleReturn.items.location', 'noReceiptReturn', 'device', 'location', 'user', 'doctor', 'patient']);
        $timezone = (string) ($this->shop()->timezone ?: config('app.timezone'));
        $original = $exchange->originalSale;
        $device = $exchange->device;

        if ($exchange->saleReturn !== null) {
            // refund() lists the return's lines in their order: the same line, the same index.
            $lines = $exchange->saleReturn->items->values();
            $refund = $receipts->refund($exchange->saleReturn);
            /** @var list<array<string, mixed>> $items */
            $items = $refund['items'];
            $returned = [];

            foreach ($items as $i => $item) {
                $returned[] = [...$item, 'credit' => (int) $item['refund'], 'to' => self::destination($lines[$i]->location ?? null)];
            }
            $taxRate = (float) $refund['sale']['taxRate'];
        } else {
            $slip = $this->noReceipt->slip($exchange->noReceiptReturn ?? throw new \LogicException('An exchange has a return.'));
            $returned = array_map(fn (array $item): array => [
                'description' => $item['description'],
                'unitName' => $item['unitName'],
                'quantity' => $item['quantity'],
                'credit' => (int) $item['refund'],
                'to' => $item['to'],
                'batches' => filled($item['batchNo']) ? [['batchNo' => $item['batchNo'], 'expiry' => $item['expiry'], 'quantity' => $item['quantity']]] : [],
            ], $slip['items']);
            $taxRate = 0.0;
        }

        $patient = match (true) {
            $exchange->newSale !== null => ReceiptService::patient($exchange->newSale),
            $original !== null => ReceiptService::patient($original),
            default => $exchange->patient === null ? null : ['name' => $exchange->patient->name, 'age' => null],
        };

        return [
            'number' => $exchange->number,
            'exchangedAt' => $exchange->exchanged_at->setTimezone($timezone)->format('Y-m-d H:i'),
            'till' => $device === null ? null : ['code' => (string) $device->code, 'name' => $device->name, 'location' => $exchange->location?->name],
            'user' => (string) $exchange->user?->name,
            'patient' => $patient,
            'doctor' => $exchange->doctor?->name,
            'original' => $original === null ? null : ['invoiceNo' => $original->invoice_no, 'soldAt' => $original->sold_at->setTimezone($timezone)->format('Y-m-d H:i')],
            'noReceiptNo' => $exchange->noReceiptReturn?->number,
            'returned' => array_values($returned),
            'taxRate' => $taxRate,
            'creditTotal' => $exchange->credit_total,
            'creditApplied' => $exchange->credit_applied,
            'patientPaid' => $exchange->patient_paid,
            'cashRefunded' => $exchange->cash_refunded,
            'newSale' => $exchange->newSale === null ? null : $receipts->sale($exchange->newSale),
            'reason' => $exchange->reason->label(),
            'note' => $exchange->note,
            'reprint' => $receipts->reprintStamp($reprint),
        ];
    }

    /** Where returned stock went, as printed. */
    public static function destination(?Location $to): string
    {
        return match (true) {
            $to === null => 'Not put back',
            $to->is_sellable => 'Back on sale: '.$to->name,
            default => 'To '.$to->name,
        };
    }

    /**
     * How many whole shop days old a bill is (sold yesterday: 1).
     */
    public function ageInDays(Sale $sale): int
    {
        $timezone = (string) ($this->shop()->timezone ?: config('app.timezone'));
        $sold = CarbonImmutable::parse($sale->sold_at)->setTimezone($timezone)->startOfDay();
        $today = CarbonImmutable::now($timezone)->startOfDay();

        return max(0, (int) $sold->diffInDays($today));
    }

    /** The shop's time limit in days; 0 = none. */
    public function maxDays(): int
    {
        return max(0, (int) $this->shop()->setting('exchange_max_days', 14));
    }

    /**
     * Why this bill can't be returned or exchanged at the till by $user, or
     * null when it can: the till's search shows it before anything is ticked.
     */
    public function refusal(Sale $sale, User $user): ?string
    {
        if ($sale->bill_type === BillType::Opd) {
            return "{$sale->invoice_no} is an OPD bill: its medicines are one amount, so it is returned from the admin (Sales).";
        }

        if (in_array($sale->status, [SaleStatus::Voided, SaleStatus::Returned], true)) {
            return "{$sale->invoice_no} is {$sale->status->value}: nothing is left to return.";
        }

        $max = $this->maxDays();
        $age = $this->ageInDays($sale);

        if ($max > 0 && $age > $max && ! $user->can('pos.exchange.late')) {
            return "{$sale->invoice_no} is {$age} days old; the shop takes returns and exchanges at the till up to {$max} days. Ask the owner.";
        }

        return null;
    }

    /** The most $user may refund in cash in one return or exchange; null = no limit (their paid out limit). */
    public function cashLimitFor(User $user): ?int
    {
        return $this->cash->limitFor($user, CashMovementType::PaidOut);
    }

    /** @return array{0: Location|null, 1: Location} [Quarantine (may be missing), the till's location] */
    public function destinations(Device $till): array
    {
        /** @var Location $here */
        $here = $till->location ?? throw new PosException('This till has no location.');

        return [$this->noReceipt->quarantine(), $here];
    }

    private function existing(ExchangeData $data): ?Exchange
    {
        $exchange = Exchange::query()->withoutGlobalScope('shop')->where('uuid', $data->uuid)->first();

        if ($exchange === null) {
            return null;
        }

        if ((int) $exchange->shop_id !== (int) $this->shop()->getKey() || (int) $exchange->device_id !== (int) $data->till->getKey()) {
            throw new PosException('This exchange id belongs to another till.');
        }

        return $exchange;
    }

    /** What can be refused before anything is locked. */
    private function checkRequest(ExchangeData $data): void
    {
        if ((int) $data->till->shop_id !== (int) $this->shop()->getKey() || ! ($data->till->is_active ?? true)) {
            throw new PosException('This till is not registered or has been disabled.');
        }

        if (! in_array($data->reason, ReturnReason::tillReasons(), true)) {
            throw new PosException('Choose why the items came back.');
        }

        if ($data->reason === ReturnReason::Other && blank($data->note)) {
            throw new PosException('Say why in the note (reason Other).');
        }

        if ($data->doctorId !== null && ! Doctor::query()->whereKey($data->doctorId)->where('is_active', true)->exists()) {
            throw new PosException('That doctor was not found.');
        }

        if ($data->original === null) {
            if (! $data->user->can('returns.no_receipt')) {
                throw new PosException('Only the owner takes items back without their bill.');
            }

            if ($data->noReceiptLines === []) {
                throw new PosException('Add the item that came back.');
            }

            return;
        }

        if ($data->returnLines === []) {
            throw new PosException('Tick what came back.');
        }

        if (($refusal = $this->refusal($data->original, $data->user)) !== null) {
            throw new PosException($refusal);
        }

        [$quarantine, $here] = $this->destinations($data->till);
        $allowed = array_filter([$quarantine?->getKey(), $here->getKey()]);

        foreach ($data->returnLines as $line) {
            if ($line->toLocationId === null || ! in_array($line->toLocationId, $allowed, true)) {
                throw new PosException($quarantine === null
                    ? 'This shop has no quarantine location: put the items back on sale, or add a location that does not sell.'
                    : 'Send each item to Quarantine or back on sale here.');
            }
        }
    }

    private function record(ExchangeData $data, int $newTotal): Exchange
    {
        // The shop row numbers one exchange at a time (EX-000001); a retry waiting here finds the first.
        Shop::query()->whereKey($this->shop()->getKey())->lockForUpdate()->firstOrFail();

        if (($existing = $this->existing($data)) !== null) {
            return $existing;
        }

        $shift = $this->lockOpenShift($data->till);
        $reasonText = $data->reason->label().(blank($data->note) ? '' : ': '.trim((string) $data->note));

        /** @var SaleReturn|NoReceiptReturn $return */
        $return = $data->original === null
            ? $this->noReceipt->create($data->till, $data->user, $data->noReceiptLines, $reasonText)
            : $this->returns->atTill($data->original, array_map(fn (ReturnLine $line): ReturnLine => new ReturnLine(
                $line->saleItemId, $line->quantity, $line->inBaseUnits, true, $line->batches, $line->toLocationId,
            ), $data->returnLines), $data->reason, $data->user, $shift, $data->note);

        $credit = (int) $return->refund_total;
        $applied = min($credit, $newTotal);
        $cashBack = $credit - $applied;
        $limit = $this->cashLimitFor($data->user);

        if ($limit !== null && $cashBack > $limit) {
            throw new PosException('Refunding '.Money::format($cashBack).' in cash is above your limit of '.Money::format($limit).'. Ask the owner.');
        }

        $return->update(['exchange_credit' => $applied]);

        $original = $data->original?->fresh();
        $patientInput = $original?->patient_id !== null
            ? new PatientInput((string) Patient::query()->whereKey($original->patient_id)->value('uuid'))
            : $data->patient;

        $sale = null;

        if ($data->newLines !== []) {
            $sale = $this->checkout->checkout(new CheckoutData(
                device: $data->till,
                cashier: $data->user,
                lines: $data->newLines,
                payments: [
                    ...($applied > 0 ? [new PaymentInput(PaymentMethod::Exchange, $applied)] : []),
                    ...$data->payments,
                ],
                saleDiscount: $data->saleDiscount,
                uuid: (string) Str::uuid7(),
                notes: 'Exchange for '.($original->invoice_no ?? $return->getAttribute('number')),
                patient: $patientInput,
                exchangeCredit: true,
            ));
        }

        $patientId = $original->patient_id ?? $sale->patient_id
            ?? ($data->patient === null ? null : Patient::query()->where('uuid', $data->patient->uuid)->value('id'));

        $exchange = Exchange::query()->create([
            'uuid' => $data->uuid,
            'number' => $this->nextNumber(),
            'shift_id' => $shift->getKey(),
            'device_id' => $data->till->getKey(),
            'location_id' => $data->till->location_id,
            'user_id' => $data->user->getKey(),
            'patient_id' => $patientId,
            'doctor_id' => $data->doctorId,
            'reason' => $data->reason,
            'note' => blank($data->note) ? null : trim((string) $data->note),
            'original_sale_id' => $original?->getKey(),
            'sale_return_id' => $return instanceof SaleReturn ? $return->getKey() : null,
            'no_receipt_return_id' => $return instanceof NoReceiptReturn ? $return->getKey() : null,
            'new_sale_id' => $sale?->getKey(),
            'credit_total' => $credit,
            'credit_applied' => $applied,
            'new_total' => (int) ($sale->grand_total ?? 0),
            'patient_paid' => (int) ($sale->grand_total ?? 0) - $applied,
            'cash_refunded' => $cashBack,
            'exchanged_at' => now(),
        ]);

        $this->audit->record(self::EXCHANGED_EVENT, $exchange, [], [
            'number' => $exchange->number,
            'kind' => $sale === null ? 'return' : 'exchange',
            'original_invoice_no' => $original?->invoice_no,
            'return' => $return instanceof SaleReturn ? $return->return_no : $return->getAttribute('number'),
            'new_invoice_no' => $sale?->invoice_no,
            'credit' => $credit,
            'credit_applied' => $applied,
            'new_total' => $exchange->new_total,
            'patient_paid' => $exchange->patient_paid,
            'cash_refunded' => $cashBack,
            'reason' => $data->reason->value,
            'note' => $exchange->note,
            'doctor_id' => $data->doctorId,
            'patient_id' => $patientId,
            'shift_id' => $shift->getKey(),
            'till' => $data->till->code,
        ], (int) $data->user->getKey());

        return $exchange;
    }

    private function lockOpenShift(Device $till): Shift
    {
        /** @var Shift|null $shift */
        $shift = Shift::query()
            ->where('device_id', $till->getKey())
            ->whereNull('closed_at')
            ->latest('opened_at')
            ->lockForUpdate()
            ->first();

        return $shift ?? throw new PosException('Open a shift on this till first: returns and exchanges belong to a shift\'s drawer.');
    }

    /** The next number, per shop. Only with the shop row locked. */
    private function nextNumber(): string
    {
        return 'EX-'.str_pad((string) (Exchange::query()->count() + 1), 6, '0', STR_PAD_LEFT);
    }

    private function shop(): Shop
    {
        return $this->currentShop->getOrFail();
    }
}
