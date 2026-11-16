<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\RejectProof;
use App\Http\Controllers\Landlord\Concerns\AnswersDecisions;
use App\Http\Requests\ReservationReasonRequest;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * POST /landlord/reservations/{reservation}/reject: owner or caretaker, with a reason. The request asks the policy before it validates the reason; another
 * account's reservation is a 404 (the scope binds the route); whether the status allows it is the action's.
 */
final class RejectProofController
{
    use AnswersDecisions;

    public function __invoke(ReservationReasonRequest $request, Reservation $reservation, RejectProof $action): RedirectResponse|JsonResponse
    {
        /** @var User $user (auth + landlord middleware) */
        $user = $request->user();
        $model = $action->handle($user, $reservation, $request->reason());

        return $this->answer($request, $model, __('Proof sent back. The renter has 24 hours to send a new one.'));
    }
}
