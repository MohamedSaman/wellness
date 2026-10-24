<?php

namespace App\Http\Controllers\Pos;

use App\Exceptions\PosException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\OpenShiftRequest;
use App\Http\Requests\Pos\ShiftCountRequest;
use App\Models\Device;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftService;
use App\Support\CurrentShop;
use App\Support\ShiftPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    public function open(OpenShiftRequest $request, ShiftService $shifts): JsonResponse
    {
        $request->validated();

        $shift = $shifts->open($request->device() ?? abort(422), $request->user(), $request->openingCash());

        return response()->json(['shift' => ShiftPresenter::present($shift->load('user'))], 201);
    }

    /**
     * What should be in the drawer, (given a count) the difference, and the
     * shift's takings so far (ShiftService::summary(): what a cashier may
     * see of their own shift, docs/PHARMACY.md §9, branch 11).
     */
    public function expected(ShiftCountRequest $request, ShiftService $shifts): JsonResponse
    {
        $request->validated();

        $shift = $this->openShift($request->device() ?? abort(422));
        $expected = $shifts->expectedCash($shift);
        $counted = $request->countedCash();

        return response()->json([
            'expected_cash' => $expected,
            'counted_cash' => $counted,
            'difference' => $counted === null ? null : $counted - $expected,
            'summary' => $this->mayReadSummary($request->user(), $shift) ? $shifts->summary($shift) : null,
        ]);
    }

    /**
     * The printable summary of a shift: its takings, payments and cash, for
     * the cashier whose shift it is (or a user with shifts.manage). Another
     * shop's shift is not found (tenant-scoped binding).
     */
    public function summary(Request $request, Shift $shift, ShiftService $shifts, CurrentShop $currentShop): View
    {
        abort_unless($this->mayReadSummary($request->user(), $shift), 403);

        $shift->load(['device.location', 'user']);
        $shop = $currentShop->getOrFail();
        $timezone = (string) ($shop->timezone ?: config('app.timezone'));

        return view('pos.shift-summary', [
            'shop' => $shop,
            'shift' => $shift,
            'summary' => $shifts->summary($shift),
            'openedAt' => $shift->opened_at->setTimezone($timezone)->format('d/m/Y H:i'),
            'closedAt' => $shift->closed_at?->setTimezone($timezone)->format('d/m/Y H:i'),
            'autoPrint' => $request->boolean('print'),
        ]);
    }

    /** A cashier reads only their own shift; shifts.manage reads any. */
    private function mayReadSummary(?User $user, Shift $shift): bool
    {
        return $user !== null && ((int) $shift->user_id === (int) $user->getKey() || $user->can('shifts.manage'));
    }

    public function close(ShiftCountRequest $request, ShiftService $shifts): JsonResponse
    {
        $request->validated();

        $shift = $shifts->close(
            $this->openShift($request->device() ?? abort(422)),
            $request->user(),
            (int) $request->countedCash(),
            $request->validated('notes'),
        );

        return response()->json([
            'closed' => [
                'uuid' => $shift->uuid,
                'expected_cash' => $shift->expected_cash,
                'counted_cash' => $shift->counted_cash,
                'difference' => $shift->difference,
            ],
            'summary' => $this->mayReadSummary($request->user(), $shift) ? $shifts->summary($shift) : null,
        ]);
    }

    private function openShift(Device $device): Shift
    {
        return Shift::query()
            ->where('device_id', $device->getKey())
            ->whereNull('closed_at')
            ->latest('opened_at')
            ->first() ?? throw new PosException('There is no open shift on this till.');
    }
}
