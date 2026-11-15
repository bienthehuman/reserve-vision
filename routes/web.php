<?php

declare(strict_types=1);

use App\Http\Controllers\HomeController;
use App\Http\Controllers\Landlord\ArchivePropertyController;
use App\Http\Controllers\Landlord\ArchiveRoomController;
use App\Http\Controllers\Landlord\ArchiveSpaceController;
use App\Http\Controllers\Landlord\DashboardController;
use App\Http\Controllers\Landlord\EditProperty;
use App\Http\Controllers\Landlord\EditRoom;
use App\Http\Controllers\Landlord\ListMembers;
use App\Http\Controllers\Landlord\ListProperties;
use App\Http\Controllers\Landlord\ListReservations;
use App\Http\Controllers\Landlord\NewProperty;
use App\Http\Controllers\Landlord\NewRoom;
use App\Http\Controllers\Landlord\RenameSpaceController;
use App\Http\Controllers\Landlord\SaveProperty;
use App\Http\Controllers\Landlord\SaveRoom;
use App\Http\Controllers\Landlord\StoreInvitation;
use App\Http\Controllers\Landlord\StoreProperty;
use App\Http\Controllers\Landlord\StoreRoom;
use App\Http\Controllers\Landlord\StoreSpace;
use App\Http\Controllers\Landlord\UnarchivePropertyController;
use App\Http\Controllers\Landlord\UnarchiveRoomController;
use App\Http\Controllers\Landlord\UnarchiveSpaceController;
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

    // Slice 4.1: owners edit; a caretaker gets 403 on every one of these (the pages above stay read-only for them).
    Route::get('/properties/create', NewProperty::class)->name('properties.create');
    Route::post('/properties', StoreProperty::class)->name('properties.store');
    Route::get('/properties/{property}/edit', EditProperty::class)->whereNumber('property')->name('properties.edit');
    Route::put('/properties/{property}', SaveProperty::class)->whereNumber('property')->name('properties.update');
    Route::post('/properties/{property}/archive', ArchivePropertyController::class)->whereNumber('property')->name('properties.archive');
    Route::delete('/properties/{property}/archive', UnarchivePropertyController::class)->whereNumber('property')->name('properties.unarchive');

    Route::get('/properties/{property}/rooms/create', NewRoom::class)->whereNumber('property')->name('properties.rooms.create');
    Route::post('/properties/{property}/rooms', StoreRoom::class)->whereNumber('property')->name('properties.rooms.store');
    Route::get('/rooms/{room}/edit', EditRoom::class)->whereNumber('room')->name('rooms.edit');
    Route::put('/rooms/{room}', SaveRoom::class)->whereNumber('room')->name('rooms.update');
    // ExposeRequestTiming is a no-op unless CONCURRENCY_PROBE (never in production): the archive-versus-reserve race proves overlap with it.
    Route::post('/rooms/{room}/archive', ArchiveRoomController::class)->whereNumber('room')->middleware(ExposeRequestTiming::class)->name('rooms.archive');
    Route::delete('/rooms/{room}/archive', UnarchiveRoomController::class)->whereNumber('room')->name('rooms.unarchive');

    Route::post('/rooms/{room}/spaces', StoreSpace::class)->whereNumber('room')->name('rooms.spaces.store');
    Route::patch('/spaces/{space}', RenameSpaceController::class)->whereNumber('space')->name('spaces.update');
    Route::post('/spaces/{space}/archive', ArchiveSpaceController::class)->whereNumber('space')->name('spaces.archive');
    Route::delete('/spaces/{space}/archive', UnarchiveSpaceController::class)->whereNumber('space')->name('spaces.unarchive');

    Route::get('/members', ListMembers::class)->name('members.index');
    Route::post('/invitations', StoreInvitation::class)->name('invitations.store');
});
