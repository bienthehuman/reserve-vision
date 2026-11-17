<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\AcceptViewing;
use App\Http\Controllers\Landlord\Concerns\AnswersViewings;
use App\Http\Requests\ViewingNoteRequest;
use App\Models\User;
use App\Models\ViewingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/** POST /landlord/viewings/{viewing}/accept (slice 6.2): owner or caretaker. Another account's request is a 404 (the scope binds the route); whether the status and the clock allow it is the action's, on the locked row. */
final class AcceptViewingController
{
    use AnswersViewings;

    public function __invoke(ViewingNoteRequest $request, ViewingRequest $viewing, AcceptViewing $action): RedirectResponse|JsonResponse
    {
        /** @var User $user (auth + landlord middleware) */
        $user = $request->user();
        $model = $action->handle($user, $viewing, $request->note());

        return $this->answer($request, $model, __('Viewing accepted. The renter can see your contact number and the address.'));
    }
}
