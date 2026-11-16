import { Card } from 'react-bootstrap';
import ActionButton from '@/Components/ActionButton';
import ConfirmButton from '@/Components/ConfirmButton';
import { addDays, formatDay, formatUntil } from '@/lib/dates';
import type { ReservationStatus } from '@/types';

export type MoveKey = 'confirm' | 'reject' | 'decline' | 'moved_in' | 'undo' | 'withdraw' | 'end_stay';

export interface Decisions {
    moves: { key: MoveKey; allowed: boolean }[];
    /** Moves the transition table has from this status that only an owner may make (a caretaker's Withdraw). */
    owner_only: MoveKey[];
    rejections_left: number;
    undo_until: string | null;
    /** The business date, "YYYY-MM-DD". */
    today: string;
    refund: { can_record: boolean; fee_refunded: boolean | null; refund_note: string | null } | null;
}

interface ReservationDecisionsProps {
    reservationId: number;
    status: ReservationStatus;
    moveIn: string;
    moveOut: string;
    renterName: string;
    decisions: Decisions;
}

const INTRO: Partial<Record<ReservationStatus, string>> = {
    proof_uploaded: 'Check the proof of payment below. Then confirm the reservation, ask for a new proof, or decline it.',
    confirmed: 'The bed is kept for this renter. Mark them as moved in when they arrive.',
    active: 'The renter lives here. End the stay when they move out.',
};

/** The date the bed is free from if the stay ends today: max(today, move-in + 1 day), unless the planned end is earlier. */
export function endsOn(today: string, moveIn: string, moveOut: string): string {
    const earliest = addDays(moveIn, 1);
    const leaves = today > earliest ? today : earliest;

    return leaves < moveOut ? leaves : moveOut;
}

/** Why a move is disabled right now, or what to know before using it (null: nothing to say). */
export function moveNote(key: MoveKey, allowed: boolean, { decisions, moveIn }: { decisions: Decisions; moveIn: string }): string | null {
    switch (key) {
        case 'undo':
            if (allowed && decisions.undo_until) {
                return `Undo is possible until ${formatUntil(decisions.undo_until, decisions.today)}.`;
            }

            return decisions.today >= moveIn ? "Undo isn't possible once the stay has started." : 'Undo was possible for 24 hours after confirming.';
        case 'moved_in':
            return allowed ? null : `Moving in opens on ${formatDay(moveIn)}.`;
        case 'withdraw':
            return allowed ? null : 'Withdrawing is only possible before the move-in date.';
        case 'reject':
            return decisions.rejections_left === 1 ? 'You can ask for a new proof once more. After that, confirm or decline.' : null;
        default:
            return null;
    }
}

/**
 * The landlord's moves on one reservation (slice 6.1, ACCESS.md "Decisions"): only what the transition table allows from
 * this status for this role, each disabled move explained under the buttons. The server decides (DecisionPanel) and checks
 * again on the locked row; this only shows it.
 */
