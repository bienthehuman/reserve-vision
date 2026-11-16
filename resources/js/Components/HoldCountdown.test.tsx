import { act, cleanup, render, screen } from '@testing-library/react';
import { renderToString } from 'react-dom/server';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import HoldCountdown from './HoldCountdown';

const reload = vi.fn();
vi.mock('@inertiajs/react', () => ({ router: { reload: () => reload() } }));

// 3:40 PM on Mon 16 Nov 2026 in Manila.
const END = '2026-11-16T07:40:00+00:00';
const MINUTE = 60_000;
const end = new Date(END).getTime();

afterEach(() => {
    cleanup();
    vi.useRealTimers();
});

describe('HoldCountdown', () => {
    beforeEach(() => {
        reload.mockClear();
        vi.useFakeTimers();
    });

    it('says when the hold ends in the server HTML, without JavaScript', () => {
        const html = renderToString(<HoldCountdown expiresAt={END} />);

        expect(html.replace(/<!-- -->/g, '')).toMatch(/Your hold ends at 3:40\sPM, Mon 16 Nov, Manila time\./);
    });

    it('counts down once hydrated, every minute', () => {
        vi.setSystemTime(end - 23 * 60 * MINUTE);
        render(<HoldCountdown expiresAt={END} />);

        expect(screen.getByText('Your hold ends in 23 hours')).not.toBeNull();
        expect(screen.getByText(/until 3:40\sPM, Mon 16 Nov, Manila time/)).not.toBeNull();

        act(() => {
            vi.setSystemTime(end - 62 * MINUTE); // the tick below adds a minute: 61 left
            vi.advanceTimersByTime(MINUTE);
        });
        expect(screen.getByText('Your hold ends in 1 hour and 1 minute')).not.toBeNull();
    });

    it('announces politely only at 1 hour and at 10 minutes left', () => {
        vi.setSystemTime(end - 62 * MINUTE - 30_000);
        const { container } = render(<HoldCountdown expiresAt={END} />);
        const live = container.querySelector('[aria-live="polite"]');

        expect(live?.textContent).toBe('');

        const minuteLater = () =>
            act(() => {
                vi.advanceTimersByTime(MINUTE);
            });

        minuteLater(); // 61.5 minutes left
        expect(live?.textContent).toBe('');
        minuteLater(); // 60.5
        expect(live?.textContent).toBe('');
        minuteLater(); // 59.5: crossed the hour
        expect(live?.textContent).toBe('Your hold ends in 1 hour.');

        for (let i = 0; i < 49; i += 1) {
            minuteLater();
        } // 10.5 minutes left
        expect(live?.textContent).toBe('Your hold ends in 1 hour.');
        minuteLater(); // 9.5
        expect(live?.textContent).toBe('Your hold ends in 10 minutes.');
    });

    it('says the hold has ended and reloads the page data when it runs out', () => {
        vi.setSystemTime(end - 30_000);
        render(<HoldCountdown expiresAt={END} />);

        act(() => {
            vi.advanceTimersByTime(MINUTE);
        });

        expect(screen.getByText('Your hold has ended.')).not.toBeNull();
        expect(reload).toHaveBeenCalled();
    });
});
