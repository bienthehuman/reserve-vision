<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\LandlordRole;
use App\Exceptions\Isolation\NoCurrentLandlordAccount;
use App\Models\LandlordAccount;
use App\Models\LandlordMember;
use App\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;

/**
 * The landlord account of the logged-in landlord user, and their role in it
 * (docs/ACCESS.md "Isolation"). Bound as a scoped instance (AppServiceProvider),
 * so a queue worker or a long-lived server drops it between jobs and requests.
 *
 * The account comes **only** from the authenticated user's membership
 * (`landlord_members.user_id` is unique, so there is exactly one), never from a
 * route, query, body or header. It is resolved once per user: if the logged-in
 * user changes inside one request or test (login, logout, actingAs), the next
 * call resolves again instead of answering for the previous user.
 */
final class CurrentLandlordAccount
{
    private ?User $resolvedFor = null;

    private ?LandlordMember $member = null;

    public function __construct(private readonly AuthFactory $auth) {}

    /**
     * The logged-in user is a landlord user, so the isolation scope applies,
     * with or without a membership (without one, it matches nothing).
     */
    public function applies(): bool
    {
        return $this->user()?->isLandlord() === true;
    }

    /** A landlord user with a membership is logged in. */
    public function exists(): bool
    {
        return $this->resolve() !== null;
    }

    /** @throws NoCurrentLandlordAccount */
    public function account(): LandlordAccount
    {
        /** @var LandlordAccount landlord_members.landlord_account_id is a required foreign key */
        return $this->member()->landlordAccount;
    }

    /** @throws NoCurrentLandlordAccount */
    public function id(): int
    {
        return $this->member()->landlord_account_id;
    }

    /** The account id, or null when there is no current account. For the scope and guards that must not throw. */
    public function idOrNull(): ?int
    {
        return $this->resolve()?->landlord_account_id;
    }

    /** @throws NoCurrentLandlordAccount */
    public function role(): LandlordRole
    {
        return $this->member()->role;
    }

    /** @throws NoCurrentLandlordAccount */
    public function isOwner(): bool
    {
        return $this->role() === LandlordRole::Owner;
    }

    /** @throws NoCurrentLandlordAccount */
    public function member(): LandlordMember
    {
        return $this->resolve() ?? throw NoCurrentLandlordAccount::make();
    }

    /**
     * Drops what was resolved, so the next call reads the membership again. For the one
     * request in which a logged-in user's membership appears: AcceptInvitation (slice 4.3).
     */
    public function forget(): void
    {
        $this->user()?->unsetRelation('landlordMember');
        $this->resolvedFor = null;
        $this->member = null;
    }

    private function resolve(): ?LandlordMember
    {
        $user = $this->user();

        if ($user === null || ! $user->isLandlord()) {
            $this->resolvedFor = null;
            $this->member = null;

            return null;
        }

        if ($this->resolvedFor !== $user) {
            $this->member = LandlordMembership::of($user);
            $this->resolvedFor = $user;
        }

        return $this->member;
    }

    private function user(): ?User
    {
        $user = $this->auth->guard()->user();

        return $user instanceof User ? $user : null;
    }
}
