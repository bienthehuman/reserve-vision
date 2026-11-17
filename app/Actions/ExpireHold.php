<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Concerns\DecidesReservations;
use App\Enums\ReservationStatus;
use App\Exceptions\Domain\InvalidTransition;
use App\Mail\Reservations\HoldExpired;
use App\Models\Reservation;
use App\Support\Mail\Notify;
use Illuminate\Support\Facades\DB;

/**
 * The system tidies a hold that ran out: `held` → `expired` (docs/RESERVATIONS.md). Run for each row by
 * `reservations:expire-holds`. Correctness never depends on it: an expired hold stopped blocking at
 * `hold_expires_at` (`Reservation::scopeBlocking()`), so this only fixes the status and emails the renter.
 * Lock the row first and ask the transition table again: a proof uploaded a second ago is not an error for
 * the job, it is a row to skip. No audit row (no one acted).
 */
final class ExpireHold
{
    use DecidesReservations;

    /** @throws InvalidTransition when the row has moved on (a proof, a cancel) or the hold is live again (a rejected proof) */
    public function handle(Reservation $reservation): Reservation
    {
        $expired = DB::transaction(function () use ($reservation): Reservation {
            $locked = $this->lock($reservation);
            $locked->assertCanMoveTo(ReservationStatus::Expired);
            $locked->update(['status' => ReservationStatus::Expired]);

            return $locked;
        });

        Notify::renter($expired->renter_id, new HoldExpired($expired->id));

        return $expired;
    }
}
