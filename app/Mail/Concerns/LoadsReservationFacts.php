<?php

declare(strict_types=1);

namespace App\Mail\Concerns;

use App\Data\Mail\ReservationFacts;
use App\Models\Reservation;

/**
 * A reservation mail carries only the reservation's id (and what the action alone knows: a reason, an amount),
 * and reads the facts when the worker renders it. So the action reads nothing after its transaction, and the
 * queued payload holds no personal data.
 */
trait LoadsReservationFacts
{
    private ?ReservationFacts $loadedFacts = null;

    private function facts(): ReservationFacts
    {
        return $this->loadedFacts ??= ReservationFacts::of(Reservation::query()->findOrFail($this->reservationId));
    }
}
