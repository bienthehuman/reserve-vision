<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/** Why a person with a valid invitation link can't join the account (AcceptInvitation). */
final class CannotJoin extends BusinessRuleException
{
    public static function differentEmail(): self
    {
        return new self('This invitation was sent to another email address. Log out, then log in or sign up with that email.');
    }

    public static function isARenter(): self
    {
        return new self('This email belongs to a renter account. A person is either a renter or a landlord, so ask the owner to invite another email address of yours.');
    }

    public static function alreadyAMember(): self
    {
        return new self('You already belong to a landlord account, and a person can belong to only one.');
    }

    public static function logInFirst(): self
    {
        return new self('There is already an account with this email. Log in, then open the invitation link again.');
    }
}
