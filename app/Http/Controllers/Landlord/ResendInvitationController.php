<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\ResendInvitation;
use App\Mail\Landlord\InvitationMail;
use App\Models\LandlordInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;

/** POST /landlord/invitations/{invitation}/resend: a new link by email; the old one stops working. Owners only. */
final class ResendInvitationController
{
    public function __invoke(LandlordInvitation $invitation, ResendInvitation $resend): RedirectResponse
    {
        Gate::authorize('resend', $invitation);

        $created = $resend->handle($invitation);
        Mail::to($created->invitation->email)->queue(InvitationMail::for($created));

        return redirect()
            ->route('landlord.members.index')
            ->with('status', __('We\'ve sent a new invitation to :email. The old link no longer works.', ['email' => $created->invitation->email]));
    }
}
