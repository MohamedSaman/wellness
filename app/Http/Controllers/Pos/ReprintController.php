<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\ReprintReceiptRequest;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\User;
use App\Services\ReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class ReprintController extends Controller
{
    /**
     * Reprint from the checkout screen. Any sale of the cashier's shop may be
     * reprinted (other shops' sales are not found). The reprint is logged
     * before the receipt page opens, so every duplicate is accounted for.
     */
    public function store(ReprintReceiptRequest $request, Sale $sale, ReceiptService $receipts): JsonResponse
    {
        $request->validated();

        /** @var User $user */
        $user = $request->user();
        $reprint = $receipts->recordReprint($sale, $user, $request->device() ?? abort(422));

        return response()->json([
            'receipt_url' => route('pos.receipt.reprinted', ['sale' => $sale, 'reprint' => $reprint->getKey()]),
        ]);
    }

    /**
     * The duplicate of a logged reprint: the sale's receipt, a refund slip or
     * the void slip, marked REPRINT. Only the user who reprinted it can open it.
     */
    public function show(Request $request, Sale $sale, int $reprint, ReceiptService $receipts): Response
    {
        $entry = $receipts->findReprint($sale, $reprint);

        abort_if($entry === null || (int) $entry->user_id !== (int) $request->user()?->getKey(), 404);

        return match (ReceiptService::reprintedDocument($entry)) {
            'refund' => ReceiptController::refundSlip(
                $receipts,
                SaleReturn::query()->where('sale_id', $sale->getKey())->findOrFail((int) ($entry->new_values['sale_return_id'] ?? 0)),
                $entry,
            ),
            'void' => ReceiptController::voidSlip($receipts, $sale, $entry),
            default => ReceiptController::sale($receipts, $sale, $entry),
        };
    }
}
