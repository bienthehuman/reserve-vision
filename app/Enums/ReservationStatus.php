<?php

declare(strict_types=1);

namespace App\Enums;

use App\Exceptions\Domain\InvalidTransition;

/**
 * Mirrors the `reservations.status` CHECK and the lifecycle in docs/RESERVATIONS.md.
 * The transition table below is compared with the one in that file by
 * tests/Feature/Docs/TransitionTableTest.php: change one, change the other.
 */
enum ReservationStatus: string
{
    case Held = 'held';
    case ProofUploaded = 'proof_uploaded';
    case Confirmed = 'confirmed';
    case Active = 'active';
    case Expired = 'expired';
    case Declined = 'declined';
    case Cancelled = 'cancelled';
    case Ended = 'ended';

    /**
     * Every arrow of the lifecycle, one row per action, in the order of the doc.
     * `from` is null for the creating action.
     *
     * @return list<array{from: ?self, to: self, action: string}>
     */
    public static function transitions(): array
    {
        return [
            ['from' => null, 'to' => self::Held, 'action' => 'ReserveSpace'],
            ['from' => self::Held, 'to' => self::ProofUploaded, 'action' => 'UploadPaymentProof'],
            ['from' => self::Held, 'to' => self::Expired, 'action' => 'ExpireHolds'],
            ['from' => self::Held, 'to' => self::Cancelled, 'action' => 'CancelReservation'],
            ['from' => self::ProofUploaded, 'to' => self::Held, 'action' => 'RejectProof'],
            ['from' => self::ProofUploaded, 'to' => self::Confirmed, 'action' => 'ConfirmReservation'],
            ['from' => self::ProofUploaded, 'to' => self::Declined, 'action' => 'DeclineReservation'],
            ['from' => self::ProofUploaded, 'to' => self::Cancelled, 'action' => 'CancelReservation'],
            ['from' => self::Confirmed, 'to' => self::ProofUploaded, 'action' => 'UndoConfirmation'],
            ['from' => self::Confirmed, 'to' => self::Active, 'action' => 'MarkMovedIn'],
            ['from' => self::Confirmed, 'to' => self::Cancelled, 'action' => 'CancelReservation'],
            ['from' => self::Confirmed, 'to' => self::Cancelled, 'action' => 'WithdrawReservation'],
            ['from' => self::Active, 'to' => self::Ended, 'action' => 'EndStay'],
            ['from' => self::Active, 'to' => self::Ended, 'action' => 'EndStays'],
        ];
    }

    public function canTransitionTo(self $to): bool
    {
        foreach (self::transitions() as $row) {
            if ($row['from'] === $this && $row['to'] === $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws InvalidTransition
     */
    public function assertCanTransitionTo(self $to): void
    {
        if (! $this->canTransitionTo($to)) {
            throw InvalidTransition::between($this, $to);
        }
    }

    /**
     * Always blocks overlapping stays. `held` is not listed: it blocks only
     * while `hold_expires_at` is in the future, which Reservation::scopeBlocking()
     * (slice 2.3) handles.
     */
    public function isBlockingStatus(): bool
    {
        return match ($this) {
            self::ProofUploaded, self::Confirmed, self::Active => true,
            default => false,
        };
    }
}
