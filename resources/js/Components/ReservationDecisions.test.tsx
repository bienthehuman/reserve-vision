import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import ReservationDecisions, { endsOn, moveNote, type Decisions } from './ReservationDecisions';
import { refundLabel } from './RefundCard';

afterEach(cleanup);

const base: Decisions = { moves: [], owner_only: [], rejections_left: 2, undo_until: null, today: '2027-03-15', refund: null };

describe('endsOn', () => {
    it('is today, at least one night after the move-in, and never after the planned end', () => {
        expect(endsOn('2027-03-15', '2027-02-15', '2027-08-15')).toBe('2027-03-15');
        expect(endsOn('2027-03-15', '2027-03-15', '2027-09-15')).toBe('2027-03-16');
        expect(endsOn('2027-03-15', '2027-01-15', '2027-03-14')).toBe('2027-03-14');
    });
});

describe('moveNote', () => {
    it('explains a disabled move from the dates the server sent', () => {
        expect(moveNote('moved_in', false, { decisions: base, moveIn: '2027-06-01' })).toBe('Moving in opens on Tue 1 Jun.');
        expect(moveNote('moved_in', true, { decisions: base, moveIn: '2027-03-15' })).toBeNull();
        expect(moveNote('undo', false, { decisions: base, moveIn: '2027-03-15' })).toBe("Undo isn't possible once the stay has started.");
        expect(moveNote('undo', false, { decisions: base, moveIn: '2027-04-01' })).toBe('Undo was possible for 24 hours after confirming.');
        expect(moveNote('withdraw', false, { decisions: base, moveIn: '2027-03-15' })).toBe('Withdrawing is only possible before the move-in date.');
    });

    it('says until when an undo works, in Manila time', () => {
        const decisions = { ...base, undo_until: '2027-03-15T07:40:00+00:00' };

        expect(moveNote('undo', true, { decisions, moveIn: '2027-04-01' })).toMatch(/^Undo is possible until 3:40\sPM today\.$/);
    });

    it('warns before the last rejection', () => {
        expect(moveNote('reject', true, { decisions: { ...base, rejections_left: 1 }, moveIn: '2027-04-01' })).toContain('once more');
        expect(moveNote('reject', true, { decisions: base, moveIn: '2027-04-01' })).toBeNull();
    });
});

describe('ReservationDecisions', () => {
    it('shows only the moves it is given, the disabled ones explained, and the confirm button last', () => {
        render(
            <ReservationDecisions
                reservationId={7}
                status="confirmed"
                moveIn="2027-06-01"
                moveOut="2027-12-01"
                renterName="Ana Cruz"
                decisions={{ ...base, moves: [{ key: 'moved_in', allowed: false }, { key: 'undo', allowed: true }], owner_only: ['withdraw'], undo_until: '2027-03-15T07:40:00+00:00' }}
            />,
        );

        const movedIn = screen.getByRole('button', { name: 'Mark as moved in' }) as HTMLButtonElement;
        expect(movedIn.disabled).toBe(true);
        expect(document.getElementById(movedIn.getAttribute('aria-describedby') ?? '')?.textContent).toBe('Moving in opens on Tue 1 Jun.');
        expect((screen.getByRole('button', { name: 'Undo confirmation' }) as HTMLButtonElement).disabled).toBe(false);
        expect(screen.queryByRole('button', { name: 'Withdraw' })).toBeNull();
        expect(screen.getByText('Only an owner can withdraw a confirmed reservation.')).toBeTruthy();
    });

    it('says to decline once two proofs were sent back', () => {
        render(
            <ReservationDecisions
                reservationId={7}
                status="proof_uploaded"
                moveIn="2027-06-01"
                moveOut="2027-12-01"
                renterName="Ana Cruz"
                decisions={{ ...base, rejections_left: 0, moves: [{ key: 'confirm', allowed: true }, { key: 'decline', allowed: true }] }}
            />,
        );

        expect(screen.getByTestId('rejection-limit').textContent).toContain('decline the reservation');
        expect(screen.queryByRole('button', { name: 'Ask for a new proof' })).toBeNull();
        const buttons = screen.getAllByRole('button').map((button) => button.textContent);
        expect(buttons.at(-1)).toBe('Confirm reservation');
    });

    it('renders nothing when there is nothing to do', () => {
        const { container } = render(
            <ReservationDecisions reservationId={7} status="ended" moveIn="2027-01-01" moveOut="2027-03-01" renterName="Ana Cruz" decisions={base} />,
        );

        expect(container.innerHTML).toBe('');
    });
});

describe('refundLabel', () => {
    it('words the three states', () => {
        expect([refundLabel(null), refundLabel(true), refundLabel(false)]).toEqual(['Not recorded yet', 'Refunded', 'Not refunded']);
    });
});
