<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Concerns\DecidesReservations;
use App\Enums\ReservationStatus;
use App\Exceptions\Domain\InvalidTransition;
use App\Models\Reservation;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * An owner can't honour a confirmed booking: `confirmed` → `cancelled` before the move-in date, with
 * a reason the renter sees. `cancelled_by` is the owner (that is how the renter's page tells a
 * withdrawal from their own cancel), and the reason goes in `decision_reason`. Final; the space is
 * free again at once. Who may: ReservationPolicy::withdraw (owners only). One audit row, `reservation.withdrawn`.
 */
final class WithdrawReservation
{
    use DecidesReservations;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws InvalidTransition when it isn't confirmed, or the stay has started */
    public function handle(User $owner, Reservation $reservation, string $reason): Reservation
    {
        return DB::transaction(function () use ($owner, $reservation, $reason): Reservation {
            $locked = $this->lock($reservation);
            $locked->assertCanMoveTo(ReservationStatus::Cancelled);

            $fields = ['status', 'cancelled_by', 'cancelled_at', 'decided_by', 'decided_at', 'decision_reason'];
            $before = $this->snapshot($locked, $fields);

            $locked->update([
                'status' => ReservationStatus::Cancelled,
                'cancelled_by' => $owner->id,
                'cancelled_at' => Date::now(),
                'decided_by' => $owner->id,
                'decided_at' => Date::now(),
                'decision_reason' => $reason,
            ]);

            $this->audit->record('reservation.withdrawn', $locked, $before, $this->snapshot($locked, $fields));

            return $locked;
        });
    }
}
