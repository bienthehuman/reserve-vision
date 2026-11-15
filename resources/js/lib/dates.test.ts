import { describe, expect, it } from 'vitest';
import { formatDate, formatDateTime } from './dates';

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
