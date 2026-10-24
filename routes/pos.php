<?php

use App\Http\Controllers\Pos\CheckoutController;
use App\Http\Controllers\Pos\ItemSearchController;
use App\Http\Controllers\Pos\PosController;
use App\Http\Controllers\Pos\QuoteController;
use App\Http\Controllers\Pos\ReceiptController;
use App\Http\Controllers\Pos\RecentSalesController;
use App\Http\Controllers\Pos\ReprintController;
use App\Http\Controllers\Pos\ShiftController;
use App\Http\Controllers\Pos\StockRequestController;
use App\Http\Controllers\Pos\TillController;
use App\Http\Controllers\Pos\VehicleController;
use App\Http\Controllers\SaleInvoiceController;
use Illuminate\Support\Facades\Route;

/*
| The checkout screen and its JSON endpoints. Every route needs a signed-in,
| active user with pos.checkout; each endpoint also checks that the till
| belongs to the user's shop.
*/
Route::middleware(['auth', 'can:pos.checkout'])->prefix('pos')->name('pos.')->group(function () {
    Route::get('/', [PosController::class, 'index'])->name('index');

    Route::post('till', [TillController::class, 'store'])->name('till.store');
    Route::delete('till', [TillController::class, 'destroy'])->name('till.destroy');
    Route::post('till/register', [TillController::class, 'register'])->middleware('module:offline_sync')->name('till.register');

    Route::post('shift/open', [ShiftController::class, 'open'])->name('shift.open');
    Route::get('shift/expected', [ShiftController::class, 'expected'])->name('shift.expected');
    Route::post('shift/close', [ShiftController::class, 'close'])->name('shift.close');
    Route::get('shifts/{shift}/summary', [ShiftController::class, 'summary'])->name('shift.summary');

    Route::get('search', ItemSearchController::class)->middleware('throttle:pos-search')->name('search');
    Route::get('vehicles', VehicleController::class)->middleware('module:vehicle_fitment')->name('vehicles');

    Route::post('quote', QuoteController::class)->name('quote');
    Route::post('checkout', CheckoutController::class)->name('checkout');

    Route::get('receipts/{sale}', ReceiptController::class)->name('receipt');

    Route::get('recent-sales', RecentSalesController::class)->name('recent-sales');
    Route::post('receipts/{sale}/reprint', [ReprintController::class, 'store'])->name('receipt.reprint');

    // Stock requests from the till (multi_location): the OPD asks General Pharmacy for stock.
    Route::middleware(['can:stock.request', 'module:multi_location'])->prefix('stock-requests')->name('stock-requests.')->group(function () {
        Route::get('/', [StockRequestController::class, 'index'])->name('index');
        Route::get('items', [StockRequestController::class, 'items'])->middleware('throttle:pos-search')->name('items');
        Route::get('suggest', [StockRequestController::class, 'suggest'])->name('suggest');
        Route::post('/', [StockRequestController::class, 'store'])->name('store');
        Route::post('{stockRequest}/cancel', [StockRequestController::class, 'cancel'])->name('cancel');
    });
});

/*
| The duplicate receipt of a logged reprint. Reprints are made from the
| checkout screen (pos.checkout) or the admin panel (sales.view_all), so this
| page only needs a signed-in user: it opens just for whoever reprinted it.
| Refund and void slips are printed from the admin panel's sale page; the
| sale's policy decides who may open them (docs/PHARMACY.md §9, branch 9).
*/
Route::middleware('auth')->prefix('pos')->name('pos.')->group(function () {
    Route::get('receipts/{sale}/reprints/{reprint}', [ReprintController::class, 'show'])->whereNumber('reprint')->name('receipt.reprinted');
    Route::get('receipts/{sale}/refunds/{saleReturn}', [ReceiptController::class, 'refund'])->name('receipt.refund');
    Route::get('receipts/{sale}/void', [ReceiptController::class, 'void'])->name('receipt.void');
});

/*
| The A4 invoice of a sale, for a patient or customer who asks for a proper
| bill: from the receipt page or the admin sale page, for whoever may see
| the sale.
*/
Route::middleware('auth')->get('sales/{sale}/invoice', SaleInvoiceController::class)->name('sales.invoice');
