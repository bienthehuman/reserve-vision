import { cleanup, render } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { reservationStatuses, type ReservationStatus } from '@/types/reservation';
import StatusBadge from './StatusBadge';

// The mapping table from docs/DESIGN_SYSTEM.md, written out independently of the component.
const expected: Record<ReservationStatus, { label: string; icon: string; color: string }> = {
    held: { label: 'On hold', icon: 'bi-hourglass', color: 'warning' },
    proof_uploaded: { label: 'Awaiting review', icon: 'bi-file-earmark-check', color: 'info' },
    confirmed: { label: 'Confirmed', icon: 'bi-check-circle', color: 'success' },
    active: { label: 'Living here', icon: 'bi-house-door', color: 'primary' },
    expired: { label: 'Hold expired', icon: 'bi-clock-history', color: 'secondary' },
    declined: { label: 'Declined', icon: 'bi-x-circle', color: 'danger' },
    cancelled: { label: 'Cancelled', icon: 'bi-slash-circle', color: 'secondary' },
    ended: { label: 'Stay ended', icon: 'bi-box-arrow-right', color: 'secondary' },
};

afterEach(cleanup);

describe('StatusBadge', () => {
    it.each(reservationStatuses)('renders the label, icon and color for %s', (status) => {
        const { container } = render(<StatusBadge status={status} />);
        const badge = container.querySelector(`[data-status="${status}"]`);
        const icon = badge?.querySelector('svg');

        expect(badge?.textContent).toBe(expected[status].label);
        expect(badge?.classList.contains(`status-badge--${expected[status].color}`)).toBe(true);
        expect(icon?.classList.contains(expected[status].icon)).toBe(true);
        expect(icon?.getAttribute('aria-hidden')).toBe('true');
    });

    it('appends detail after the label', () => {
        const { container } = render(<StatusBadge status="held" detail="23h left" />);

        expect(container.textContent).toBe('On hold · 23h left');
    });
});
