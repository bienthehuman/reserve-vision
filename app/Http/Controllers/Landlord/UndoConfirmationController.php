<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\UndoConfirmation;
use App\Http\Controllers\Landlord\Concerns\AnswersDecisions;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** POST /landlord/reservations/{reservation}/undo: owner or caretaker, within 24 hours. Another account's reservation is a 404 (the scope binds the route); whether the status allows it is the action's, on the locked row. */
final class UndoConfirmationController
{
    use AnswersDecisions;

    public function __invoke(Request $request, Reservation $reservation, UndoConfirmation $action): RedirectResponse|JsonResponse
    {
        Gate::authorize('undoConfirmation', $reservation);

        $model = $action->handle($reservation);

        return $this->answer($request, $model, __('Confirmation undone. The proof is waiting for your decision again.'));
    }
}
