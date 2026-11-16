<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Actions\CreateInvitation;
use App\Http\Requests\StoreInvitationRequest;
use App\Mail\Landlord\InvitationMail;
use App\Models\LandlordInvitation;
use App\Models\User;
use App\Support\CurrentLandlordAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;

/** POST /landlord/invitations: owners only. Creates the invitation and emails its link (queued). */
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

        Mail::to($created->invitation->email)->queue(InvitationMail::for($created));

        return redirect()
            ->route('landlord.members.index')
            ->with('status', __('We\'ve sent an invitation to :email.', ['email' => $created->invitation->email]));
    }
}
