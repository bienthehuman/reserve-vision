<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\RevokeInvitation;
use App\Models\LandlordInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/** DELETE /landlord/invitations/{invitation}: takes the invitation back; its link stops working. Owners only. */
final class RevokeInvitationController
{
    public function __invoke(LandlordInvitation $invitation, RevokeInvitation $revoke): RedirectResponse
    {
        Gate::authorize('revoke', $invitation);

        $invitation = $revoke->handle($invitation);

        return redirect()
            ->route('landlord.members.index')
            ->with('status', __('The invitation for :email is cancelled. Its link no longer works.', ['email' => $invitation->email]));
    }
}
