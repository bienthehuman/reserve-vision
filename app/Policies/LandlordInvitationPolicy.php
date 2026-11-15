<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LandlordInvitation;
use App\Models\User;
use App\Policies\Concerns\ChecksAccess;
use Illuminate\Auth\Access\Response;

/** docs/ACCESS.md "Permission matrix"; mapped row by row in tests/Feature/Docs/PermissionMatrixTest.php. */
final class LandlordInvitationPolicy
{
    use ChecksAccess;

    /** Invite or remove caretakers, revoke invites: an invitation to the user's own account. */
    public function create(User $user): Response
    {
        return $this->landlordRole($user, self::OWNERS);
    }

    /** Invite or remove caretakers, revoke invites. */
    public function revoke(User $user, LandlordInvitation $invitation): Response
    {
        return $this->landlordOn($user, $invitation->landlord_account_id, self::OWNERS);
    }
}
