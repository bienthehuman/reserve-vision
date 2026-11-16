<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Concerns\DecidesReservations;
use App\Enums\PaymentProofStatus;
use App\Enums\ReservationStatus;
use App\Exceptions\Domain\InvalidTransition;
use App\Models\Reservation;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * A landlord member accepts the renter's payment: `proof_uploaded` → `confirmed`, and the pending
 * proof becomes `accepted` (RESERVATIONS.md). Who may: ReservationPolicy::confirm (owner, caretaker).
 * The space stays blocked throughout (both statuses block). `decided_by` / `decided_at` start the
 * 24-hour undo window. One audit row, `reservation.confirmed`.
 */
final class ConfirmReservation
{
    use DecidesReservations;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws InvalidTransition when the reservation isn't waiting for a decision any more */
    public function handle(User $by, Reservation $reservation): Reservation
    {
        return DB::transaction(function () use ($by, $reservation): Reservation {
            $locked = $this->lock($reservation);
            $locked->assertCanMoveTo(ReservationStatus::Confirmed);

            $fields = ['status', 'decided_by', 'decided_at', 'decision_reason'];
            $before = $this->snapshot($locked, $fields);
            [$proofBefore, $proofAfter] = $this->review($locked, $by, PaymentProofStatus::Accepted);

            $locked->update([
                'status' => ReservationStatus::Confirmed,
                'decided_by' => $by->id,
                'decided_at' => Date::now(),
                'decision_reason' => null,
            ]);

            $this->audit->record('reservation.confirmed', $locked, [...$before, ...$proofBefore], [...$this->snapshot($locked, $fields), ...$proofAfter]);

            return $locked;
        });
    }
}
