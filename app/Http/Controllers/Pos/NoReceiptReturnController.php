<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\CashMovementsRequest;
use App\Http\Requests\Pos\ReprintCashMovementRequest;
use App\Http\Requests\Pos\StoreNoReceiptReturnRequest;
use App\Models\AuditLog;
use App\Models\NoReceiptReturn;
use App\Models\Shift;
use App\Models\User;
use App\Services\NoReceiptReturnService;
use App\Services\ReceiptService;
use App\Support\CurrentShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Returns without a receipt at the till (pharmacy, docs/PHARMACY.md branch
 * 18): the owner refunds an item bought before the system went live, in
 * cash from the drawer; the stock goes to Quarantine or back on sale. The
 * slip prints at once; a reprint is logged and marked REPRINT.
 */
class NoReceiptReturnController extends Controller
{
    /** The returns without a receipt of the till's open shift, newest first. */
    public function index(CashMovementsRequest $request, NoReceiptReturnService $returns): JsonResponse
    {
        $request->validated();
        $till = $request->device() ?? abort(422);
        $shift = Shift::query()->where('device_id', $till->getKey())->whereNull('closed_at')->latest('opened_at')->first();
        $timezone = (string) (app(CurrentShop::class)->getOrFail()->timezone ?: config('app.timezone'));

        return response()->json([
            'returns' => $shift === null ? [] : NoReceiptReturn::query()->with('user')->where('shift_id', $shift->getKey())->orderByDesc('id')->get()
                ->map(fn (NoReceiptReturn $return): array => [
                    'uuid' => (string) $return->uuid,
                    'number' => $return->number,
                    'refund_total' => $return->refund_total,
                    'reason' => $return->reason,
                    'user' => (string) $return->user?->name,
                    'at' => $return->returned_at->setTimezone($timezone)->format('Y-m-d H:i'),
                ])->values(),
            'quarantine' => $returns->quarantine()?->name,
        ]);
    }

    public function store(StoreNoReceiptReturnRequest $request, NoReceiptReturnService $returns): JsonResponse
    {
        $request->validated();

        /** @var User $user */
        $user = $request->user();
        $return = $returns->create($request->device() ?? abort(422), $user, $request->lines(), (string) $request->validated('reason'), $request->validated('note'));

        return response()->json([
            'return' => ['uuid' => (string) $return->uuid, 'number' => $return->number, 'refund_total' => $return->refund_total],
            'slip_url' => route('pos.no-receipt-returns.slip', $return),
        ], 201);
    }

    public function reprint(ReprintCashMovementRequest $request, NoReceiptReturn $noReceiptReturn, NoReceiptReturnService $returns): JsonResponse
    {
        $request->validated();

        /** @var User $user */
        $user = $request->user();
        abort_unless($returns->mayRead($user, $noReceiptReturn), 403);
        $entry = $returns->recordReprint($noReceiptReturn, $user, $request->device());

        return response()->json([
            'slip_url' => route('pos.no-receipt-returns.reprinted', ['noReceiptReturn' => $noReceiptReturn, 'reprint' => $entry->getKey()]),
        ]);
    }

    /** The slip as first printed: never marked. */
    public function slip(Request $request, NoReceiptReturn $noReceiptReturn, NoReceiptReturnService $returns): Response
    {
        abort_unless($returns->mayRead($request->user(), $noReceiptReturn), 403);

        return self::slipPage($noReceiptReturn, $returns);
    }

    /** A logged reprint, marked REPRINT: only for the user who reprinted it. */
    public function reprinted(Request $request, NoReceiptReturn $noReceiptReturn, int $reprint, NoReceiptReturnService $returns): Response
    {
        $entry = $returns->findReprint($noReceiptReturn, $reprint);

        abort_if($entry === null || (int) $entry->user_id !== (int) $request->user()?->getKey(), 404);
        abort_unless($returns->mayRead($request->user(), $noReceiptReturn), 403);

        return self::slipPage($noReceiptReturn, $returns, $entry);
    }

    /** @internal also renders the admin's reprints */
    public static function slipPage(NoReceiptReturn $return, NoReceiptReturnService $returns, ?AuditLog $reprint = null): Response
    {
        $receipts = app(ReceiptService::class);

        return Inertia::render('pos/Receipt', [
            'title' => ($reprint === null ? 'Refund ' : 'Reprint refund ').$return->number,
            'shop' => $receipts->shop(),
            'sale' => null,
            'thermal' => $receipts->thermal('no_receipt', $returns->slip($return, $reprint)),
            'invoiceUrl' => null,
        ]);
    }
}
