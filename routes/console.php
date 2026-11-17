<?php

declare(strict_types=1);

use App\Support\BusinessDate;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Scheduled jobs (slice 6.3, docs/ARCHITECTURE.md "Scheduled jobs"). Each one calls actions, logs one summary line,
// is idempotent, and never runs twice at once. The `scheduler` service runs `schedule:work`.
Schedule::command('reservations:expire-holds')->everyMinute()->withoutOverlapping();
Schedule::command('reservations:end-stays')->dailyAt('00:05')->timezone(BusinessDate::TIMEZONE)->withoutOverlapping();
Schedule::command('reservations:remind-proofs')->hourly()->withoutOverlapping();
