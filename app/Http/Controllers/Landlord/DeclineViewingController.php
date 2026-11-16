<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\DeclineViewing;
use App\Http\Controllers\Landlord\Concerns\AnswersViewings;
use App\Http\Requests\ViewingNoteRequest;
use App\Models\User;
use App\Models\ViewingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/** POST /landlord/viewings/{viewing}/decline (slice 6.2): owner or caretaker. Another account's request is a 404 (the scope binds the route); whether the status and the clock allow it is the action's, on the locked row. */
final class DeclineViewingController
{
    use AnswersViewings;

    public function __invoke(ViewingNoteRequest $request, ViewingRequest $viewing, DeclineViewing $action): RedirectResponse|JsonResponse
    {
        /** @var User $user (auth + landlord middleware) */
        $user = $request->user();
        $model = $action->handle($user, $viewing, $request->note());

        return $this->answer($request, $model, __('Viewing declined.'));
    }
}
