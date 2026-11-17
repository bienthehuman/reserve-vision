<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Support\BusinessDate;
use Carbon\CarbonInterface;

/** Dates and times the way emails write them (docs/DESIGN_SYSTEM.md "Voice"): Manila time, labelled. */
final class Format
{
    /**
     * A translated line (`__()` can return an array for a key that holds one).
     *
     * @param  array<string, string|int>  $replace
     */
    public static function t(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }

    /** "Mon, 1 Jun 2026" for a calendar date (a lease date: no timezone shift). */
    public static function day(CarbonInterface $date): string
    {
        return $date->format('D, j M Y');
    }

    /** "Mon, 1 Jun 2026, 3:40 PM Philippine time" for an instant. */
    public static function moment(CarbonInterface $instant): string
    {
        return $instant->setTimezone(BusinessDate::TIMEZONE)->format('D, j M Y, g:i A').' Philippine time';
    }
}
