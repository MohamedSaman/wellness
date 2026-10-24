<?php

use App\Http\Controllers\ImportFileController;
use Illuminate\Support\Facades\Route;

/*
| Import files (imports.manage): the templates to fill in, and for each
| import in the log the file as uploaded and its error report. Imports
| themselves run from the admin panel (Administration -> Imports).
*/
Route::middleware(['auth'])->prefix('imports')->name('imports.')->group(function () {
    Route::get('templates/{type}', [ImportFileController::class, 'template'])->name('template');
    Route::get('{run}/file', [ImportFileController::class, 'file'])->name('file');
    Route::get('{run}/report', [ImportFileController::class, 'report'])->name('report');
});
