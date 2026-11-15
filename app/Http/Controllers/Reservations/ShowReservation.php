<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reservations;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The renter's "your hold" page (slice 2.5, minimal). Someone else's
 * reservation is a 404, never a 403 (ACCESS.md): the renter's own-reservations
 * scope finds nothing, and ReservationPolicy::viewOwn denies as not found
 * behind it. Proof upload comes in 5.3.
 */
final class ShowReservation
{
    public function __invoke(Request $request, int $reservation): Response
    {
        /** @var User $renter (auth + renter middleware) */
        $renter = $request->user();

        $reservation = Reservation::query()->ownedByRenter($renter)->findOrFail($reservation);
        Gate::authorize('viewOwn', $reservation);

        $space = $reservation->space()->firstOrFail();
        $room = $space->room()->firstOrFail();
        $property = $room->property()->firstOrFail();

        return Inertia::render('Reservations/Show', [
            'reservation' => [
                'id' => $reservation->id,
                'status' => $reservation->status->value,
                'is_live_hold' => $reservation->isLiveHold(),
                'reservation_fee' => $reservation->reservation_fee,
                'hold_expires_at' => $reservation->hold_expires_at?->toIso8601String(),
                'move_in' => $reservation->move_in->toDateString(),
                'move_out' => $reservation->move_out->toDateString(),
                'space_label' => $space->label,
            ],
            'room' => [
                'id' => $room->id,
                'name' => $room->name,
            ],
            'property' => [
                'name' => $property->name,
                'barangay' => $property->barangay,
                'city' => $property->city,
            ],
        ]);
    }
}
