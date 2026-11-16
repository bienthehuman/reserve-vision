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
 * A landlord member turns the reservation down: `proof_uploaded` → `declined`, with a reason the
 * renter sees. Final: there is no undo (RESERVATIONS.md "Correction paths"), and the space is free
 * again at once. The pending proof becomes `rejected` with the same reason, so no proof is left
 * "waiting". Whether the fee is refunded is recorded afterwards (RecordRefund). Who may:
 * ReservationPolicy::decline (owner, caretaker). One audit row, `reservation.declined`.
 */
final class DeclineReservation
{
    use DecidesReservations;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws InvalidTransition when the reservation isn't waiting for a decision any more */
    public function handle(User $by, Reservation $reservation, string $reason): Reservation
    {
        return DB::transaction(function () use ($by, $reservation, $reason): Reservation {
            // TEMPORARY negative control: a plain read instead of the row lock (the decision race must fail).
            $locked = Reservation::query()->whereKey($reservation->getKey())->firstOrFail();
            $locked->assertCanMoveTo(ReservationStatus::Declined);

            $fields = ['status', 'decided_by', 'decided_at', 'decision_reason'];
            $before = $this->snapshot($locked, $fields);
            [$proofBefore, $proofAfter] = $this->review($locked, $by, PaymentProofStatus::Rejected, $reason);

            $locked->update([
                'status' => ReservationStatus::Declined,
                'decided_by' => $by->id,
                'decided_at' => Date::now(),
                'decision_reason' => $reason,
            ]);

            $this->audit->record('reservation.declined', $locked, [...$before, ...$proofBefore], [...$this->snapshot($locked, $fields), ...$proofAfter]);

            return $locked;
        });
    }
}
