<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Concerns\DecidesReservations;
use App\Enums\ReservationStatus;
use App\Exceptions\Domain\InvalidTransition;
use App\Models\Reservation;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\DB;

/**
 * The system closes a stay whose `move_out` has come: `active` → `ended` (docs/RESERVATIONS.md). Run for each
 * row by `reservations:end-stays`, once a day after midnight in Manila. The dates stay as they were (unlike the
 * landlord's early `EndStay`). No email, no audit row (no one acted).
 */
final class EndFinishedStay
{
    use DecidesReservations;

    /** @throws InvalidTransition when it isn't `active` any more, or its move-out date hasn't come */
    public function handle(Reservation $reservation): Reservation
    {
        return DB::transaction(function () use ($reservation): Reservation {
            $locked = $this->lock($reservation);
            $locked->assertCanMoveTo(ReservationStatus::Ended);

            if ($locked->move_out->gt(BusinessDate::today())) {
                throw InvalidTransition::stayNotOver();
            }

            $locked->update(['status' => ReservationStatus::Ended]);

            return $locked;
        });
    }
}
