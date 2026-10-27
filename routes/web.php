<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

require __DIR__.'/pos.php';
require __DIR__.'/purchasing.php';
require __DIR__.'/stock.php';
require __DIR__.'/imports.php';
require __DIR__.'/labels.php';
require __DIR__.'/reports.php';
require __DIR__.'/settings.php';
