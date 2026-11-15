<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;

/**
 * Business dates (docs/ARCHITECTURE.md "Time"). Timestamps are UTC, but "today"
 * for the move-in window, MarkMovedIn and EndStays is the calendar date in
 * Manila: at 23:30 UTC on Nov 14 it is already Nov 15 there.
 *
 * Dates come back as midnight in the app timezone (UTC), the same shape the
 * `immutable_date` casts give `move_in` and `move_out`, so they compare
 * directly. A date at midnight Manila time would be 16:00 UTC the day before,
 * and comparing it with a lease date would be off by one at the edges.
 *
 * Driven by Laravel's clock, so travelTo() and Carbon::setTestNow() control it.
 */
final class BusinessDate
{
    public const string TIMEZONE = 'Asia/Manila';

    public static function today(): CarbonImmutable
    {
        return self::of(CarbonImmutable::instance(Date::now())->setTimezone(self::TIMEZONE));
    }

    /**
     * The calendar date of $date as read in its own timezone, with the time
     * dropped: 2026-11-15 07:00 +08:00 becomes 2026-11-15 00:00 UTC.
     */
    public static function of(CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->format('Y-m-d'))->startOfDay();
    }
}
