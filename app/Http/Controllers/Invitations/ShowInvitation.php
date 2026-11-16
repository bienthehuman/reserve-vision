<?php

declare(strict_types=1);

namespace App\Http\Controllers\Invitations;

use App\Enums\UserType;
use App\Exceptions\Domain\CannotJoin;
use App\Exceptions\Domain\InvitationNotValid;
use App\Models\LandlordInvitation;
use App\Models\User;
use App\Support\LandlordMembership;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /invitations/{token}: the page an invitation email links to (ACCESS.md, "Accepting an
 * invitation"). Public and rate limited. An unknown, expired, revoked or already accepted token
 * gets ONE neutral page (404), which never says which it was. A valid one shows the account and
 * role, and what this visitor can do next (`state`):
 *
 *   register    nobody is logged in and no user has the invited email: the short sign-up form
 *   login       nobody is logged in and a user has that email: log in, and come back here
 *   join        logged in as the invited email: one button
 *   refused     logged in as the invited email, but a renter or already a member (`reason`)
 *   other_user  logged in as somebody else: log out
 */
final class ShowInvitation
{
    public function __invoke(Request $request, string $token): Response
    {
        $invitation = LandlordInvitation::query()->forToken($token)->with(['landlordAccount', 'inviter'])->first();

        if ($invitation === null || ! $invitation->isPending()) {
            return self::notValid($request);
        }

        $user = $request->user();
        [$state, $reason] = $user instanceof User ? $this->forUser($user, $invitation) : $this->forGuest($request, $invitation);

        return Inertia::render('Invitations/Show', [
            'state' => $state,
            'reason' => $reason,
            'invitation' => [
                'account' => $invitation->landlordAccount->name,
                'role' => $invitation->role->value,
                'invited_by' => $invitation->inviter->full_name,
                'email' => $invitation->email,
                'accept_url' => route('invitations.accept', ['token' => $token], absolute: false),
            ],
        ])->toResponse($request);
    }

    /** The one page for every link that can't be used. */
    public static function notValid(Request $request): Response
    {
        return Inertia::render('Invitations/Show', [
            'state' => 'invalid',
            'reason' => __(InvitationNotValid::MESSAGE),
            'invitation' => null,
        ])->toResponse($request)->setStatusCode(404);
    }

    /**
     * @return array{string, string|null}
     */
    private function forGuest(Request $request, LandlordInvitation $invitation): array
    {
        if (! User::query()->where('email', $invitation->email)->exists()) {
            return ['register', null];
        }

        // After logging in they land back on this page (LoginResponse goes to the intended URL).
        redirect()->setIntendedUrl($request->url());

        return ['login', null];
    }

    /**
     * @return array{string, string|null}
     */
    private function forUser(User $user, LandlordInvitation $invitation): array
    {
        return match (true) {
            mb_strtolower($user->email) !== mb_strtolower($invitation->email) => ['other_user', null],
            $user->type === UserType::Renter => ['refused', CannotJoin::isARenter()->getMessage()],
            LandlordMembership::of($user) !== null => ['refused', CannotJoin::alreadyAMember()->getMessage()],
            default => ['join', null],
        };
    }
}
