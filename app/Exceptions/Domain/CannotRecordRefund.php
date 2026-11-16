<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

/** A refund record on a reservation that can't have one (RESERVATIONS.md "Refunds"). */
final class CannotRecordRefund extends BusinessRuleException
{
    public static function notEnded(): self
    {
        return new self('A refund can only be recorded once a reservation is declined or cancelled.');
    }

    public static function nothingPaid(): self
    {
        return new self('The renter never sent a proof of payment, so there is no fee to refund.');
    }
}
