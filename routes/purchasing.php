<?php

use App\Http\Controllers\PurchasingPrintController;
use Illuminate\Support\Facades\Route;

/*
| Printable purchasing documents (purchasing module): the purchase order for
| the supplier, the goods received note, the supplier return note, and the
| supplier statement (payables.view or purchasing.manage). Each
| opens from its page in the admin panel; the policy of the document decides
| who may print it, and another shop's document is not found.
*/
Route::middleware(['auth', 'module:purchasing'])->prefix('purchasing')->name('purchasing.print.')->group(function () {
    Route::get('orders/{order}/print', [PurchasingPrintController::class, 'order'])->name('order');
    Route::get('goods-received/{receipt}/print', [PurchasingPrintController::class, 'receipt'])->name('receipt');
    Route::get('supplier-returns/{supplierReturn}/print', [PurchasingPrintController::class, 'supplierReturn'])->name('return');
    Route::get('suppliers/{supplier}/statement', [PurchasingPrintController::class, 'statement'])->name('statement');
});
