<?php

declare(strict_types=1);

namespace App\Enums;

use App\Exceptions\Domain\InvalidViewingMove;

/**
 * Mirrors the `viewing_requests.status` CHECK and the lifecycle in docs/ACCESS.md (flow C, "Transition table").
 * The table below is compared with the one in that file by tests/Feature/Docs/ViewingTransitionTableTest.php:
 * change one, change the other. The guards that depend on the clock are ViewingRequest::assertCanMoveTo()'s.
 */
enum ViewingRequestStatus: string
{
    case Requested = 'requested';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Done = 'done';
    case Cancelled = 'cancelled';

    /**
     * Every arrow of the lifecycle, one row per action. `from` is null for the creating action.
     *
     * @return list<array{from: ?self, to: self, action: string}>
     */
    public static function transitions(): array
    {
        return [
            ['from' => null, 'to' => self::Requested, 'action' => 'RequestViewing'],
            ['from' => self::Requested, 'to' => self::Accepted, 'action' => 'AcceptViewing'],
            ['from' => self::Requested, 'to' => self::Declined, 'action' => 'DeclineViewing'],
            ['from' => self::Accepted, 'to' => self::Declined, 'action' => 'DeclineViewing'],
            ['from' => self::Declined, 'to' => self::Accepted, 'action' => 'AcceptViewing'],
            ['from' => self::Requested, 'to' => self::Cancelled, 'action' => 'CancelViewing'],
            ['from' => self::Accepted, 'to' => self::Cancelled, 'action' => 'CancelViewing'],
            ['from' => self::Accepted, 'to' => self::Done, 'action' => 'MarkViewingDone'],
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
     * @throws InvalidViewingMove
     */
    public function assertCanTransitionTo(self $to): void
    {
        if (! $this->canTransitionTo($to)) {
            throw InvalidViewingMove::between($this, $to);
        }
    }
}
