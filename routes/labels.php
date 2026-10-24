<?php

use App\Http\Controllers\LabelPrintController;
use Illuminate\Support\Facades\Route;

/*
| Barcode labels (labels module, docs/PHARMACY.md §9, branch 7b): a print of
| labels as a page for the browser's print dialog, at the template's exact
| size (@page), with a preview on screen. Prints are made from the admin
| panel (LabelService); whoever made one, or the owner, may open it again.
| Another shop's print is not found.
*/
Route::middleware(['auth', 'module:labels'])->prefix('labels')->name('labels.')->group(function () {
    Route::get('prints/{labelPrint}', LabelPrintController::class)->name('print');
});
