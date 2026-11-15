<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LandlordMember;
use App\Models\User;
use App\Policies\Concerns\ChecksAccess;
use Illuminate\Auth\Access\Response;

/**
 * docs/ACCESS.md "Permission matrix"; mapped row by row in
 * tests/Feature/Docs/PermissionMatrixTest.php. `landlord_members` has no
 * isolation scope (the scope itself reads it), so this policy is what keeps
 * another account's members out. The last-owner guard is RemoveMember's (4.3).
 */
final class LandlordMemberPolicy
{
    use ChecksAccess;

    /** Invite or remove caretakers, revoke invites. */
    public function remove(User $user, LandlordMember $member): Response
    {
        return $this->landlordOn($user, $member->landlord_account_id, self::OWNERS);
    }
}
