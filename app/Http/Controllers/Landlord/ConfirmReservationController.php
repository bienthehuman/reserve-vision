<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\ConfirmReservation;
use App\Http\Controllers\Landlord\Concerns\AnswersDecisions;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** POST /landlord/reservations/{reservation}/confirm: owner or caretaker. Another account's reservation is a 404 (the scope binds the route); whether the status allows it is the action's, on the locked row. */
final class ConfirmReservationController
{
    use AnswersDecisions;

    public function __invoke(Request $request, Reservation $reservation, ConfirmReservation $action): RedirectResponse|JsonResponse
    {
        Gate::authorize('confirm', $reservation);

        /** @var User $user (auth + landlord middleware) */
        $user = $request->user();
        $model = $action->handle($user, $reservation);

        return $this->answer($request, $model, __('Reservation confirmed. The renter can move in on the agreed date.'));
    }
}
