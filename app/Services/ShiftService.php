<?php

namespace App\Services;

use App\Enums\CashMovementType;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Exceptions\PosException;
use App\Models\CashMovement;
use App\Models\Device;
use App\Models\NoReceiptReturn;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Shift;
use App\Models\User;
use App\Support\CurrentShop;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cash drawer control: open with a float, close with a count, and see the
 * difference. This is what stops end-of-day cash disputes.
 */
class ShiftService
{
    public function __construct(private readonly CurrentShop $currentShop) {}

    public function open(Device $device, User $user, int $openingCash): Shift
    {
        if (! $user->can('shifts.open_close')) {
            throw new PosException('You are not allowed to open a shift.');
        }

        return DB::transaction(function () use ($device, $user, $openingCash): Shift {
            Device::query()->whereKey($device->getKey())->lockForUpdate()->firstOrFail();

            $alreadyOpen = Shift::query()
                ->where('device_id', $device->getKey())
                ->whereNull('closed_at')
                ->exists();

            if ($alreadyOpen) {
                throw new PosException('A shift is already open on this till. Close it first.');
            }

            return Shift::query()->create([
                'device_id' => $device->getKey(),
                'user_id' => $user->getKey(),
                'opened_at' => now(),
                'opening_cash' => max(0, $openingCash),
            ]);
        });
    }

    /** Cash that should be in the drawer right now for this shift. */
    public function expectedCash(Shift $shift): int
    {
        $countedSales = Sale::query()
            ->where('shift_id', $shift->getKey())
            ->where('status', '!=', SaleStatus::Voided->value);

        $cashIn = (int) Payment::query()
            ->whereIn('sale_id', (clone $countedSales)->select('id'))
            ->where('method', PaymentMethod::Cash->value)
            ->sum('amount');

        $changeGiven = (int) (clone $countedSales)->sum('change_due');

        $cashRefunds = (int) SaleReturn::query()
            ->where('shift_id', $shift->getKey())
            ->where('refund_method', PaymentMethod::Cash->value)
            ->sum('refund_total');

        $drawer = $this->drawer($shift);

        return $shift->opening_cash + $cashIn - $changeGiven - $cashRefunds
            - $drawer['no_receipt_cash'] - $drawer['paid_out'] - $drawer['doctor_payments'] + $drawer['paid_in'];
    }

    /**
     * What else moved the shift's drawer (docs/PHARMACY.md, branch 18): cash
     * paid in and out and paid to doctors (voided ones apart), and refunds
     * of returns without a receipt (cash only). Zero where the modules are
     * off: nothing records them there.
     *
     * @return array{paid_in: int, paid_out: int, doctor_payments: int, no_receipt: int, no_receipt_count: int, no_receipt_cash: int}
     */
    private function drawer(Shift $shift): array
    {
        $movements = CashMovement::query()->toBase()
            ->where('shift_id', $shift->getKey())
            ->whereNull('voided_at')
            ->groupBy('type')
            ->selectRaw('type, SUM(amount) as amount')
            ->pluck('amount', 'type');
        $noReceipt = NoReceiptReturn::query()->toBase()
            ->where('shift_id', $shift->getKey())
            ->selectRaw('COUNT(*) as returns, COALESCE(SUM(refund_total), 0) as total')
            ->first();
        $noReceiptCash = (int) NoReceiptReturn::query()
            ->where('shift_id', $shift->getKey())
            ->where('refund_method', PaymentMethod::Cash->value)
            ->sum('refund_total');

        return [
            'paid_in' => (int) ($movements[CashMovementType::PaidIn->value] ?? 0),
            'paid_out' => (int) ($movements[CashMovementType::PaidOut->value] ?? 0),
            'doctor_payments' => (int) ($movements[CashMovementType::DoctorPayment->value] ?? 0),
            'no_receipt' => (int) ($noReceipt->total ?? 0),
            'no_receipt_count' => (int) ($noReceipt->returns ?? 0),
            'no_receipt_cash' => $noReceiptCash,
        ];
    }

