<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Concerns\DecidesReservations;
use App\Enums\PaymentProofStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\Domain\InvalidTransition;
use App\Mail\Reservations\ProofRejected;
use App\Models\Reservation;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\Mail\Notify;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * A landlord member sends the proof back (blurry, wrong amount): `proof_uploaded` → `held` with a
 * new 24-hour hold from now, at most twice (`proof_rejections`, RESERVATIONS.md "Correction paths").
 * The pending proof becomes `rejected` with the reason, which the renter sees on the hold page.
 * Who may: ReservationPolicy::rejectProof (owner, caretaker). The space stays blocked: the new hold
 * is live from the same UPDATE that ends `proof_uploaded`. One audit row, `reservation.proof_rejected`.
 */
final class RejectProof
{
    use DecidesReservations;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws InvalidTransition when it isn't waiting for a decision, or a proof was already rejected twice */
    public function handle(User $by, Reservation $reservation, string $reason): Reservation
    {
        $result = DB::transaction(function () use ($by, $reservation, $reason): Reservation {
            $locked = $this->lock($reservation);
            $locked->assertCanMoveTo(ReservationStatus::Held);

            $fields = ['status', 'proof_rejections', 'hold_expires_at', 'decided_by', 'decided_at'];
            $before = $this->snapshot($locked, $fields);
            [$proofBefore, $proofAfter] = $this->review($locked, $by, PaymentProofStatus::Rejected, $reason);

            $locked->update([
                'status' => ReservationStatus::Held,
                'proof_rejections' => $locked->proof_rejections + 1,
                'hold_expires_at' => Date::now()->addHours(Reservation::HOLD_HOURS),
                'decided_by' => $by->id,
                'decided_at' => Date::now(),
            ]);

            $this->audit->record(
                'reservation.proof_rejected',
                $locked,
                [...$before, ...$proofBefore, 'rejection_reason' => null],
                [...$this->snapshot($locked, $fields), ...$proofAfter, 'rejection_reason' => $reason],
            );

            return $locked;
        });

        Notify::renter($result->renter_id, new ProofRejected($result->id, $reason));

        return $result;
    }
}
