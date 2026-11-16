<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\RegistrationDetails;
use App\Enums\UserType;
use App\Exceptions\Domain\CannotJoin;
use App\Exceptions\Domain\InvitationNotValid;
use App\Models\LandlordInvitation;
use App\Models\LandlordMember;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\CurrentLandlordAccount;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * A person with an invitation link joins the landlord account (ACCESS.md, "Accepting an invitation").
 *
 * - `$user` null (nobody logged in): `$details` creates a LANDLORD user with the invited email,
 *   whatever email the details carry, and the email is marked verified: the token proves the inbox.
 *   Refused if a user with that email already exists (they log in first).
 * - `$user` given (logged in): it must be the invited email, a landlord user, and not a member of
 *   any account yet (`landlord_members.user_id` is unique). Their email is marked verified too.
 *
 * Then the membership, with the invitation's role, and `accepted_at`. All or nothing.
 *
 * Locks: the INVITATION ROW first (`FOR UPDATE`, found by the token's hash), so two accepts of one
 * link, or an accept and a revoke or resend, run one after the other and the second sees what the
 * first did; then the user row, so two invitations to one person can't both make a membership.
 *
 * One audit row, `invitation.accepted`, on the account, with the new member as the actor.
 */
final class AcceptInvitation
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CurrentLandlordAccount $current,
    ) {}

    /**
     * @throws InvitationNotValid unknown, expired, revoked or already accepted: one message for all
     * @throws CannotJoin
     */
    public function handle(#[\SensitiveParameter] string $token, ?User $user, ?RegistrationDetails $details = null): User
    {
        return DB::transaction(function () use ($token, $user, $details): User {
            $invitation = LandlordInvitation::query()->forToken($token)->lockForUpdate()->first();

            if ($invitation === null || ! $invitation->isPending()) {
                throw InvitationNotValid::make();
            }

            $user = $user === null
                ? $this->register($invitation, $details ?? throw new LogicException('Accepting without a logged-in user needs registration details.'))
                : $this->existing($invitation, $user);

            $member = LandlordMember::create([
                'landlord_account_id' => $invitation->landlord_account_id,
                'user_id' => $user->id,
                'role' => $invitation->role,
            ]);

            // A logged-in user had no membership until this line: resolve the current account again,
            // so the isolation write guard and the audit row see the account they have just joined.
            $this->current->forget();

            $invitation->accepted_at = now()->toImmutable();
            $invitation->save();

            $this->audit->record(
                'invitation.accepted',
                $invitation,
                ['accepted_at' => null],
                ['accepted_at' => $invitation->accepted_at, 'member_id' => $member->id, 'user_id' => $user->id, 'role' => $member->role],
                actor: $user,
            );

            return $user;
        });
    }

    private function register(LandlordInvitation $invitation, RegistrationDetails $details): User
    {
        if (User::query()->where('email', $invitation->email)->lockForUpdate()->first() !== null) {
            throw CannotJoin::logInFirst();
        }

        $user = new User([...$details->userAttributes(), 'email' => $invitation->email, 'type' => UserType::Landlord]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function existing(LandlordInvitation $invitation, User $user): User
    {
        $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

        if (mb_strtolower($locked->email) !== mb_strtolower($invitation->email)) {
            throw CannotJoin::differentEmail();
        }

        if ($locked->type === UserType::Renter) {
            throw CannotJoin::isARenter();
        }

        if (LandlordMember::query()->where('user_id', $locked->id)->lockForUpdate()->first() !== null) {
            throw CannotJoin::alreadyAMember();
        }

        if (! $user->hasVerifiedEmail()) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $user;
    }
}
