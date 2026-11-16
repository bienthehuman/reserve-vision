<?php

declare(strict_types=1);

use App\Http\Controllers\Places\ListBarangays;
use App\Http\Controllers\Places\ListCities;
use Illuminate\Support\Facades\Route;

/*
| The places lists (slice 4.4): public, read-only JSON for the property form's dropdowns
| (and 5.1's search). Loaded by bootstrap/app.php without the `web` group: no session,
| no cookies, so a browser or proxy can cache them. Rate limited per IP.
*/

Route::get('/places/provinces/{province:slug}/cities', ListCities::class)->name('places.cities');
Route::get('/places/cities/{city:slug}/barangays', ListBarangays::class)->name('places.barangays');
