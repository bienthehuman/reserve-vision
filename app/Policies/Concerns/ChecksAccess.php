<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\LandlordRole;
use App\Enums\UserType;
use App\Models\User;
use App\Support\LandlordMembership;
use Illuminate\Auth\Access\Response;

/**
 * The three checks every policy is built from (docs/ACCESS.md). Another
 * account's record, or another renter's, is a 404 (denyAsNotFound): the
 * record "doesn't exist" for them, as the isolation scope already makes it.
 * Wrong role or wrong kind of user on a record they may see is a 403.
 * Guests never reach these: every ability takes a non-null User, so the Gate
 * denies guests before calling the policy.
 *
 * The account comes from the user's own membership (LandlordMembership, the
 * same one CurrentLandlordAccount uses), never from the request.
 */
trait ChecksAccess
{
    /** Both roles. */
    protected const array MEMBERS = [LandlordRole::Owner, LandlordRole::Caretaker];

    protected const array OWNERS = [LandlordRole::Owner];

    /**
     * A landlord-side ability on a record of `$accountId`: 404 unless it is the
     * user's own account, then 403 unless their role is one of `$roles`.
     *
     * @param  list<LandlordRole>  $roles
     */
    protected function landlordOn(User $user, ?int $accountId, array $roles): Response
    {
        if (! $user->isLandlord()) {
            return Response::deny();
        }

        $member = LandlordMembership::of($user);

        if ($member === null || $accountId === null || $member->landlord_account_id !== $accountId) {
            return Response::denyAsNotFound();
        }

        return in_array($member->role, $roles, true) ? Response::allow() : Response::deny();
    }

    /**
     * A landlord-side ability with no record yet (create, list): on the user's
     * own account, 403 unless they have a membership with one of `$roles`.
     *
     * @param  list<LandlordRole>  $roles
     */
    protected function landlordRole(User $user, array $roles): Response
    {
        $member = LandlordMembership::of($user);

        return $member !== null && in_array($member->role, $roles, true) ? Response::allow() : Response::deny();
    }

    /** A renter-side ability on a renter's record: 403 for landlords, 404 for another renter's. */
    protected function renterOwns(User $user, int $renterId): Response
    {
        if ($user->type !== UserType::Renter) {
            return Response::deny();
        }

        return $user->id === $renterId ? Response::allow() : Response::denyAsNotFound();
    }

    /** A renter-side ability with no record of theirs yet (reserve, request a viewing): 403 for landlords. */
    protected function renter(User $user): Response
    {
        return $user->type === UserType::Renter ? Response::allow() : Response::deny();
    }

    /**
     * See a renter's contact details on a reservation or viewing request
     * (the matrix's last row): members of the record's account, or the renter themself.
     */
    protected function renterContact(User $user, int $accountId, int $renterId): Response
    {
        return $user->isLandlord()
            ? $this->landlordOn($user, $accountId, self::MEMBERS)
            : $this->renterOwns($user, $renterId);
    }
}
