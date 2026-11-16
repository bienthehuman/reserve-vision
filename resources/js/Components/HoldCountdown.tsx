import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Hourglass } from 'react-bootstrap-icons';
import { formatHoldEnd, formatTimeLeft } from '@/lib/dates';

/** Moments that are announced to screen readers, in minutes left (DESIGN_SYSTEM.md "Accessibility"). */
export const ANNOUNCE_AT_MINUTES = [60, 10] as const;

const MINUTE = 60_000;

interface HoldCountdownProps {
    /** `hold_expires_at`, ISO 8601. */
    expiresAt: string;
    /** For tests: the clock. */
    now?: () => number;
}

/**
 * How long the hold has left (DESIGN_SYSTEM.md "HoldCountdown"). The server's HTML already says
 * when it ends ("until 3:40 PM, Mon 16 Nov, Manila time"), so it is right without JavaScript. Once
 * hydrated it also says how long is left ("Your hold ends in 23 hours") and updates every minute,
 * but only announces itself (politely) when 1 hour and then 10 minutes are left. When the hold runs
 * out the page reloads its data, and the server shows the hold as expired.
 */
export default function HoldCountdown({ expiresAt, now = Date.now }: HoldCountdownProps) {
    const end = new Date(expiresAt).getTime();
    // Null until hydrated, so the server's HTML and the first client render are the same.
    const [left, setLeft] = useState<number | null>(null);
    const [announcement, setAnnouncement] = useState('');
    const announced = useRef(new Set<number>());

    useEffect(() => {
        const tick = () => {
            const remaining = end - now();
            setLeft(remaining);

            // Announce each threshold once, in the minute after the hold crosses it (a page opened long after stays quiet).
            for (const threshold of ANNOUNCE_AT_MINUTES) {
                const below = remaining <= threshold * MINUTE;
                if (!below) {
                    announced.current.delete(threshold);
                } else if (!announced.current.has(threshold) && remaining > (threshold - 1) * MINUTE) {
                    announced.current.add(threshold);
                    setAnnouncement(`Your hold ends in ${formatTimeLeft(remaining)}.`);
                }
            }

            if (remaining <= 0) {
                router.reload();
            }
        };

        tick();
        const timer = window.setInterval(tick, MINUTE);

        return () => window.clearInterval(timer);
    }, [end, now]);

    const until = `until ${formatHoldEnd(expiresAt)}, Manila time`;
    const timeLeft = left === null ? null : formatTimeLeft(left);

    return (
        <div className="hold-countdown" data-testid="hold-countdown">
            <Hourglass aria-hidden="true" focusable="false" className="hold-countdown__icon" />
            <p className="mb-0">
                {left === null ? (
                    <strong>Your hold ends at {formatHoldEnd(expiresAt)}, Manila time.</strong>
                ) : timeLeft === null ? (
                    <strong>Your hold has ended.</strong>
                ) : (
                    <>
                        <strong>Your hold ends in {timeLeft}</strong>
                        <span className="d-block small text-body-secondary">{until}</span>
                    </>
                )}
            </p>
            <p className="visually-hidden" aria-live="polite" aria-atomic="true">
                {announcement}
            </p>
        </div>
    );
}
