<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/** The per-renter limits on open reservations (docs/RESERVATIONS.md "Booking rules"). */
final class HoldLimitReached extends BusinessRuleException
{
    public static function overall(int $limit): self
    {
        return new self("You already have {$limit} open reservations, the most you can have at once. Cancel one, or wait for a landlord to decide on one, before reserving again.");
    }

    public static function inProperty(): self
    {
        return new self('You already have an open reservation in this property. Cancel it, or wait for the landlord to decide on it, before reserving another room here.');
    }
}
