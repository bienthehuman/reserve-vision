<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/** Why an invitation can't be created (CreateInvitation). The messages are shown to the owner as they are. */
final class CannotInvite extends BusinessRuleException
{
    public static function alreadyAMember(): self
    {
        return new self('That email already belongs to a member of a landlord account, so it can\'t be invited.');
    }

    public static function isARenter(): self
    {
        return new self('That email belongs to a renter account. A person is either a renter or a landlord, so ask them to use another email address.');
    }

    public static function alreadyPending(): self
    {
        return new self('There is already a pending invitation for that email.');
    }
}
