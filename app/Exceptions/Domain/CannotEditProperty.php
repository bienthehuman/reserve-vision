<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/** Why a property edit is refused (UpdateProperty, CreateProperty). */
final class CannotEditProperty extends BusinessRuleException
{
    public static function slugLocked(): self
    {
        return new self('The web address of a property that has been published can\'t change, so links to it keep working.');
    }

    public static function cityLocked(): self
    {
        return new self('The city of a property that has been published can\'t change, because it is part of its web address.');
    }

    public static function slugTaken(): self
    {
        return new self('Another property already uses that web address in this city. Change the address part (the slug) and try again.');
    }
}
