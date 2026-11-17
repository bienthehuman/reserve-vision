<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Enums\ViewingRequestStatus;

/** A viewing request was asked to move somewhere docs/ACCESS.md (flow C) doesn't allow, or at a time it doesn't allow. */
final class InvalidViewingMove extends BusinessRuleException
{
    public static function between(ViewingRequestStatus $from, ViewingRequestStatus $to): self
    {
        return new self("A viewing request that is {$from->value} can't become {$to->value}.");
    }

    /** accept, decline or cancel once the preferred time has come. */
    public static function timePassed(): self
    {
        return new self('The time of this viewing has passed, so it can no longer be changed.');
    }

    /** mark done before the preferred time. */
    public static function notYet(): self
    {
        return new self('A viewing can be marked done from its preferred time, not before.');
    }
}
