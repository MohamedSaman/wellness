<?php

namespace App\Services;

use App\Enums\CashMovementCategory;
use App\Enums\CashMovementType;
use App\Exceptions\PosException;
use App\Models\AuditLog;
use App\Models\CashMovement;
use App\Models\Device;
use App\Models\Doctor;
use App\Models\Shift;
use App\Models\Shop;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Support\CurrentShop;
use App\Support\ReportPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cash paid out of or into a till's drawer in its open shift, and cash paid
 * to a doctor (docs/PHARMACY.md, branch 18). Each one comes off (or adds to)
 * that shift's expected cash only (ShiftService), prints a slip, and is
 * audited. Online only: the till has no outbox for them, as for closing a
 * shift.
 *
 * Rules, checked here and not only by the till's buttons:
 * - the cash_movements module (a doctor payment also needs pharmacy);
 * - cash.movements for paid out and paid in, doctors.pay for a doctor payment;
 * - an open shift on the till, locked while the movement is written so a
 *   closing shift can't miss it;
 * - an amount above zero, within the user's role limit (paid out and doctor
 *   payments; paid in has none);
 * - a category that fits the type, a note for "Other", an active doctor for
 *   a doctor payment.
 *
 * A void (cash.movements.void, the owner) needs a reason and an open shift:
 * a closed shift has stored its expected cash, so it is history.
 */
class CashMovementService
{
    public const CREATED_EVENT = 'cash_movement_created';

    public const VOIDED_EVENT = 'cash_movement_voided';

