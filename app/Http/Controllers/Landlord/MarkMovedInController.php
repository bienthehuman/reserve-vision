<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\MarkMovedIn;
use App\Http\Controllers\Landlord\Concerns\AnswersDecisions;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** POST /landlord/reservations/{reservation}/moved-in: owner or caretaker, from the move-in date. Another account's reservation is a 404 (the scope binds the route); whether the status allows it is the action's, on the locked row. */
final class MarkMovedInController
{
    use AnswersDecisions;

    public function __invoke(Request $request, Reservation $reservation, MarkMovedIn $action): RedirectResponse|JsonResponse
    {
        Gate::authorize('markMovedIn', $reservation);

        $model = $action->handle($reservation);

        return $this->answer($request, $model, __('Marked as moved in.'));
    }
}
