<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class NotARenter extends BusinessRuleException
{
    public static function make(): self
    {
        return new self('Only renter accounts can reserve a room.');
    }
}