    public const REPRINT_EVENT = 'cash_movement_reprinted';

    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly AuditObserver $audit,
    ) {}

    /** Whether the shop records cash movements (the cash_movements module). */
    public function enabled(): bool
    {
        return $this->shop()->hasModule('cash_movements');
    }

    /** Whether the shop pays doctors from the till (cash movements and pharmacy). */
    public function doctorPaymentsEnabled(): bool
    {
        return $this->enabled() && $this->shop()->hasModule('pharmacy');
    }

    public function create(
        Device $till,
        User $user,
        CashMovementType $type,
        int $amount,
        ?CashMovementCategory $category = null,
        ?Doctor $doctor = null,
        ?string $note = null,
    ): CashMovement {
        $note = blank($note) ? null : trim((string) $note);
        $this->assertMayCreate($user, $type);

        if ($amount <= 0) {
            throw new PosException('Enter an amount above zero.');
        }

        $limit = $this->limitFor($user, $type);

        if ($limit !== null && $amount > $limit) {
            throw new PosException('The most you may '.($type === CashMovementType::DoctorPayment ? 'pay a doctor' : 'pay out').' at once is '.$this->shop()->formatMoney($limit).'. Ask the owner.');
        }

        if ($type === CashMovementType::DoctorPayment) {
            $category = null;

            if ($doctor === null) {
                throw new PosException('Choose the doctor.');
            }

            if ((int) $doctor->shop_id !== (int) $this->shop()->getKey() || ! $doctor->is_active) {
                throw new PosException("{$doctor->name} is not an active doctor of this shop.");
            }
        } else {
            $doctor = null;

            if ($category === null || ! in_array($category, $type->categories(), true)) {
                throw new PosException('Choose what the cash was '.($type === CashMovementType::PaidIn ? 'paid in' : 'paid out').' for.');
            }

            if ($category === CashMovementCategory::Other && $note === null) {
                throw new PosException('Say what the cash was for in the note.');
            }
        }

        if ($note !== null && mb_strlen($note) > 500) {
            throw new PosException('A note can have at most 500 characters.');
        }

        return DB::transaction(function () use ($till, $user, $type, $amount, $category, $doctor, $note): CashMovement {
            // The shop row numbers one movement at a time (CM-000001); the shift row keeps a closing shift waiting.
            Shop::query()->whereKey($this->shop()->getKey())->lockForUpdate()->firstOrFail();
            $shift = $this->lockOpenShift($till);

            $movement = CashMovement::query()->create([
                'number' => $this->nextNumber(),
                'shift_id' => $shift->getKey(),
                'device_id' => $till->getKey(),
                'location_id' => $till->location_id,
                'user_id' => $user->getKey(),
                'type' => $type,
                'category' => $category,
                'amount' => $amount,
                'doctor_id' => $doctor?->getKey(),
                'note' => $note,
            ]);

            $this->audit->record(self::CREATED_EVENT, $movement, [], [
                'number' => $movement->number,
                'type' => $type->value,
                'category' => $category?->value,
                'amount' => $amount,
                'doctor_id' => $doctor?->getKey(),
                'doctor' => $doctor?->name,
                'shift_id' => $shift->getKey(),
                'till' => $till->code,
                'note' => $note,
            ], (int) $user->getKey());

            return $movement;
        });
    }

    /** Voids a movement of an open shift: the owner (cash.movements.void), with a reason. */
    public function void(CashMovement $movement, User $user, string $reason): CashMovement
    {
        if (! $user->can('cash.movements.void')) {
            throw new PosException('You are not allowed to void cash movements.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new PosException('Give the reason for voiding it.');
        }

        return DB::transaction(function () use ($movement, $user, $reason): CashMovement {
            /** @var Shift $shift */
            $shift = Shift::query()->whereKey($movement->shift_id)->lockForUpdate()->firstOrFail();
            /** @var CashMovement $movement */
            $movement = CashMovement::query()->whereKey($movement->getKey())->lockForUpdate()->firstOrFail();

            if ($movement->isVoided()) {
                throw new PosException("{$movement->number} is already voided.");
            }

            if (! $shift->isOpen()) {
                throw new PosException("{$movement->number}'s shift is closed: its cash was counted, so it can't be voided.");
            }

            $movement->update(['voided_at' => now(), 'voided_by' => $user->getKey(), 'void_reason' => mb_substr($reason, 0, 255)]);

            $this->audit->record(self::VOIDED_EVENT, $movement, [], [
                'number' => $movement->number,
                'type' => $movement->type->value,
                'amount' => $movement->amount,
                'shift_id' => $shift->getKey(),
                'reason' => $movement->void_reason,
            ], (int) $user->getKey());

            return $movement;
        });
    }

    /**
     * The most one movement of this type may be for the user, in minor units,
     * or null for no limit: the highest of their roles' limits
     * (cash_movement_limits); a role not listed, or listed with none, has no
     * limit. Paid in has no limit.
     */
    public function limitFor(User $user, CashMovementType $type): ?int
    {
        if ($type === CashMovementType::PaidIn) {
            return null;
        }

        $key = $type === CashMovementType::DoctorPayment ? 'doctor_payment' : 'paid_out';
        $limits = (array) $this->shop()->setting('cash_movement_limits', []);
        $highest = null;

        foreach ($user->getRoleNames() as $role) {
            $limit = $limits[$role][$key] ?? null;

            if ($limit === null || $limit === '') {
                return null;
            }

            $highest = max($highest ?? 0, (int) $limit);
        }

        return $highest;
    }

    /** Logs a reprint of a movement's slip; the entry is what the slip's REPRINT line shows. */
    public function recordReprint(CashMovement $movement, User $user, ?Device $till): AuditLog
    {
        $this->assertMayRead($user, $movement);

        return $this->audit->record(self::REPRINT_EVENT, $movement, [], [
            'number' => $movement->number,
            'device_id' => $till?->getKey(),
            'till' => $till?->code,
            'source' => $till === null ? 'admin' : 'till',
        ], (int) $user->getKey());
    }

    /** A reprint entry of this movement in the current shop, or null (audit logs are not tenant-scoped). */
    public function findReprint(CashMovement $movement, int $auditLogId): ?AuditLog
    {
        return AuditLog::query()
            ->with('user')
            ->whereKey($auditLogId)
            ->where('shop_id', $this->currentShop->id())
            ->where('event', self::REPRINT_EVENT)
            ->where('auditable_type', $movement->getMorphClass())
            ->where('auditable_id', $movement->getKey())
            ->first();
    }

    /**
     * Who may print a movement's slip: whoever made it, a user who may read
     * its shift (its cashier, or shifts.manage), or one who may see every
     * doctor's payments (doctors.manage) for a doctor payment.
     */
    public function mayRead(?User $user, CashMovement $movement): bool
    {
        if ($user === null) {
            return false;
        }

        return (int) $movement->user_id === (int) $user->getKey()
            || $user->can('shifts.manage')
            || ($movement->type === CashMovementType::DoctorPayment && $user->can('doctors.manage'))
            || (int) $movement->shift?->user_id === (int) $user->getKey();
    }

    /**
     * The movements of a till's open shift, newest first (what the cashier
     * sees at the till); none without an open shift.
     *
     * @return Collection<int, CashMovement>
     */
    public function openShiftMovements(Device $till): Collection
    {
        $shift = Shift::query()->where('device_id', $till->getKey())->whereNull('closed_at')->latest('opened_at')->first();

        if ($shift === null) {
            return new Collection;
        }

        return CashMovement::query()
            ->with(['user', 'doctor', 'voider'])
            ->where('shift_id', $shift->getKey())
            ->orderByDesc('id')
            ->get();
    }

    /**
     * A shift's movements that count, added up by type, in minor units.
     *
     * @return array{paid_in: int, paid_out: int, doctor_payments: int}
     */
    public function shiftTotals(Shift $shift): array
    {
        $totals = CashMovement::query()->toBase()
            ->where('shift_id', $shift->getKey())
            ->whereNull('voided_at')
            ->groupBy('type')
            ->selectRaw('type, SUM(amount) as amount')
            ->pluck('amount', 'type');

        return [
            'paid_in' => (int) ($totals[CashMovementType::PaidIn->value] ?? 0),
            'paid_out' => (int) ($totals[CashMovementType::PaidOut->value] ?? 0),
            'doctor_payments' => (int) ($totals[CashMovementType::DoctorPayment->value] ?? 0),
        ];
    }

    /**
     * What the Pay doctor dialog shows for reference (no amount is worked out
     * from it): today's OPD bills and consultation fees at every till, net of
     * refunds, and today's payments to doctors at every till (shop days).
     *
     * @return array{opd_bills: int, consultation_fees: int, paid_today: list<array{doctor_id: int, doctor: string, amount: int, at: string, till: string, user: string}>}
     */
    public function doctorReference(): array
    {
        $period = ReportPeriod::today($this->shop());
        $consultations = app(ReportService::class)->consultations($period);
        $timezone = $this->timezone();

        return [
            'opd_bills' => $consultations['opd_bills'],
            'consultation_fees' => $consultations['net'],
            'paid_today' => array_values(CashMovement::query()
                ->with(['doctor', 'device', 'user'])
                ->where('type', CashMovementType::DoctorPayment->value)
                ->whereNull('voided_at')
                ->where('created_at', '>=', $period->startUtc())
                ->where('created_at', '<', $period->endUtc())
                ->orderBy('id')
                ->get()
                ->map(fn (CashMovement $movement): array => [
                    'doctor_id' => (int) $movement->doctor_id,
                    'doctor' => (string) $movement->doctor?->name,
                    'amount' => $movement->amount,
                    'at' => $movement->created_at->setTimezone($timezone)->format('H:i'),
                    'till' => (string) $movement->device?->code,
                    'user' => (string) $movement->user?->name,
                ])
                ->all()),
        ];
    }

    /**
     * A movement's slip for ReceiptLayout::cashMovement(), in shop time; a
     * reprint carries its REPRINT stamp.
     *
     * @return array<string, mixed>
     */
    public function slip(CashMovement $movement, ?AuditLog $reprint = null): array
    {
        $movement->loadMissing(['device', 'location', 'user', 'doctor', 'voider', 'shift.user']);
        $timezone = $this->timezone();
        $device = $movement->device;

        return [
            'number' => $movement->number,
            'type' => $movement->type->value,
            'heading' => $movement->type->heading(),
            'createdAt' => $movement->created_at->setTimezone($timezone)->format('Y-m-d H:i'),
            'till' => $device === null ? null : ['code' => (string) $device->code, 'name' => $device->name, 'location' => $movement->location?->name],
            'shiftOpenedAt' => (string) $movement->shift?->opened_at->setTimezone($timezone)->format('Y-m-d H:i'),
            'shiftCashier' => (string) $movement->shift?->user?->name,
            'doctor' => $movement->doctor?->name,
            'category' => $movement->category?->label(),
            'user' => (string) $movement->user?->name,
            'amount' => $movement->amount,
            'note' => $movement->note,
            'void' => $movement->voided_at === null ? null : [
                'voidedAt' => $movement->voided_at->setTimezone($timezone)->format('Y-m-d H:i'),
                'voidedBy' => (string) $movement->voider?->name,
                'reason' => (string) $movement->void_reason,
            ],
            'reprint' => app(ReceiptService::class)->reprintStamp($reprint),
        ];
    }

    /**
     * A movement as the till lists it.
     *
     * @return array{uuid: string, number: string, type: string, label: string, category: string|null, amount: int, doctor: string|null, note: string|null, user: string, at: string, voided: bool, void_reason: string|null}
     */
    public function present(CashMovement $movement): array
    {
        return [
            'uuid' => (string) $movement->uuid,
            'number' => $movement->number,
            'type' => $movement->type->value,
            'label' => $movement->type->label(),
            'category' => $movement->category?->label(),
            'amount' => $movement->amount,
            'doctor' => $movement->doctor?->name,
            'note' => $movement->note,
            'user' => (string) $movement->user?->name,
            'at' => $movement->created_at->setTimezone($this->timezone())->format('Y-m-d H:i'),
            'voided' => $movement->isVoided(),
            'void_reason' => $movement->void_reason,
        ];
    }

    public function timezone(): string
    {
        return (string) ($this->shop()->timezone ?: config('app.timezone'));
    }

    private function assertMayCreate(User $user, CashMovementType $type): void
    {
        if ($type === CashMovementType::DoctorPayment) {
            if (! $this->doctorPaymentsEnabled()) {
                throw new PosException('This shop does not pay doctors from the till.');
            }

            if (! $user->can('doctors.pay')) {
                throw new PosException('You are not allowed to pay doctors.');
            }

            return;
        }

        if (! $this->enabled()) {
            throw new PosException('This shop does not record cash paid in or out at the till.');
        }

        if (! $user->can('cash.movements')) {
            throw new PosException('You are not allowed to pay cash in or out.');
        }
    }

    private function assertMayRead(User $user, CashMovement $movement): void
    {
        if (! $this->mayRead($user, $movement)) {
            throw new PosException('You are not allowed to print this slip.');
        }
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

        return $shift ?? throw new PosException('Open a shift on this till first: cash is paid in or out of a shift\'s drawer.');
    }

    /** The next number, per shop. Only with the shop row locked. */
    private function nextNumber(): string
    {
        return 'CM-'.str_pad((string) (CashMovement::query()->count() + 1), 6, '0', STR_PAD_LEFT);
    }

    private function shop(): Shop
    {
        return $this->currentShop->getOrFail();
    }
}
