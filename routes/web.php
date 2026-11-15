<?php

declare(strict_types=1);

use App\Http\Controllers\HomeController;
use App\Http\Controllers\Landlord\DashboardController;
use App\Http\Controllers\Landlord\ListMembers;
use App\Http\Controllers\Landlord\ListProperties;
use App\Http\Controllers\Landlord\ListReservations;
use App\Http\Controllers\Landlord\StoreInvitation;
use App\Http\Controllers\Landlord\UpdateRoomRate;
use App\Http\Controllers\Landlord\ViewProperty;
use App\Http\Controllers\Landlord\ViewReservation;
use App\Http\Controllers\Reservations\ShowReservation;
use App\Http\Controllers\Reservations\StoreReservation;
use App\Http\Controllers\Rooms\ShowRoom;
use App\Http\Controllers\StyleguideController;
use App\Http\Middleware\ExposeRequestTiming;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('/styleguide', StyleguideController::class)->name('styleguide');

require __DIR__.'/auth.php';

// The minimal room page (slice 2.5; 5.1 replaces it). Only listed rooms: anything else is 404.
Route::get('/rooms/{room}', ShowRoom::class)->whereNumber('room')->name('rooms.show');

// Renters reserve and see their own reservations (RESERVATIONS.md). Landlords get 403.
Route::middleware(['auth', 'verified', 'renter'])->group(function (): void {
    Route::post('/rooms/{room}/reservations', StoreReservation::class)
        ->whereNumber('room')
        // ExposeRequestTiming is a no-op unless CONCURRENCY_PROBE (never in production).
        ->middleware(['throttle:reservations', ExposeRequestTiming::class])
        ->name('rooms.reservations.store');

    Route::get('/reservations/{reservation}', ShowReservation::class)->whereNumber('reservation')->name('reservations.show');
});

// Landlord pages: logged in, email verified, and a landlord user (ACCESS.md).
Route::middleware(['auth', 'verified', 'landlord'])->prefix('landlord')->name('landlord.')->group(function (): void {
    Route::get('/', DashboardController::class)->name('dashboard');

    // Slice 3.2. Record parameters bind through the isolation scope: another account's id is a 404.
    // tests/Feature/Isolation/T6CrossAccessTest.php walks every route in this group.
    Route::get('/reservations', ListReservations::class)->name('reservations.index');
    Route::get('/reservations/{reservation}', ViewReservation::class)->whereNumber('reservation')->name('reservations.show');

    Route::get('/properties', ListProperties::class)->name('properties.index');
    Route::get('/properties/{property}', ViewProperty::class)->whereNumber('property')->name('properties.show');
    Route::patch('/rooms/{room}/rate', UpdateRoomRate::class)->whereNumber('room')->name('rooms.rate.update');

    Route::get('/members', ListMembers::class)->name('members.index');
    Route::post('/invitations', StoreInvitation::class)->name('invitations.store');
});
