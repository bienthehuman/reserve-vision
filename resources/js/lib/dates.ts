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

const dayFormat = new Intl.DateTimeFormat('en-US', { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' });
const manilaDate = new Intl.DateTimeFormat('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit', timeZone: BUSINESS_TIMEZONE });

/** "2027-06-01" → "Tue 1 Jun" (a business date, slice 6.1's "Moving in opens on …"). */
export function formatDay(date: string): string {
    const parts = Object.fromEntries(dayFormat.formatToParts(new Date(`${date}T00:00:00Z`)).map((part) => [part.type, part.value]));

    return `${parts.weekday} ${parts.day} ${parts.month}`;
}

/** "2027-06-01" plus `days` → "2027-06-02". */
export function addDays(date: string, days: number): string {
    const next = new Date(`${date}T00:00:00Z`);
    next.setUTCDate(next.getUTCDate() + days);

    return next.toISOString().slice(0, 10);
}

/**
 * An ISO 8601 instant → "3:40 PM today", "3:40 PM tomorrow" or "3:40 PM, Mon 16 Nov" in Manila time, against
 * `today` (the server's business date, so the server and the browser write the same words).
 */
export function formatUntil(instant: string, today: string): string {
    const date = new Date(instant);
    const day = manilaDate.format(date);
    const time = holdTimeFormat.format(date);

    if (day === today) {
        return `${time} today`;
    }

    if (day === addDays(today, 1)) {
        return `${time} tomorrow`;
    }

    return `${time}, ${formatDay(day)}`;
}

/** "15:30" → "3:30 PM" (a viewing slot, Manila time). */
export function formatSlot(time: string): string {
    const [hours = '0', minutes = '00'] = time.split(':');
    const hour = Number(hours);

    return `${hour % 12 === 0 ? 12 : hour % 12}:${minutes} ${hour < 12 ? 'AM' : 'PM'}`;
}

/** "2027-06-01" against `today` → "Today, Tue 1 Jun", "Tomorrow, Wed 2 Jun" or "Thu 3 Jun" (the viewing form's days). */
export function formatViewingDay(date: string, today: string): string {
    if (date === today) {
        return `Today, ${formatDay(date)}`;
    }

    return date === addDays(today, 1) ? `Tomorrow, ${formatDay(date)}` : formatDay(date);
}
