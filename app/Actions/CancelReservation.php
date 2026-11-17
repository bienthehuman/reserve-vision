<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ReservationStatus;
use App\Exceptions\Domain\InvalidTransition;
use App\Mail\Reservations\ReservationCancelled;
use App\Models\Reservation;
use App\Models\User;
use App\Support\Mail\Notify;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * The renter cancels their own reservation: from `held` (live or not), `proof_uploaded`, or
 * `confirmed` before the move-in date (docs/RESERVATIONS.md). The space is free again at once,
 * because `cancelled` doesn't block. Whether the fee is refunded is the house rules' and the
 * landlord records it (6.1). Who may call this is ReservationPolicy::cancel; no audit row
 * (a renter action), and the landlord's members are emailed after the transaction when a proof had been sent (6.3).
 */
final class CancelReservation
{
    /** @throws InvalidTransition when the status doesn't allow it, or the confirmed stay has started */
    public function handle(User $renter, Reservation $reservation): Reservation
    {
        $hadProof = false;

        $cancelled = DB::transaction(function () use ($renter, $reservation, &$hadProof): Reservation {
            $locked = Reservation::query()->ownedByRenter($renter)->whereKey($reservation->getKey())->lockForUpdate()->firstOrFail();
            $locked->assertCanMoveTo(ReservationStatus::Cancelled);
            $hadProof = $locked->status !== ReservationStatus::Held;

            $locked->update([
                'status' => ReservationStatus::Cancelled,
                'cancelled_by' => $renter->id,
                'cancelled_at' => Date::now(),
            ]);

            return $locked;
        });

        // A held reservation was never in front of the landlord; one with a proof was.
        if ($hadProof) {
            Notify::landlordMembers($cancelled->landlord_account_id, new ReservationCancelled($cancelled->id, hadProof: true));
        }

        return $cancelled;
    }
}
