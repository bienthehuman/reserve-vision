import { describe, expect, it } from 'vitest';
import { addDays, formatDate, formatDateTime, formatDay, formatHoldEnd, formatTimeLeft, formatUntil } from './dates';

describe('formatDate', () => {
    it('shows a calendar date without shifting it by the local timezone', () => {
        expect(formatDate('2026-12-01')).toBe('Dec 1, 2026');
        expect(formatDate('2027-02-28')).toBe('Feb 28, 2027');
    });
});

describe('formatDateTime', () => {
    it('shows an instant in Manila time', () => {
        // 02:00 UTC is 10:00 in Manila (UTC+8, no daylight saving).
        expect(formatDateTime('2026-11-16T02:00:00+00:00')).toMatch(/^Nov 16, 2026, 10:00\sAM$/);
    });
});

describe('formatHoldEnd', () => {
    it('shows the time, weekday, day and month in Manila time', () => {
        // 07:40 UTC is 3:40 PM in Manila.
        expect(formatHoldEnd('2026-11-16T07:40:00+00:00')).toMatch(/^3:40\sPM, Mon 16 Nov$/);
        // Past midnight in Manila is already the next day.
        expect(formatHoldEnd('2026-11-16T16:30:00+00:00')).toMatch(/^12:30\sAM, Tue 17 Nov$/);
    });
});

describe('formatTimeLeft', () => {
    it('speaks in hours, then minutes, rounding up', () => {
        const minute = 60_000;
        expect(formatTimeLeft(23 * 60 * minute + 30 * minute)).toBe('23 hours');
        expect(formatTimeLeft(2 * 60 * minute)).toBe('2 hours');
        expect(formatTimeLeft(65 * minute)).toBe('1 hour and 5 minutes');
        expect(formatTimeLeft(61 * minute)).toBe('1 hour and 1 minute');
        expect(formatTimeLeft(60 * minute)).toBe('1 hour');
        expect(formatTimeLeft(10 * minute)).toBe('10 minutes');
        expect(formatTimeLeft(1)).toBe('1 minute');
        expect(formatTimeLeft(0)).toBeNull();
        expect(formatTimeLeft(-5)).toBeNull();
    });
});

describe('formatDay', () => {
    it('shows a business date as weekday, day and month', () => {
        expect(formatDay('2027-06-01')).toBe('Tue 1 Jun');
    });
});

describe('addDays', () => {
    it('crosses month ends', () => {
        expect(addDays('2027-02-28', 1)).toBe('2027-03-01');
        expect(addDays('2027-03-15', -1)).toBe('2027-03-14');
    });
});

describe('formatUntil', () => {
    it('says today or tomorrow against the business date, in Manila time', () => {
        // 07:40 UTC on Mar 15 is 3:40 PM on Mar 15 in Manila.
        expect(formatUntil('2027-03-15T07:40:00+00:00', '2027-03-15')).toMatch(/^3:40\sPM today$/);
        expect(formatUntil('2027-03-15T07:40:00+00:00', '2027-03-14')).toMatch(/^3:40\sPM tomorrow$/);
        // 17:00 UTC on Mar 15 is already 1:00 AM on Mar 16 in Manila.
        expect(formatUntil('2027-03-15T17:00:00+00:00', '2027-03-16')).toMatch(/^1:00\sAM today$/);
        expect(formatUntil('2027-03-15T07:40:00+00:00', '2027-03-10')).toMatch(/^3:40\sPM, Mon 15 Mar$/);
    });
});