    /**
     * One shift's takings (docs/PHARMACY.md §9, branch 11): the bills rung
     * up in it (voided ones apart), sales, refunds given in it, voids, net,
     * what each payment method took (cash net of change) and the drawer:
     * float + cash sales - cash refunds - paid out - doctor payments + paid
     * in = expected (branch 18: the movements, and returns without a
     * receipt as refunds, where those modules are on), the count and the
     * difference (closed shifts as recorded at closing, open ones now).
     * What a cashier sees of their own shift; reports use it per shift.
     *
     * @return array{bills: int, gross: int, refunds: int, refunds_count: int, no_receipt_refunds?: int, no_receipt_count?: int, voids: int, voids_total: int, net: int, payments: list<array{method: string, label: string, amount: int}>, cash: array{opening: int, sales: int, refunds: int, paid_in?: int, paid_out?: int, doctor_payments?: int, expected: int, counted: int|null, difference: int|null}, movements?: list<array{number: string, type: string, label: string, category: string|null, amount: int, note: string|null, user: string, at: string}>, voided_movements?: int, doctor_payments?: list<array{doctor: string, payments: int, amount: int}>}
     */
    public function summary(Shift $shift): array
    {
        $sales = Sale::query()->where('shift_id', $shift->getKey());
        $counted = (clone $sales)->where('status', '!=', SaleStatus::Voided->value);

        $bills = (clone $counted)->selectRaw('COUNT(*) as bills, COALESCE(SUM(grand_total), 0) as gross, COALESCE(SUM(change_due), 0) as change_given')->toBase()->first();
        $voids = (clone $sales)->where('status', SaleStatus::Voided->value)->selectRaw('COUNT(*) as voids, COALESCE(SUM(grand_total), 0) as voids_total')->toBase()->first();
        $returns = SaleReturn::query()->where('shift_id', $shift->getKey());
        $refunds = (clone $returns)->selectRaw('COUNT(*) as refunds_count, COALESCE(SUM(refund_total), 0) as refunds')->toBase()->first();
        $cashRefunds = (int) (clone $returns)->where('refund_method', PaymentMethod::Cash->value)->sum('refund_total');

        $taken = Payment::query()->toBase()
            ->whereIn('sale_id', (clone $counted)->select('id'))
            ->selectRaw('method, SUM(amount) as amount')
            ->groupBy('method')
            ->pluck('amount', 'method');

        $changeGiven = (int) ($bills->change_given ?? 0);
        $payments = [];

        foreach (PaymentMethod::cases() as $method) {
            if (! isset($taken[$method->value])) {
                continue;
            }

            $payments[] = [
                'method' => $method->value,
                'label' => ucwords(str_replace('_', ' ', $method->value)),
                'amount' => (int) $taken[$method->value] - ($method === PaymentMethod::Cash ? $changeGiven : 0),
            ];
        }

        $cashSales = (int) ($taken[PaymentMethod::Cash->value] ?? 0) - $changeGiven;
        $drawer = $this->drawer($shift);
        // Returns without a receipt are refunds (decided for branch 18): in the refunds and in the cash refunds.
        $cashRefunds += $drawer['no_receipt_cash'];
        $expected = $shift->isOpen()
            ? $shift->opening_cash + $cashSales - $cashRefunds - $drawer['paid_out'] - $drawer['doctor_payments'] + $drawer['paid_in']
            : (int) $shift->expected_cash;
        $gross = (int) ($bills->gross ?? 0);
        $refundTotal = (int) ($refunds->refunds ?? 0) + $drawer['no_receipt'];
        $shop = $this->currentShop->getOrFail();
        $movementsOn = $shop->hasModule('cash_movements');
        $pharmacy = $shop->hasModule('pharmacy');

        return [
            'bills' => (int) ($bills->bills ?? 0),
            'gross' => $gross,
            'refunds' => $refundTotal,
            'refunds_count' => (int) ($refunds->refunds_count ?? 0) + $drawer['no_receipt_count'],
            // Of which without a receipt (pharmacy, branch 18).
            ...($pharmacy ? ['no_receipt_refunds' => $drawer['no_receipt'], 'no_receipt_count' => $drawer['no_receipt_count']] : []),
            'voids' => (int) ($voids->voids ?? 0),
            'voids_total' => (int) ($voids->voids_total ?? 0),
            'net' => $gross - $refundTotal,
            'payments' => $payments,
            'cash' => [
                'opening' => $shift->opening_cash,
                'sales' => $cashSales,
                'refunds' => $cashRefunds,
                ...($movementsOn ? ['paid_in' => $drawer['paid_in'], 'paid_out' => $drawer['paid_out'], 'doctor_payments' => $drawer['doctor_payments']] : []),
                'expected' => $expected,
                'counted' => $shift->counted_cash,
                'difference' => $shift->difference,
            ],
            // Each paid out and paid in, and each doctor's payments (branch 18).
            ...($movementsOn ? $this->movementLists($shift, $pharmacy) : []),
        ];
    }

