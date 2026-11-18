<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\RecentSalesRequest;
use App\Models\Sale;
use App\Services\ReceiptService;
use App\Support\CurrentShop;
use Illuminate\Http\JsonResponse;

class RecentSalesController extends Controller
{
    /** This till's sales from the open shift and today, for reprinting. Only the current shop's sales are visible. */
    public function __invoke(RecentSalesRequest $request, ReceiptService $receipts, CurrentShop $currentShop): JsonResponse
    {
        $input = $request->validated();
        $timezone = $currentShop->getOrFail()->timezone;
        $pharmacy = $currentShop->getOrFail()->hasModule('pharmacy');

        $sales = $receipts->recentSales($request->device() ?? abort(422), $input['q'] ?? null);

        return response()->json([
            'sales' => $sales->map(fn (Sale $sale): array => [
                'uuid' => $sale->uuid,
                'invoice_no' => $sale->invoice_no,
                'sold_at' => $sale->sold_at->timezone($timezone)->format('Y-m-d H:i'),
                'cashier' => $sale->cashier->name,
                'grand_total' => $sale->grand_total,
                'status' => $sale->status->value,
                // The new bill of an exchange (pharmacy, branch 19): its reprint is the EXCHANGE receipt.
                ...($pharmacy ? ['exchange' => $sale->exchange === null ? null : ['uuid' => (string) $sale->exchange->uuid, 'number' => $sale->exchange->number]] : []),
            ])->values(),
        ]);
    }
}
