<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\MarkViewingDone;
use App\Http\Controllers\Landlord\Concerns\AnswersViewings;
use App\Models\User;
use App\Models\ViewingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** POST /landlord/viewings/{viewing}/done (slice 6.2): owner or caretaker. Another account's request is a 404 (the scope binds the route); whether the status and the clock allow it is the action's, on the locked row. */
final class MarkViewingDoneController
{
    use AnswersViewings;

    public function __invoke(Request $request, ViewingRequest $viewing, MarkViewingDone $action): RedirectResponse|JsonResponse
    {
        Gate::authorize('markDone', $viewing);

        /** @var User $user (auth + landlord middleware) */
        $user = $request->user();
        $model = $action->handle($user, $viewing);

        return $this->answer($request, $model, __('Viewing marked as done.'));
    }
}
