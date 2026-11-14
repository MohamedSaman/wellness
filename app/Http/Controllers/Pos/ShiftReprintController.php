<?php

namespace App\Http\Controllers\Pos;

use App\Exceptions\PosException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\ClosedShiftsRequest;
use App\Http\Requests\Pos\ReprintShiftSummaryRequest;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftSummaryService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Reprints of a closed shift's summary (pharmacy, docs/PHARMACY.md §9,
 * branch 17). The cashier forgot to print it when closing, or the copy
 * was lost: the till lists its shifts closed in the last 7 days, and a
 * reprint is logged before its page opens, marked REPRINT, as a sale's is.
 */
class ShiftReprintController extends Controller
{
    /** This till's shifts closed in the last 7 days that the user may print. */
    public function index(ClosedShiftsRequest $request, ShiftSummaryService $summaries): JsonResponse
    {
        $request->validated();

        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'shifts' => $summaries->closedShifts($request->device() ?? abort(422), $user),
            'days' => ShiftSummaryService::REPRINT_DAYS,
        ]);
    }

    /**
     * Reprint from the till: the shift's own cashier, or a user with
     * shifts.manage (the rule of the summary page). The reprint is logged
     * first; the page it returns prints itself.
     */
    public function store(ReprintShiftSummaryRequest $request, Shift $shift, ShiftSummaryService $summaries): JsonResponse
    {
        $request->validated();

        /** @var User $user */
        $user = $request->user();
        abort_unless(ShiftSummaryService::mayRead($user, $shift), 403);

        if ($shift->isOpen()) {
            throw new PosException('This shift is still open: print its summary when it is closed.');
        }

        $reprint = $summaries->recordReprint($shift, $user, $request->device() ?? abort(422), $request->printFormat());

        return response()->json([
            'summary_url' => route('pos.shift.reprinted', ['shift' => $shift, 'reprint' => $reprint->getKey()]),
        ]);
    }

    /**
     * The reprint of a logged entry, in the format it was reprinted in,
     * marked REPRINT. Only the user who reprinted it opens it, while they
     * may still read the shift.
     */
    public function show(Request $request, Shift $shift, int $reprint, ShiftSummaryService $summaries): View|Response
    {
        $entry = $summaries->findReprint($shift, $reprint);

        abort_if($entry === null || (int) $entry->user_id !== (int) $request->user()?->getKey(), 404);
        abort_unless(ShiftSummaryService::mayRead($request->user(), $shift), 403);

        return ShiftController::summaryPage($shift, ShiftSummaryService::reprintedFormat($entry), $request->boolean('print'), $entry);
    }
}
