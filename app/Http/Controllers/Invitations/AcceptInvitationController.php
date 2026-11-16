<?php

declare(strict_types=1);

namespace App\Http\Controllers\Invitations;

use App\Actions\AcceptInvitation;
use App\Exceptions\Domain\InvitationNotValid;
use App\Http\Requests\AcceptInvitationRequest;
use App\Models\LandlordInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /invitations/{token}: accept. Logged in, it is the "Join" button; not logged in, the short
 * sign-up form, after which the new landlord user is logged in. Either way they land on /landlord.
 * Public and rate limited like the page. A token that can't be used (also one that stopped being
 * valid between the page and the click) gets the same neutral answer as the page.
 */
final class AcceptInvitationController
{
    public function __invoke(AcceptInvitationRequest $request, string $token, AcceptInvitation $accept): Response
    {
        $invitation = LandlordInvitation::query()->forToken($token)->with('landlordAccount')->first();

        if ($invitation === null || ! $invitation->isPending()) {
            return $request->expectsJson()
                ? response()->json(['message' => __(InvitationNotValid::MESSAGE)], 422)
                : redirect()->route('invitations.show', ['token' => $token], 303);
        }

        $user = $request->user();

        if ($user instanceof User) {
            $accept->handle($token, $user);
        } else {
            $user = $accept->handle($token, null, $request->details($invitation->email));

            Auth::login($user);
            $request->session()->regenerate();
        }

        $message = __('You\'ve joined :account as a :role.', ['account' => $invitation->landlordAccount->name, 'role' => $invitation->role->value]);

        return $request->expectsJson()
            ? response()->json(['message' => $message], 201)
            : redirect()->route('landlord.dashboard')->with('status', $message);
    }
}
