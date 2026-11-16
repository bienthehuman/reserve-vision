<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/**
 * The renter acted on a `held` reservation after `hold_expires_at`, whether or
 * not the ExpireHolds job has run yet (T5).
 */
final class HoldExpired extends BusinessRuleException
{
    public static function make(): self
    {
        return new self('Your hold has expired. Reserve again to get a new one.');
    }
}
