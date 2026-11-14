<?php

declare(strict_types=1);

use App\Http\Controllers\HomeController;
use App\Http\Controllers\StyleguideController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('/styleguide', StyleguideController::class)->name('styleguide');
