<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Concerns\DecidesReservations;
use App\Enums\ReservationStatus;
use App\Exceptions\Domain\InvalidTransition;
use App\Models\Reservation;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * The renter has moved in: `confirmed` → `active`, from the move-in date (business date, Manila).
 * Not undoable in v1 (backlog). The space stays blocked. Who may: ReservationPolicy::markMovedIn
 * (owner, caretaker). One audit row, `reservation.moved_in`.
 */
final class MarkMovedIn
{
    use DecidesReservations;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws InvalidTransition when it isn't confirmed, or the move-in date hasn't come */
    public function handle(Reservation $reservation): Reservation
    {
        return DB::transaction(function () use ($reservation): Reservation {
            $locked = $this->lock($reservation);
            $locked->assertCanMoveTo(ReservationStatus::Active);

            $locked->update(['status' => ReservationStatus::Active]);

            $this->audit->record('reservation.moved_in', $locked, ['status' => ReservationStatus::Confirmed], ['status' => ReservationStatus::Active]);

            return $locked;
        });
    }
}
