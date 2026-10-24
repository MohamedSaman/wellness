<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\CheckoutRequest;
use App\Services\CheckoutService;
use App\Services\Data\CheckoutData;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    public function __invoke(CheckoutRequest $request, CheckoutService $checkout): JsonResponse
    {
        $input = $request->validated();

        // Only variant ids and quantities come from the till; CheckoutService prices everything
        // (a typed service price and the OPD Medicines amount are checked there).
        $sale = $checkout->checkout(new CheckoutData(
            device: $request->device() ?? abort(422),
            cashier: $request->user(),
            lines: $request->cartLines(),
            payments: $request->paymentInputs(),
            saleDiscount: $request->saleDiscount(),
            uuid: $input['uuid'],
            customerName: $input['customer_name'] ?? null,
            customerPhone: $input['customer_phone'] ?? null,
            billType: $request->billType(),
            opdMedicinesAmount: $request->medicinesAmount(),
        ));

        return response()->json([
            'sale' => [
                'uuid' => $sale->uuid,
                'invoice_no' => $sale->invoice_no,
                'grand_total' => $sale->grand_total,
                'change_due' => $sale->change_due,
                'receipt_url' => route('pos.receipt', $sale),
            ],
        ]);
    }
}