    /**
     * The shift's paid out and paid in (voided ones listed apart, for
     * control) and, in a pharmacy, what each doctor was paid: one row per
     * doctor, in the order first paid.
     *
     * @return array{movements: list<array{number: string, type: string, label: string, category: string|null, amount: int, note: string|null, user: string, at: string}>, voided_movements: int, doctor_payments: list<array{doctor: string, payments: int, amount: int}>}
     */
    private function movementLists(Shift $shift, bool $pharmacy): array
    {
        $timezone = (string) ($this->currentShop->getOrFail()->timezone ?: config('app.timezone'));
        $all = CashMovement::query()->with(['user', 'doctor'])->where('shift_id', $shift->getKey())->orderBy('id')->get();
        $counted = $all->filter(fn (CashMovement $movement): bool => ! $movement->isVoided());

        return [
            'movements' => array_values($counted->where('type', '!=', CashMovementType::DoctorPayment)->map(fn (CashMovement $movement): array => [
                'number' => $movement->number,
                'type' => $movement->type->value,
                'label' => $movement->type->label(),
                'category' => $movement->category?->label(),
                'amount' => $movement->amount,
                'note' => $movement->note,
                'user' => (string) $movement->user?->name,
                'at' => $movement->created_at->setTimezone($timezone)->format('Y-m-d H:i'),
            ])->all()),
            'voided_movements' => $all->count() - $counted->count(),
            'doctor_payments' => ! $pharmacy ? [] : array_values($counted->where('type', CashMovementType::DoctorPayment)
                ->groupBy('doctor_id')
                ->map(fn (Collection $payments): array => [
                    'doctor' => (string) $payments->first()?->doctor?->name,
                    'payments' => $payments->count(),
                    'amount' => (int) $payments->sum('amount'),
                ])->all()),
        ];
    }

    public function close(Shift $shift, User $user, int $countedCash, ?string $notes = null): Shift
    {
        $ownShift = (int) $shift->user_id === (int) $user->getKey();

        if (! ($ownShift && $user->can('shifts.open_close')) && ! $user->can('shifts.manage')) {
            throw new PosException('You are not allowed to close this shift.');
        }

        return DB::transaction(function () use ($shift, $countedCash, $notes): Shift {
            /** @var Shift $shift */
            $shift = Shift::query()->whereKey($shift->getKey())->lockForUpdate()->firstOrFail();

            if (! $shift->isOpen()) {
                throw new PosException('This shift is already closed.');
            }

            $expected = $this->expectedCash($shift);

            $shift->update([
                'closed_at' => now(),
                'expected_cash' => $expected,
                'counted_cash' => $countedCash,
                'difference' => $countedCash - $expected,
                'notes' => $notes,
            ]);

            return $shift;
        });
    }
}
