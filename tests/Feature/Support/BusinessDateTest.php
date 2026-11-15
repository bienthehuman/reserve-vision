<?php

declare(strict_types=1);

use App\Support\BusinessDate;
use Carbon\CarbonImmutable;

it('is the calendar date in Manila, at midnight in the app timezone', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00', 'Asia/Manila'));

    $today = BusinessDate::today();

    expect($today->format('Y-m-d H:i:s'))->toBe('2026-11-15 00:00:00')
        ->and($today->timezoneName)->toBe('UTC');
});

it('turns over at midnight Manila time, not UTC', function (): void {
    // Both instants are on Nov 15 in UTC (15:59 and 16:00), but on different days in Manila.
    $this->travelTo(CarbonImmutable::parse('2026-11-15 23:59:59', 'Asia/Manila'));
    expect(now('UTC')->toDateString())->toBe('2026-11-15')
        ->and(BusinessDate::today()->toDateString())->toBe('2026-11-15');

    $this->travelTo(CarbonImmutable::parse('2026-11-16 00:00:00', 'Asia/Manila'));
    expect(now('UTC')->toDateString())->toBe('2026-11-15')
        ->and(BusinessDate::today()->toDateString())->toBe('2026-11-16');
});

it('follows Carbon::setTestNow too', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-01-31 20:00', 'UTC'));

    expect(BusinessDate::today()->toDateString())->toBe('2027-02-01');

    CarbonImmutable::setTestNow();
});

it('keeps the calendar date of any date, whatever its timezone', function (): void {
    expect(BusinessDate::of(CarbonImmutable::parse('2026-11-15 07:00', 'Asia/Manila'))->toIso8601String())
        ->toBe('2026-11-15T00:00:00+00:00');
});
