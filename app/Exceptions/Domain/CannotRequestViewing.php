<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/** The rules of asking for a viewing (docs/ACCESS.md, flow C): the slot, and the per-renter limits. */
final class CannotRequestViewing extends BusinessRuleException
{
    public static function notListed(): self
    {
        return new self("This property isn't open for viewings right now.");
    }

    public static function notASlot(): self
    {
        return new self('Choose a time between 8:00 AM and 6:00 PM (Manila time), on the hour or the half hour.');
    }

    public static function tooSoon(string $earliest): self
    {
        return new self("Viewings need at least 2 hours' notice, so the landlord has time to answer. The earliest time you can ask for is {$earliest} (Manila time).");
    }

    public static function tooFar(int $days): self
    {
        return new self("You can ask for a viewing up to {$days} days ahead.");
    }

    public static function overall(int $limit): self
    {
        return new self("You already have {$limit} viewing requests open, the most you can have at once. Cancel one, or wait until one is over, before asking for another.");
    }

    public static function inProperty(): self
    {
        return new self('You already have a viewing request open at this property. Cancel it, or wait until it is over, before asking for another time.');
    }
}
