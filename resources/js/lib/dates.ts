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

const holdTimeFormat = new Intl.DateTimeFormat('en-US', { hour: 'numeric', minute: '2-digit', timeZone: BUSINESS_TIMEZONE });
const holdDayFormat = new Intl.DateTimeFormat('en-US', { weekday: 'short', day: 'numeric', month: 'short', timeZone: BUSINESS_TIMEZONE });

/** An ISO 8601 instant → "3:40 PM, Mon 16 Nov" in Manila time (the hold page's "until …"). */
export function formatHoldEnd(instant: string): string {
    const date = new Date(instant);
    const parts = Object.fromEntries(holdDayFormat.formatToParts(date).map((part) => [part.type, part.value]));

    return `${holdTimeFormat.format(date)}, ${parts.weekday} ${parts.day} ${parts.month}`;
}

/**
 * Time left, in the hold page's voice: "23 hours", "1 hour and 5 minutes", "10 minutes", "1 minute".
 * Rounded up to the minute, so "0 minutes" never shows while time is left; null once it has run out.
 */
export function formatTimeLeft(milliseconds: number): string | null {
    if (milliseconds <= 0) {
        return null;
    }

    const minutes = Math.ceil(milliseconds / 60_000);
    const plural = (count: number, word: string) => `${count} ${word}${count === 1 ? '' : 's'}`;

    if (minutes >= 120) {
        return plural(Math.floor(minutes / 60), 'hour');
    }

    if (minutes >= 60) {
        return minutes === 60 ? '1 hour' : `1 hour and ${plural(minutes - 60, 'minute')}`;
    }

    return plural(minutes, 'minute');
}
