<?php

declare(strict_types=1);

namespace App\Support\Viewings;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

/**
 * When a viewing can be asked for (docs/ACCESS.md, flow C): from today to 30 days ahead, between 8:00 AM and
 * 6:00 PM Manila time inclusive, on the hour or the half hour, and at least LEAD_HOURS from now so the landlord
 * has time to answer. After 4:00 PM today has no slot left. All in Manila time; callers store UTC.
 */
final class ViewingSlots
{
    public const string TIMEZONE = 'Asia/Manila';

    /** The least notice, in hours: the earliest slot is the first one at or after now + this. */
    public const int LEAD_HOURS = 2;

    public const int WINDOW_DAYS = 30;

    public const int FIRST_HOUR = 8;

    /** The last slot starts at 6:00 PM. */
    public const int LAST_HOUR = 18;

    public const int STEP_MINUTES = 30;

    /** Now, in Manila. */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::instance(Date::now())->setTimezone(self::TIMEZONE);
    }

    /**
     * Every slot of a day, as "08:00" … "18:00".
     *
     * @return list<string>
     */
    public static function times(): array
    {
        $times = [];

        for ($minutes = self::FIRST_HOUR * 60; $minutes <= self::LAST_HOUR * 60; $minutes += self::STEP_MINUTES) {
            $times[] = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        }

        return $times;
    }

    /** The first slot that can be asked for right now (Manila time): maybe today, maybe tomorrow 8:00 AM. */
    public static function earliest(): CarbonImmutable
    {
        $soonest = self::now()->addHours(self::LEAD_HOURS);
        $into = $soonest->minute % self::STEP_MINUTES;

        if ($into !== 0 || $soonest->second !== 0 || $soonest->microsecond !== 0) {
            $soonest = $soonest->startOfMinute()->addMinutes(self::STEP_MINUTES - $into);
        }

        $first = $soonest->setTime(self::FIRST_HOUR, 0);

        if ($soonest->lt($first)) {
            return $first;
        }

        return $soonest->gt($soonest->setTime(self::LAST_HOUR, 0)) ? $first->addDay() : $soonest;
    }

    /** The last day a viewing can be asked for, as a Manila midnight. */
    public static function lastDay(): CarbonImmutable
    {
        return self::now()->startOfDay()->addDays(self::WINDOW_DAYS);
    }

    /** Whether `$manila` (a Manila-time moment) is on the grid: 8:00 AM to 6:00 PM, in steps. */
    public static function isSlot(CarbonImmutable $manila): bool
    {
        $minutes = $manila->hour * 60 + $manila->minute;

        return $manila->second === 0 && $manila->microsecond === 0
            && $minutes >= self::FIRST_HOUR * 60 && $minutes <= self::LAST_HOUR * 60
            && $minutes % self::STEP_MINUTES === 0;
    }

    /**
     * "2026-11-20" + "15:30" as a Manila moment, or null when either isn't a real date or time.
     */
    public static function at(string $date, string $time): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || preg_match('/^\d{2}:\d{2}$/', $time) !== 1) {
            return null;
        }

        $moment = CarbonImmutable::createFromFormat('!Y-m-d H:i', "{$date} {$time}", self::TIMEZONE);

        return $moment !== null && $moment->format('Y-m-d H:i') === "{$date} {$time}" ? $moment : null;
    }

    /** "Tue 17 Nov, 3:30 PM" in Manila time. */
    public static function label(CarbonImmutable $moment): string
    {
        return $moment->setTimezone(self::TIMEZONE)->format('D j M, g:i A');
    }
}
