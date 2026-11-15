<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ReservationStatus;
use DomainException;

/** A reservation was asked to move somewhere docs/RESERVATIONS.md doesn't allow. */
final class InvalidTransition extends DomainException
{
    public static function between(ReservationStatus $from, ReservationStatus $to): self
    {
        return new self("A reservation that is {$from->value} can't become {$to->value}.");
    }
}
