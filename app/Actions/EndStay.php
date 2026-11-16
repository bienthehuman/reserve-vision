<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Concerns\DecidesReservations;
use App\Enums\ReservationStatus;
use App\Exceptions\Domain\InvalidTransition;
use App\Models\Reservation;
use App\Support\AuditLogger;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\DB;

/**
 * The renter has left: `active` → `ended`. When that is before the planned end, `move_out` becomes
 * `max(today, move_in + 1 day)` (a stay is at least one night, and `move_out` is exclusive), so the
 * space is free from that date; `ended` doesn't block at all, and the shortened dates keep the record
 * true. A stay already past its `move_out` keeps it. Who may: ReservationPolicy::endStay (owner,
 * caretaker). One audit row, `reservation.stay_ended`.
 */
final class EndStay
{
    use DecidesReservations;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws InvalidTransition when the stay isn't under way */
    public function handle(Reservation $reservation): Reservation
    {
        return DB::transaction(function () use ($reservation): Reservation {
            $locked = $this->lock($reservation);
            $locked->assertCanMoveTo(ReservationStatus::Ended);

            $before = $this->snapshot($locked, ['status', 'move_out']);
            $leaves = BusinessDate::today()->max($locked->move_in->addDay());

            $locked->update([
                'status' => ReservationStatus::Ended,
                'move_out' => $leaves->lt($locked->move_out) ? $leaves : $locked->move_out,
            ]);

            $this->audit->record('reservation.stay_ended', $locked, $before, $this->snapshot($locked, ['status', 'move_out']));

            return $locked;
        });
    }
}
