<?php

declare(strict_types=1);

use App\Http\Controllers\HomeController;
use App\Http\Controllers\Landlord\DashboardController;
use App\Http\Controllers\StyleguideController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('/styleguide', StyleguideController::class)->name('styleguide');

require __DIR__.'/auth.php';

// Landlord pages: logged in, email verified, and a landlord user (ACCESS.md).
Route::middleware(['auth', 'verified', 'landlord'])->prefix('landlord')->name('landlord.')->group(function (): void {
    Route::get('/', DashboardController::class)->name('dashboard');
});