export default function ReservationDecisions({ reservationId, status, moveIn, moveOut, renterName, decisions }: ReservationDecisionsProps) {
    const base = `/landlord/reservations/${reservationId}`;
    const notes = decisions.moves
        .map(({ key, allowed }) => ({ key, text: moveNote(key, allowed, { decisions, moveIn }) }))
        .filter((note): note is { key: MoveKey; text: string } => note.text !== null);
    const limitReached = status === 'proof_uploaded' && decisions.rejections_left === 0;
    const ownerOnly = decisions.owner_only.includes('withdraw');

    if (decisions.moves.length === 0 && !ownerOnly) {
        return null;
    }

    const noteId = (key: MoveKey) => `decision-note-${key}`;
    const describedBy = (key: MoveKey) => (notes.some((note) => note.key === key) ? noteId(key) : undefined);
    const leaves = endsOn(decisions.today, moveIn, moveOut);

    const button = (key: MoveKey, allowed: boolean) => {
        switch (key) {
            case 'confirm':
                return (
                    <ActionButton key={key} href={`${base}/confirm`} method="post" variant="primary" size="md" disabled={!allowed} describedBy={describedBy(key)}>
                        Confirm reservation
                    </ActionButton>
                );
            case 'reject':
                return (
                    <ConfirmButton
                        key={key}
                        href={`${base}/reject`}
                        method="post"
                        size="md"
                        tone="primary"
                        disabled={!allowed}
                        describedBy={describedBy(key)}
                        title="Ask for a new proof?"
                        body={`${renterName} gets a new 24-hour hold to send another proof. The bed stays theirs meanwhile.`}
                        reason={{ label: "What's wrong with this proof?", help: 'The renter sees this, e.g. "The amount is ₱1,000, the fee is ₱1,500."' }}
                        confirmLabel="Ask for a new proof"
                        pendingLabel="Sending…"
                        cancelLabel="Go back"
                    >
                        Ask for a new proof
                    </ConfirmButton>
                );
            case 'decline':
                return (
                    <ConfirmButton
                        key={key}
                        href={`${base}/decline`}
                        method="post"
                        size="md"
                        disabled={!allowed}
                        describedBy={describedBy(key)}
                        title="Decline this reservation?"
                        body="The bed is free for others at once. You can't undo this. Record afterwards whether you refunded the fee."
                        reason={{ label: 'Why are you declining?', help: 'The renter sees this.' }}
                        confirmLabel="Decline reservation"
                        pendingLabel="Declining…"
                        cancelLabel="Keep it"
                    >
                        Decline
                    </ConfirmButton>
                );
            case 'moved_in':
                return (
                    <ConfirmButton
                        key={key}
                        href={`${base}/moved-in`}
                        method="post"
                        size="md"
                        tone="primary"
                        disabled={!allowed}
                        describedBy={describedBy(key)}
                        title={`Has ${renterName} moved in?`}
                        body="The stay starts. You can't undo this."
                        confirmLabel="Mark as moved in"
                        pendingLabel="Saving…"
                        cancelLabel="Not yet"
                    >
                        Mark as moved in
                    </ConfirmButton>
                );
            case 'undo':
                return (
                    <ActionButton key={key} href={`${base}/undo`} method="post" size="md" disabled={!allowed} describedBy={describedBy(key)}>
                        Undo confirmation
                    </ActionButton>
                );
            case 'withdraw':
                return (
                    <ConfirmButton
                        key={key}
                        href={`${base}/withdraw`}
                        method="post"
                        size="md"
                        disabled={!allowed}
                        describedBy={describedBy(key)}
                        title="Withdraw this reservation?"
                        body={`${renterName} loses the bed, and it is free for others at once. You can't undo this. Record afterwards whether you refunded the fee.`}
                        reason={{ label: "Why can't you keep this booking?", help: 'The renter sees this.' }}
                        confirmLabel="Withdraw reservation"
                        pendingLabel="Withdrawing…"
                        cancelLabel="Keep it"
                    >
                        Withdraw
                    </ConfirmButton>
                );
            case 'end_stay':
                return (
                    <ConfirmButton
                        key={key}
                        href={`${base}/end`}
                        method="post"
                        size="md"
                        disabled={!allowed}
                        describedBy={describedBy(key)}
                        title="End this stay?"
                        body={`The stay ends and the bed is free from ${formatDay(leaves)}. You can't undo this.`}
                        confirmLabel="End stay"
                        pendingLabel="Ending…"
                        cancelLabel="Keep it"
                    >
                        End stay
                    </ConfirmButton>
                );
        }
    };

    // The page's one primary button (Confirm) goes last, as in a form.
    const ordered = [...decisions.moves].sort((a, b) => Number(a.key === 'confirm') - Number(b.key === 'confirm'));

    return (
        <Card className="mb-4" data-testid="decision-panel">
            <Card.Body>
                <h2 className="h3">What to do next</h2>
                {INTRO[status] && <p>{INTRO[status]}</p>}
                {limitReached && (
                    <p data-testid="rejection-limit">
                        You've asked for a new proof twice. If this one isn't right either, decline the reservation.
                    </p>
                )}
                {decisions.moves.length > 0 && <div className="button-row">{ordered.map(({ key, allowed }) => button(key, allowed))}</div>}
                {(notes.length > 0 || ownerOnly) && (
                    <ul className="decision-notes">
                        {notes.map((note) => (
                            <li key={note.key} id={noteId(note.key)}>
                                {note.text}
                            </li>
                        ))}
                        {ownerOnly && <li>Only an owner can withdraw a confirmed reservation.</li>}
                    </ul>
                )}
            </Card.Body>
        </Card>
    );
}
