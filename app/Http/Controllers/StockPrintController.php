<?php

namespace App\Http\Controllers;

use App\Models\StockCount;
use App\Models\StockRequest;
use App\Models\StockWriteOff;
use App\Services\StockCountService;
use App\Services\StockRequestService;
use App\Support\CurrentShop;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Printable stock-control documents, plain HTML for the browser's print
 * dialog (?print=1 opens it): the write-off note, the stock request with
 * its pick list, the stock count sheet. Route model binding is
 * tenant-scoped, so another shop's document is a 404; the document's policy
 * decides who may see it. Costs only for cost.view.
 */
class StockPrintController extends Controller
{
    public function writeOff(Request $request, StockWriteOff $writeOff, CurrentShop $currentShop): View
    {
        $this->authorizeView($request, $writeOff);

        return view('stock.print.write-off', [
            'shop' => $currentShop->getOrFail(),
            'writeOff' => $writeOff->load(['items.variant.product', 'location', 'user']),
            'showCost' => $request->user()?->can('cost.view') ?? false,
            'autoPrint' => $request->boolean('print'),
        ]);
    }

    /** The request, and for General Pharmacy a pick list: what is still to send and the batches FEFO would take. */
    public function request(Request $request, StockRequest $stockRequest, CurrentShop $currentShop, StockRequestService $requests): View
    {
        $this->authorizeView($request, $stockRequest);

        return view('stock.print.request', [
            'shop' => $currentShop->getOrFail(),
            'stockRequest' => $stockRequest->load(['items.variant.product', 'fromLocation', 'toLocation', 'requester', 'transfers']),
            'picks' => $requests->pickList($stockRequest),
            'autoPrint' => $request->boolean('print'),
        ]);
    }

    /** The count sheet: lines by shelf, with what the system holds unless the count is blind. */
    public function count(Request $request, StockCount $stockCount, CurrentShop $currentShop, StockCountService $counts): View
    {
        $this->authorizeView($request, $stockCount);

        return view('stock.print.count-sheet', [
            'shop' => $currentShop->getOrFail(),
            'count' => $stockCount->load(['location', 'starter']),
            'lines' => $counts->sheet($stockCount),
            'autoPrint' => $request->boolean('print'),
        ]);
    }

    private function authorizeView(Request $request, Model $document): void
    {
        abort_unless($request->user()?->can('view', $document) ?? false, 403);
    }
}
