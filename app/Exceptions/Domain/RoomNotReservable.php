<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * The room is archived, or its property is unpublished or archived, or the
 * landlord account isn't verified. Renters normally can't even see such a
 * room (404), so one message covers every case.
 */
final class RoomNotReservable extends BusinessRuleException
{
    public static function make(): self
    {
        return new self("This room can't be reserved right now.");
    }
}
