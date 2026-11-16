<?php

namespace App\Services;

use App\Enums\SaleStatus;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\NoReceiptReturn;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Shift;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Support\CurrentShop;
use Illuminate\Support\Collection;

/**
 * The printed shift summary (docs/PHARMACY.md §9, branches 11 and 17): who
 * may print it, the receipt printer's payload, and its reprints.
 *
 * The first print, from the till's Close shift dialog, is the summary page
 * as it is. A reprint (the till's Shift summaries, or the admin's Shift
 * history) is logged first, like a sale's reprint, and its page is marked
 * REPRINT with when and by whom (ReceiptService::reprintStamp()). Reprints
 * are a pharmacy feature: a retail shop keeps its summary as it was.
 */
class ShiftSummaryService
{
    public const REPRINT_EVENT = 'shift_summary_reprinted';

    /** The receipt printer's layout, or the A4 page. */
    public const FORMATS = ['thermal', 'a4'];

    /** How far back the till's Shift summaries look, in days (closed in the last 7 days). */
    public const REPRINT_DAYS = 7;

    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly ShiftService $shifts,
        private readonly ReceiptService $receipts,
        private readonly AuditObserver $audit,
    ) {}

    /** A cashier reads only their own shift; shifts.manage reads any. */
    public static function mayRead(?User $user, Shift $shift): bool
    {
        return $user !== null && ((int) $shift->user_id === (int) $user->getKey() || $user->can('shifts.manage'));
    }

    /** Whether the shop reprints shift summaries (pharmacy). */
    public function reprintsEnabled(): bool
    {
        return $this->currentShop->getOrFail()->hasModule('pharmacy');
    }

    /**
     * The formats a reprint may take in this shop: the receipt printer's when
     * the shop prints thermal receipts, and A4.
     *
     * @return list<string>
     */
    public function formats(): array
    {
        return $this->receipts->usesThermalReceipt() ? self::FORMATS : ['a4'];
    }

    /**
     * This till's shifts closed in the last REPRINT_DAYS days that $user may
     * print (their own, or every one with shifts.manage), newest first, with
     * the net sales ShiftService::summary() would print.
     *
     * @return Collection<int, array{uuid: string, closed_at: string, cashier: string, net: int}>
     */
    public function closedShifts(Device $till, User $user): Collection
    {
        $timezone = $this->timezone();
        $shifts = Shift::query()
            ->with('user')
            ->where('device_id', $till->getKey())
            ->whereNotNull('closed_at')
            ->where('closed_at', '>=', now()->subDays(self::REPRINT_DAYS))
            ->when(! $user->can('shifts.manage'), fn ($query) => $query->where('user_id', $user->getKey()))
            ->orderByDesc('closed_at')
            ->orderByDesc('id')
            ->get();

        $ids = $shifts->modelKeys();
        // Net as ShiftService::summary(): bills not voided, less the refunds given in the shift.
        $gross = Sale::query()->toBase()->whereIn('shift_id', $ids)->where('status', '!=', SaleStatus::Voided->value)
            ->groupBy('shift_id')->selectRaw('shift_id, SUM(grand_total) as total')->pluck('total', 'shift_id');
        $refunds = SaleReturn::query()->toBase()->whereIn('shift_id', $ids)
            ->groupBy('shift_id')->selectRaw('shift_id, SUM(refund_total) as total')->pluck('total', 'shift_id');
        // Returns without a receipt are refunds too (branch 18).
        $noReceipt = NoReceiptReturn::query()->toBase()->whereIn('shift_id', $ids)
            ->groupBy('shift_id')->selectRaw('shift_id, SUM(refund_total) as total')->pluck('total', 'shift_id');

        return $shifts->map(fn (Shift $shift): array => [
            'uuid' => (string) $shift->uuid,
            'closed_at' => (string) $shift->closed_at?->setTimezone($timezone)->format('Y-m-d H:i'),
            'cashier' => (string) $shift->user?->name,
            'net' => (int) ($gross[$shift->getKey()] ?? 0) - (int) ($refunds[$shift->getKey()] ?? 0) - (int) ($noReceipt[$shift->getKey()] ?? 0),
        ])->values();
    }

    /**
     * Logs a reprint of a closed shift's summary. The entry is what the
     * reprint's REPRINT line shows. $till is the till it was reprinted from
     * (null from the admin panel).
     */
    public function recordReprint(Shift $shift, User $user, ?Device $till, string $format): AuditLog
    {
        if (! in_array($format, $this->formats(), true) || $shift->isOpen()) {
            throw new \InvalidArgumentException("Cannot reprint the summary of shift {$shift->uuid} as {$format}.");
        }

        return $this->audit->record(self::REPRINT_EVENT, $shift, [], [
            'shift_id' => $shift->getKey(),
            'shift_uuid' => $shift->uuid,
            'format' => $format,
            'source' => $till === null ? 'admin' : 'till',
            'device_id' => $till?->getKey(),
            'till' => $till?->code,
        ], (int) $user->getKey());
    }

    /** A reprint entry of this shift in the current shop, or null. Audit logs are not tenant-scoped, so the shop is checked here. */
    public function findReprint(Shift $shift, int $auditLogId): ?AuditLog
    {
        return AuditLog::query()
            ->with('user')
            ->whereKey($auditLogId)
            ->where('shop_id', $this->currentShop->id())
            ->where('event', self::REPRINT_EVENT)
            ->where('auditable_type', $shift->getMorphClass())
            ->where('auditable_id', $shift->getKey())
            ->first();
    }

    /** The format a reprint entry was printed in. */
    public static function reprintedFormat(AuditLog $entry): string
    {
        return ($entry->new_values['format'] ?? null) === 'a4' ? 'a4' : 'thermal';
    }

    /**
     * The shift summary for the receipt printer: ShiftService::summary() as
     * it is, the shift's details in shop time, and two figures the A4 page
     * does not have, read from the shift's bills: the invoice range (first
     * and last bill rung up, voided ones included: their numbers were used)
     * and the discounts given on the bills that were not voided. A reprint
     * also carries its REPRINT stamp.
     *
     * @return array<string, mixed>
     */
    public function thermalPayload(Shift $shift, ?AuditLog $reprint = null): array
    {
        $timezone = $this->timezone();
        $bills = Sale::query()->where('shift_id', $shift->getKey());
        $first = (clone $bills)->orderBy('sold_at')->orderBy('id')->value('invoice_no');
        $last = (clone $bills)->orderByDesc('sold_at')->orderByDesc('id')->value('invoice_no');
        $device = $shift->device;

        return [
            'till' => $device === null ? null : [
                'code' => (string) $device->code,
                'name' => $device->name,
                'location' => $device->location?->name,
            ],
            'cashier' => (string) $shift->user?->name,
            'openedAt' => $shift->opened_at->setTimezone($timezone)->format('Y-m-d H:i'),
            'closedAt' => $shift->closed_at?->setTimezone($timezone)->format('Y-m-d H:i'),
            'printedAt' => now($timezone)->format('Y-m-d H:i'),
            'invoices' => $first === null ? null : ['first' => (string) $first, 'last' => (string) $last],
            'discounts' => (int) (clone $bills)->where('status', '!=', SaleStatus::Voided->value)->sum('discount_total'),
            'summary' => $this->shifts->summary($shift),
            'reprint' => $this->receipts->reprintStamp($reprint),
        ];
    }

    public function timezone(): string
    {
        return (string) ($this->currentShop->getOrFail()->timezone ?: config('app.timezone'));
    }
}
