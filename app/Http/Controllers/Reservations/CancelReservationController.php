<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reservations;

use App\Actions\CancelReservation;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * `POST /reservations/{reservation}/cancel`. Only the renter's own reservation (another renter's
 * is a 404); whether its status still allows cancelling is CancelReservation's rule, on the locked
 * row (a refusal is the `error` flash on the hold page).
 */
final class CancelReservationController
{
    public function __invoke(Request $request, int $reservation, CancelReservation $cancel): RedirectResponse
    {
        /** @var User $renter (auth + renter middleware) */
        $renter = $request->user();

        $reservation = Reservation::query()->ownedByRenter($renter)->findOrFail($reservation);
        Gate::authorize('cancel', $reservation);

        $cancel->handle($renter, $reservation);

        return redirect()->route('reservations.show', $reservation->id)
            ->with('status', __('Your reservation is cancelled.'));
    }
}
