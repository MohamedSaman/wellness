<?php

namespace App\Http\Controllers\Pos;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\QuoteRequest;
use App\Services\CheckoutService;
use App\Services\Data\PaymentInput;
use App\Support\CurrentShop;
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
        $credit = (int) $request->validated('exchange_credit');
        $exchange = $credit > 0 && $user?->can('pos.exchange') && app(CurrentShop::class)->getOrFail()->hasModule('pharmacy');

        if (! $exchange) {
            return response()->json([
                'totals' => collect($quote)->except('lines')->all(),
                'lines' => $quote['lines'],
                'payment' => $payments === [] ? null : $checkout->previewPayments($payments, $quote['grand_total']),
            ]);
        }

        // An exchange (branch 19): the credit pays the new items up to their total, as ExchangeService records it.
        $applied = min($credit, (int) $quote['grand_total']);

        return response()->json([
            'totals' => collect($quote)->except('lines')->all(),
            'lines' => $quote['lines'],
            'payment' => $checkout->previewPayments([
                ...($applied > 0 ? [new PaymentInput(PaymentMethod::Exchange, $applied)] : []),
                ...$payments,
            ], $quote['grand_total'], exchangeCredit: true),
            'exchange' => [
                'credit' => $credit,
                'applied' => $applied,
                'patient_pays' => (int) $quote['grand_total'] - $applied,
                'refund' => $credit - $applied,
            ],
        ]);
    }
}
