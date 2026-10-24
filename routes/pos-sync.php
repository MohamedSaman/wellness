<?php

use App\Http\Controllers\Pos\Sync\CatalogController;
use App\Http\Controllers\Pos\Sync\InvoiceBlockController;
use App\Http\Controllers\Pos\Sync\SaleSyncController;
use App\Http\Middleware\AuthenticateTill;
use Illuminate\Support\Facades\Route;

/*
| Offline sync, called by a till with its device token (Authorization: Bearer).
| Stateless (api middleware): no session, no CSRF, and a signed-in browser
| session is never enough. Only for shops with the offline_sync module.
*/
Route::middleware([AuthenticateTill::class, 'module:offline_sync', 'throttle:pos-sync'])
    ->prefix('pos/sync')
    ->name('pos.sync.')
    ->group(function () {
        Route::get('catalog', CatalogController::class)->name('catalog');
        Route::post('invoice-block', InvoiceBlockController::class)->name('invoice-block');
        Route::post('sales', SaleSyncController::class)->name('sales');
    });
