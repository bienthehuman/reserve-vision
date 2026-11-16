<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/** Why a member can't be removed from a landlord account (RemoveMember). */
final class CannotRemoveMember extends BusinessRuleException
{
    public static function yourself(): self
    {
        return new self('You can\'t remove yourself. Ask another owner to do it.');
    }

    public static function lastOwner(): self
    {
        return new self('An account needs at least one owner, so the last owner can\'t be removed.');
    }

    public static function notAnOwnerAnyMore(): self
    {
        return new self('You are no longer an owner of this account, so you can\'t remove members.');
    }
}
