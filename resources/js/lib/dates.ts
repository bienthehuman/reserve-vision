/**
 * Dates at the view edge. Calendar dates arrive as "YYYY-MM-DD" (no time, no
 * zone: a business date, ARCHITECTURE.md "Time"); instants arrive as ISO 8601
 * and are shown in Asia/Manila, so server and browser render the same text.
 */
export const BUSINESS_TIMEZONE = 'Asia/Manila';

const dateFormat = new Intl.DateTimeFormat('en-PH', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });

const dateTimeFormat = new Intl.DateTimeFormat('en-PH', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
    timeZone: BUSINESS_TIMEZONE,
});

/** "2026-12-01" → "Dec 1, 2026" (the Philippine order). */
export function formatDate(date: string): string {
    return dateFormat.format(new Date(`${date}T00:00:00Z`));
}

/** An ISO 8601 instant → "Nov 16, 2026, 10:00 AM" in Manila time. */
export function formatDateTime(instant: string): string {
    return dateTimeFormat.format(new Date(instant));
}
