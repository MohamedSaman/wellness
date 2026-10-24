<?php

use App\Http\Controllers\StockPrintController;
use Illuminate\Support\Facades\Route;

/*
| Printable stock-control documents (docs/PHARMACY.md §9, branch 10): the
| write-off note (pharmacy), the stock request with its pick list
| (multi_location) and the stock count sheet. Each opens from its page; the
| document's policy decides who may print it, and another shop's document
| is not found.
*/
Route::middleware('auth')->prefix('stock')->name('stock.print.')->group(function () {
    Route::get('write-offs/{writeOff}/print', [StockPrintController::class, 'writeOff'])->middleware('module:pharmacy')->name('write-off');
    Route::get('requests/{stockRequest}/print', [StockPrintController::class, 'request'])->middleware('module:multi_location')->name('request');
    Route::get('counts/{stockCount}/print', [StockPrintController::class, 'count'])->name('count');
});
