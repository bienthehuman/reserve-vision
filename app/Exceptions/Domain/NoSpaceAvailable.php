<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class NoSpaceAvailable extends BusinessRuleException
{
    public static function forDates(): self
    {
        return new self('This room has no free space for those dates. Try other dates or another room.');
    }
}
