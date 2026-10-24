<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Services\ReceiptService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The A4 invoice of a sale (docs/PHARMACY.md §9, branch 9), for a patient
 * who asks for a proper bill: the receipt's own payload (the sale as sold,
 * never the product as it is now) on a page, with the shop's receipt
 * choices (batch, expiry, prescriber, the OPD bill's medicines) applied as
 * on the thermal receipt. ?print=1 opens the print dialog.
 */
class SaleInvoiceController extends Controller
{
    public function __invoke(Request $request, Sale $sale, ReceiptService $receipts): View
    {
        Gate::authorize('view', $sale);

        return view('sales.invoice', [
            'shop' => $receipts->currentShop(),
            'receiptShop' => $receipts->shop(),
            'sale' => $receipts->sale($sale),
            'printedBy' => $request->user()?->name,
            'autoPrint' => $request->boolean('print'),
        ]);
    }
}
