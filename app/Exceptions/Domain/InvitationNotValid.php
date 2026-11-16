<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * The invitation link can't be used: unknown, expired, revoked or already accepted.
 * One message for all four, on purpose: the link's holder isn't told which.
 */
final class InvitationNotValid extends BusinessRuleException
{
    public const string MESSAGE = 'This invitation link isn\'t valid any more. Ask the owner to send a new one.';

    public static function make(): self
    {
        return new self(self::MESSAGE);
    }
}
