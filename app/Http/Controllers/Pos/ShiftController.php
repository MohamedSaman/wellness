<?php

namespace App\Http\Controllers\Pos;

use App\Exceptions\PosException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\OpenShiftRequest;
use App\Http\Requests\Pos\ShiftCountRequest;
use App\Http\Requests\Pos\ShiftPatientsRequest;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Sale;
use App\Models\Shift;
use App\Services\PatientListService;
use App\Services\Receipts\ReceiptLayout;
use App\Services\ReceiptService;
use App\Services\ShiftService;
use App\Services\ShiftSummaryService;
use App\Support\CurrentShop;
use App\Support\PatientAge;
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
            'summary' => ShiftSummaryService::mayRead($request->user(), $shift) ? $shifts->summary($shift) : null,
        ]);
    }

    /**
     * The printable summary of a shift: its takings, payments and cash, for
     * the cashier whose shift it is (or a user with shifts.manage). Another
     * shop's shift is not found (tenant-scoped binding). This is the first
     * print, from the Close shift dialog: never marked. Reprints are logged
     * and marked (ShiftReprintController).
     */
    public function summary(Request $request, Shift $shift): View|Response
    {
        abort_unless(ShiftSummaryService::mayRead($request->user(), $shift), 403);

        return self::summaryPage($shift, $request->query('format') === 'a4' ? 'a4' : 'thermal', $request->boolean('print'));
    }

    /**
     * The summary page. A shop that prints thermal receipts (pharmacy) gets
     * it laid out for the receipt printer, on the receipt page
     * (ReceiptLayout::shiftSummary()): it prints like a receipt. The 'a4'
     * format, and every other shop, get the A4 page. A reprint is marked
     * REPRINT, with when and by whom.
     */
    public static function summaryPage(Shift $shift, string $format, bool $autoPrint, ?AuditLog $reprint = null): View|Response
    {
        $summaries = app(ShiftSummaryService::class);
        $receipts = app(ReceiptService::class);
        $shift->load(['device.location', 'user']);
        $timezone = $summaries->timezone();

        if ($receipts->usesThermalReceipt() && $format !== 'a4') {
            return Inertia::render('pos/Receipt', [
                'title' => ($reprint === null ? 'Shift summary ' : 'Shift summary reprint ').$shift->device?->code.' '.$shift->opened_at->setTimezone($timezone)->format('d/m/Y'),
                'shop' => $receipts->shop(),
                'sale' => null,
                'thermal' => $receipts->thermal('shift', $summaries->thermalPayload($shift, $reprint)),
                'invoiceUrl' => null,
            ]);
        }

        $stamp = $receipts->reprintStamp($reprint);

        return view('pos.shift-summary', [
            'shop' => $receipts->currentShop(),
            'shift' => $shift,
            'summary' => app(ShiftService::class)->summary($shift),
            'openedAt' => $shift->opened_at->setTimezone($timezone)->format('d/m/Y H:i'),
            'closedAt' => $shift->closed_at?->setTimezone($timezone)->format('d/m/Y H:i'),
            'autoPrint' => $autoPrint,
            'closeAfterPrint' => $reprint !== null,
            'reprint' => $stamp === null ? null : [
                'printedAt' => ReceiptLayout::date((string) $stamp['printedAt']),
                'printedBy' => $stamp['printedBy'],
            ],
        ]);
    }

    /**
     * Patients this shift (docs/PHARMACY.md, branch 16): the bills with a
     * patient rung up in the till's open shift, oldest first, with the time
     * in shop time, the patient, their age at the visit and the invoice (to
     * reprint). Only the open shift of the till asked about, at a location
     * the user may use (PosRequest): no shift id is taken, so no other
     * shift or till can be read. Read only. No open shift: no bills.
     */
    public function patients(ShiftPatientsRequest $request, PatientListService $patients, CurrentShop $currentShop): JsonResponse
    {
        $request->validated();

        $shift = $patients->openShift($request->device() ?? abort(422));

        if ($shift === null) {
            return response()->json(['shift' => null, 'bills' => [], 'without_patient' => 0]);
        }

        $timezone = (string) ($currentShop->getOrFail()->timezone ?: config('app.timezone'));

        return response()->json([
            'shift' => ['uuid' => $shift->uuid, 'opened_at' => $shift->opened_at->setTimezone($timezone)->format('Y-m-d H:i')],
            'bills' => $patients->shiftBills($shift)->map(fn (Sale $sale): array => [
                'uuid' => $sale->uuid,
                'invoice_no' => $sale->invoice_no,
                'sold_at' => $sale->sold_at->setTimezone($timezone)->format('Y-m-d H:i'),
                'patient' => (string) ($sale->patient->name ?? $sale->customer_name),
                'age' => PatientAge::format($sale->patient_age_months),
                'bill_type' => $sale->bill_type->value,
                'status' => $sale->status->value,
            ])->values(),
            'without_patient' => $patients->shiftBillsWithoutPatient($shift),
        ]);
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
            'summary' => ShiftSummaryService::mayRead($request->user(), $shift) ? $shifts->summary($shift) : null,
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
