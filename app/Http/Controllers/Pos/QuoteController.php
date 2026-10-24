<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\QuoteRequest;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;

class QuoteController extends Controller
{
    /**
     * Totals for the cart, from the same maths as checkout, and with the till
     * each batch line's batches (first expiry first out). Prices are never
     * read from the request (a typed service price aside); costs are never sent.
     */
    public function __invoke(QuoteRequest $request, CheckoutService $checkout): JsonResponse
    {
        $request->validated();
        $user = $request->user();

        $quote = $checkout->quote(
            $request->cartLines(),
            $request->saleDiscount(),
            $user,
            $request->filled('device') ? $request->device() : null,
            $request->billType(),
            $request->medicinesAmount(),
        );

        $payments = $request->paymentInputs();

        return response()->json([
            'totals' => collect($quote)->except('lines')->all(),
            'lines' => $quote['lines'],
            'payment' => $payments === [] ? null : $checkout->previewPayments($payments, $quote['grand_total']),
        ]);
    }
}
