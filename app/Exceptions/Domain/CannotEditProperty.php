<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/** Why a property edit is refused (UpdateProperty, CreateProperty). */
final class CannotEditProperty extends BusinessRuleException
{
    public static function slugLocked(): self
    {
        return new self('The link to a published property\'s listing can\'t be changed, so the links people already have keep working.');
    }

    public static function cityLocked(): self
    {
        return new self('The city can\'t be changed after a property has been published, because it is part of the link to its listing.');
    }

    public static function slugTaken(): self
    {
        return new self('Another property in this city already has a listing link like this one. Change the property\'s name a little and try again.');
    }
}
