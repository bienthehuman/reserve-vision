<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class InvalidRate extends BusinessRuleException
{
    public static function make(): self
    {
        return new self('A monthly rate must be more than ₱0 and at most ₱42,949,672.');
    }
}
