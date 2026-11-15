<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\CreateInvitation;
use App\Http\Requests\StoreInvitationRequest;
use App\Models\LandlordInvitation;
use App\Models\User;
use App\Support\CurrentLandlordAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/** POST /landlord/invitations: owners only. Creates the row; the email and acceptance are 4.3. */
final class StoreInvitation
{
    public function __invoke(
        StoreInvitationRequest $request,
        CurrentLandlordAccount $current,
        CreateInvitation $createInvitation,
    ): RedirectResponse {
        Gate::authorize('create', LandlordInvitation::class);

        /** @var User $inviter (auth + landlord middleware) */
        $inviter = $request->user();

        $created = $createInvitation->handle($current->account(), $inviter, $request->string('email')->toString());

        return redirect()
            ->route('landlord.members.index')
            ->with('status', __('Invitation saved for :email. The email is not sent yet.', ['email' => $created->invitation->email]));
    }
}
