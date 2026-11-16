<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/** Why a photo can't be uploaded or uploaded again. */
final class CannotEditPhoto extends BusinessRuleException
{
    public static function limitReached(int $max): self
    {
        return new self("A property can have at most {$max} photos. Delete one to add another.");
    }

    public static function notFailed(): self
    {
        return new self('Only a photo that failed can be uploaded again. To replace this one, delete it and add the new photo.');
    }
}
