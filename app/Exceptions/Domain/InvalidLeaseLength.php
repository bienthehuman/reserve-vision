<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

final class InvalidLeaseLength extends BusinessRuleException
{
    public static function between(int $min, int $max): self
    {
        return new self("The lease must be from {$min} to {$max} whole months.");
    }
}
