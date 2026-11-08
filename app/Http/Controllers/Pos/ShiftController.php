<?php

namespace App\Http\Controllers\Pos;

use App\Enums\SaleStatus;
use App\Exceptions\PosException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\OpenShiftRequest;
use App\Http\Requests\Pos\ShiftCountRequest;
use App\Models\Device;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use App\Services\ReceiptService;
use App\Services\ShiftService;
use App\Support\CurrentShop;
use App\Support\ShiftPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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
     *
     * A shop that prints thermal receipts (pharmacy) gets it laid out for the
     * receipt printer, on the receipt page (ReceiptLayout::shiftSummary()):
     * it prints like a receipt. `?format=a4`, and every other shop, get the
     * A4 page.
     */
    public function summary(Request $request, Shift $shift, ShiftService $shifts, CurrentShop $currentShop, ReceiptService $receipts): View|Response
    {
        abort_unless($this->mayReadSummary($request->user(), $shift), 403);

        $shift->load(['device.location', 'user']);
        $shop = $currentShop->getOrFail();
        $timezone = (string) ($shop->timezone ?: config('app.timezone'));

        if ($receipts->usesThermalReceipt() && $request->query('format') !== 'a4') {
            return Inertia::render('pos/Receipt', [
                'title' => 'Shift summary '.$shift->device?->code.' '.$shift->opened_at->setTimezone($timezone)->format('d/m/Y'),
                'shop' => $receipts->shop(),
                'sale' => null,
                'thermal' => $receipts->thermal('shift', $this->thermalSummary($shift, $shifts->summary($shift), $timezone)),
                'invoiceUrl' => null,
            ]);
        }

        return view('pos.shift-summary', [
            'shop' => $shop,
            'shift' => $shift,
            'summary' => $shifts->summary($shift),
            'openedAt' => $shift->opened_at->setTimezone($timezone)->format('d/m/Y H:i'),
            'closedAt' => $shift->closed_at?->setTimezone($timezone)->format('d/m/Y H:i'),
            'autoPrint' => $request->boolean('print'),
        ]);
    }

    /**
     * The shift summary for the receipt printer: ShiftService::summary() as
     * it is, the shift's details in shop time, and two figures the A4 page
     * does not have, read from the shift's bills: the invoice range (first
     * and last bill rung up, voided ones included: their numbers were used)
     * and the discounts given on the bills that were not voided.
     *
     * @param  array<string, mixed>  $summary  ShiftService::summary()
     * @return array<string, mixed>
     */
    private function thermalSummary(Shift $shift, array $summary, string $timezone): array
    {
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
            'summary' => $summary,
        ];
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
