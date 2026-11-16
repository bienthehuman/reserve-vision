<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/** Why a space can't be added or renamed. */
final class CannotEditSpace extends BusinessRuleException
{
    public static function privateRoomHasOne(): self
    {
        return new self('A private room has exactly one space. To add beds, make it a shared room.');
    }

    public static function limitReached(int $max): self
    {
        return new self("A shared room can have at most {$max} beds. Archive a bed you no longer use to add another.");
    }

    public static function labelTaken(string $label): self
    {
        return new self("This room already has a space called \"{$label}\".");
    }
}
