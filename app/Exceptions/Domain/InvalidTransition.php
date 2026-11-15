<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Enums\ReservationStatus;

/** A reservation was asked to move somewhere docs/RESERVATIONS.md doesn't allow. */
final class InvalidTransition extends BusinessRuleException
{
    public static function between(ReservationStatus $from, ReservationStatus $to): self
    {
        return new self("A reservation that is {$from->value} can't become {$to->value}.");
    }

    public static function holdStillLive(): self
    {
        return new self("This hold hasn't expired yet.");
    }
}
