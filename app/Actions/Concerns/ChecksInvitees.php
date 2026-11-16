<?php

declare(strict_types=1);

namespace App\Actions\Concerns;

use App\Enums\UserType;
use App\Exceptions\Domain\CannotInvite;
use App\Models\LandlordMember;
use App\Models\User;

/** Who may be invited: shared by CreateInvitation and ResendInvitation. */
trait ChecksInvitees
{
    /**
     * Refused: an email of a renter (a person is one type, DATA_MODEL.md) and an email that
     * already belongs to a member of any account. A landlord user without a membership (a
     * removed member) can be invited again.
     *
     * @throws CannotInvite
     */
    private function assertEmailCanBeInvited(string $email): void
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            return;
        }

        if ($user->type === UserType::Renter) {
            throw CannotInvite::isARenter();
        }

        if (LandlordMember::query()->where('user_id', $user->id)->exists()) {
            throw CannotInvite::alreadyAMember();
        }
    }
}
