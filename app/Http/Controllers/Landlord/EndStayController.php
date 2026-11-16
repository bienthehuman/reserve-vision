<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\EndStay;
use App\Http\Controllers\Landlord\Concerns\AnswersDecisions;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** POST /landlord/reservations/{reservation}/end: owner or caretaker. Another account's reservation is a 404 (the scope binds the route); whether the status allows it is the action's, on the locked row. */
final class EndStayController
{
    use AnswersDecisions;

    public function __invoke(Request $request, Reservation $reservation, EndStay $action): RedirectResponse|JsonResponse
    {
        Gate::authorize('endStay', $reservation);

        $model = $action->handle($reservation);

        return $this->answer($request, $model, __('Stay ended. The bed is free from the move-out date.'));
    }
}
