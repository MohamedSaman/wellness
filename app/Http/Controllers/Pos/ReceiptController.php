<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Services\ReceiptService;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Printable receipts. Money is sent in minor units plus the currency
 * settings. A pharmacy shop also gets `thermal`: the receipt laid out for its
 * paper (the lines the browser prints, and ESC/POS bytes); a retail shop's
 * sale receipt is the HTML receipt it always had.
 */
class ReceiptController extends Controller
{
    /** The sale's receipt, as printed at the till (80 mm or 58 mm). */
    public function __invoke(Sale $sale, ReceiptService $receipts): Response
    {
        Gate::authorize('view', $sale);

        return self::sale($receipts, $sale);
    }

    /** The refund slip of one return of the sale. */
    public function refund(Sale $sale, SaleReturn $saleReturn, ReceiptService $receipts): Response
    {
        Gate::authorize('view', $sale);
        abort_unless((int) $saleReturn->sale_id === (int) $sale->getKey(), 404);

        return self::refundSlip($receipts, $saleReturn);
    }

    /** The void slip of a voided sale. */
    public function void(Sale $sale, ReceiptService $receipts): Response
    {
        Gate::authorize('view', $sale);

        return self::voidSlip($receipts, $sale);
    }

    /** @internal also renders reprints (ReprintController) */
    public static function sale(ReceiptService $receipts, Sale $sale, ?AuditLog $reprint = null): Response
    {
        $payload = $receipts->sale($sale, $reprint);
        $thermal = $receipts->usesThermalReceipt();

        return Inertia::render('pos/Receipt', [
            // The retail HTML receipt's reprint is a "Duplicate receipt", as it always was.
            'title' => ($reprint === null ? 'Receipt ' : ($thermal ? 'Reprint ' : 'Duplicate receipt ')).$sale->invoice_no,
            'shop' => $receipts->shop(),
            'sale' => $payload,
            'thermal' => $thermal ? $receipts->thermal('sale', $payload) : null,
            'invoiceUrl' => route('sales.invoice', $sale),
        ]);
    }

    /** @internal */
    public static function refundSlip(ReceiptService $receipts, SaleReturn $return, ?AuditLog $reprint = null): Response
    {
        return Inertia::render('pos/Receipt', [
            'title' => ($reprint === null ? 'Refund ' : 'Reprint refund ').$return->return_no,
            'shop' => $receipts->shop(),
            'sale' => null,
            'thermal' => $receipts->thermal('refund', $receipts->refund($return, $reprint)),
            'invoiceUrl' => null,
        ]);
    }

    /** @internal */
    public static function voidSlip(ReceiptService $receipts, Sale $sale, ?AuditLog $reprint = null): Response
    {
        return Inertia::render('pos/Receipt', [
            'title' => ($reprint === null ? 'Void ' : 'Reprint void ').$sale->invoice_no,
            'shop' => $receipts->shop(),
            'sale' => null,
            'thermal' => $receipts->thermal('void', $receipts->voidSlip($sale, $reprint)),
            'invoiceUrl' => null,
        ]);
    }
}
