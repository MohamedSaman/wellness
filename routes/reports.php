<?php

use App\Http\Controllers\ReportPrintController;
use Illuminate\Support\Facades\Route;

/*
| The print page of every admin report (docs/PHARMACY.md §9, branch 11),
| opened from the report's Print button with its filters. The report's own
| access rule decides who may see it (reports.view, and reports.profit for
| profit reports).
*/
Route::middleware('auth')->get('reports/{report}/print', ReportPrintController::class)
    ->where('report', '[a-z0-9-]+')
    ->name('reports.print');
