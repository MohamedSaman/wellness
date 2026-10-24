<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Exceptions\PosException;
use App\Models\Device;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cash drawer control: open with a float, close with a count, and see the
 * difference. This is what stops end-of-day cash disputes.
 */
class ShiftService
{
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

        return $shift->opening_cash + $cashIn - $changeGiven - $cashRefunds;
    }

    /**
     * One shift's takings (docs/PHARMACY.md §9, branch 11): the bills rung
     * up in it (voided ones apart), sales, refunds given in it, voids, net,
     * what each payment method took (cash net of change) and the drawer:
     * float + cash sales - cash refunds = expected, the count and the
     * difference (closed shifts as recorded at closing, open ones now).
     * What a cashier sees of their own shift; reports use it per shift.
     *
     * @return array{bills: int, gross: int, refunds: int, refunds_count: int, voids: int, voids_total: int, net: int, payments: list<array{method: string, label: string, amount: int}>, cash: array{opening: int, sales: int, refunds: int, expected: int, counted: int|null, difference: int|null}}
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
        $expected = $shift->isOpen() ? $shift->opening_cash + $cashSales - $cashRefunds : (int) $shift->expected_cash;
        $gross = (int) ($bills->gross ?? 0);
        $refundTotal = (int) ($refunds->refunds ?? 0);

        return [
            'bills' => (int) ($bills->bills ?? 0),
            'gross' => $gross,
            'refunds' => $refundTotal,
            'refunds_count' => (int) ($refunds->refunds_count ?? 0),
            'voids' => (int) ($voids->voids ?? 0),
            'voids_total' => (int) ($voids->voids_total ?? 0),
            'net' => $gross - $refundTotal,
            'payments' => $payments,
            'cash' => [
                'opening' => $shift->opening_cash,
                'sales' => $cashSales,
                'refunds' => $cashRefunds,
                'expected' => $expected,
                'counted' => $shift->counted_cash,
                'difference' => $shift->difference,
            ],
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
