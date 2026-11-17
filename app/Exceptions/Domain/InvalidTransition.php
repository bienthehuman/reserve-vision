<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use App\Enums\ReservationStatus;
use Carbon\CarbonImmutable;

/** A reservation was asked to move somewhere docs/RESERVATIONS.md doesn't allow. */
final class InvalidTransition extends BusinessRuleException
{
    public static function between(ReservationStatus $from, ReservationStatus $to): self
    {
        return new self("A reservation that is {$from->value} can't become {$to->value}.");
    }

    /** `confirmed` → `cancelled` on or after the move-in date (RESERVATIONS.md: `today < move_in`). */
    public static function stayStarted(): self
    {
        return new self("This stay has already started, so it can't be cancelled here. Talk to the landlord.");
    }

    public static function holdStillLive(): self
    {
        return new self("This hold hasn't expired yet.");
    }

    /** proof_uploaded → held a third time: the limit of RejectProof. */
    public static function rejectionLimit(): self
    {
        return new self('A new proof has already been asked for twice. Confirm this one or decline the reservation.');
    }

    /** confirmed → proof_uploaded more than 24 hours after the confirmation. */
    public static function undoWindowClosed(): self
    {
        return new self('A confirmation can only be undone within 24 hours.');
    }

    /** confirmed → proof_uploaded on or after the move-in date. */
    public static function undoAfterMoveIn(): self
    {
        return new self("The stay has started, so the confirmation can't be undone.");
    }

    /** confirmed → active before the move-in date. */
    public static function beforeMoveIn(CarbonImmutable $moveIn): self
    {
        return new self("The renter can be marked as moved in from {$moveIn->format('D j M Y')}.");
    }

    /** active → ended by the daily job before the planned move-out has come. */
    public static function stayNotOver(): self
    {
        return new self("This stay hasn't reached its move-out date yet.");
    }
}
