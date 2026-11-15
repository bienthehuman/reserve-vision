<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\LandlordMember;
use App\Models\User;

/**
 * A user's landlord membership, loaded once per user instance and kept on it
 * (the `landlordMember` relation), so CurrentLandlordAccount and the policies
 * share one query per request. Null for renters and for a landlord user
 * without a membership.
 */
final class LandlordMembership
{
    public static function of(User $user): ?LandlordMember
    {
        if (! $user->isLandlord()) {
            return null;
        }

        if (! $user->relationLoaded('landlordMember')) {
            $user->setRelation('landlordMember', $user->landlordMember()->with('landlordAccount')->first());
        }

        $member = $user->getRelation('landlordMember');

        return $member instanceof LandlordMember ? $member : null;
    }
}
