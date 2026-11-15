<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/** Why a room edit is refused (UpdateRoom, CreateRoom). */
final class CannotEditRoom extends BusinessRuleException
{
    public static function typeLocked(): self
    {
        return new self('The type of a room can\'t change once it has had a reservation, because the reservations point to its beds. Add a new room of the other type instead.');
    }

    public static function bedsNeeded(): self
    {
        return new self('Say how many beds the room has (2 to 12).');
    }
}
