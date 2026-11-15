<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use Carbon\CarbonImmutable;

final class MoveInOutOfRange extends BusinessRuleException
{
    public static function between(CarbonImmutable $first, CarbonImmutable $last): self
    {
        return new self("Choose a move-in date from {$first->format('M j, Y')} to {$last->format('M j, Y')}.");
    }
}
