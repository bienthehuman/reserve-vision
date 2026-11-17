<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Concerns\DecidesReservations;
use App\Enums\PaymentProofStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\Domain\InvalidTransition;
use App\Mail\Reservations\ConfirmationUndone;
use App\Models\Reservation;
use App\Support\AuditLogger;
use App\Support\Mail\Notify;
use Illuminate\Support\Facades\DB;

/**
 * A landlord member takes a confirmation back: `confirmed` → `proof_uploaded`, within 24 hours of
 * `decided_at` and before the move-in date (the guards are in Reservation::assertCanMoveTo()). The
 * accepted proof is `pending` again and the decision is cleared, so the reservation is exactly as
 * it was before the confirm; the audit log keeps who confirmed. The space stays blocked. Who may:
 * ReservationPolicy::undoConfirmation (owner, caretaker). One audit row, `reservation.confirmation_undone`.
 */
final class UndoConfirmation
{
    use DecidesReservations;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws InvalidTransition when it isn't confirmed, the 24 hours are over, or the stay has started */
    public function handle(Reservation $reservation): Reservation
    {
        $result = DB::transaction(function () use ($reservation): Reservation {
            $locked = $this->lock($reservation);
            $locked->assertCanMoveTo(ReservationStatus::ProofUploaded);

            $fields = ['status', 'decided_by', 'decided_at'];
            $before = $this->snapshot($locked, $fields);

            // The confirm accepted the pending proof; a reservation has at most one accepted proof.
            $accepted = $this->proofs($locked, PaymentProofStatus::Accepted);

            foreach ($accepted as $proof) {
                $proof->update(['status' => PaymentProofStatus::Pending, 'reviewed_by' => null, 'reviewed_at' => null]);
            }

            $locked->update([
                'status' => ReservationStatus::ProofUploaded,
                'decided_by' => null,
                'decided_at' => null,
            ]);

            $proofFields = $accepted->isEmpty() ? [[], []] : [['proof_status' => PaymentProofStatus::Accepted], ['proof_status' => PaymentProofStatus::Pending]];

            $this->audit->record('reservation.confirmation_undone', $locked, [...$before, ...$proofFields[0]], [...$this->snapshot($locked, $fields), ...$proofFields[1]]);

            return $locked;
        });

        Notify::renter($result->renter_id, new ConfirmationUndone($result->id));

        return $result;
    }
}
