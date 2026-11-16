<?php

declare(strict_types=1);

namespace App\Support\Reservations;

use App\Enums\ReservationStatus;
use App\Exceptions\Domain\BusinessRuleException;
use App\Models\PaymentProof;
use App\Models\Reservation;
use App\Models\User;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\Gate;

/**
 * What the landlord reservation page offers (slice 6.1, ACCESS.md "Decisions"): the moves the
 * transition table has from the reservation's status that the user's role may make, each with
 * whether its guard lets it through right now (Reservation::assertCanMoveTo(), the same check the
 * action makes on the locked row), and the refund record. The page explains a disabled move from
 * `undo_until`, `move_in` and `today`.
 *
 * A move the role can't make isn't listed (a caretaker never sees Withdraw), nor is Reject once a proof
 * was sent back twice (`rejections_left` is 0 and the page says to decline instead).
 */
final class DecisionPanel
{
    /** Move => [action in the transition table, ReservationPolicy ability]. In the order the page shows them. */
    private const array MOVES = [
        'confirm' => ['ConfirmReservation', 'confirm'],
        'reject' => ['RejectProof', 'rejectProof'],
        'decline' => ['DeclineReservation', 'decline'],
        'moved_in' => ['MarkMovedIn', 'markMovedIn'],
        'undo' => ['UndoConfirmation', 'undoConfirmation'],
        'withdraw' => ['WithdrawReservation', 'withdraw'],
        'end_stay' => ['EndStay', 'endStay'],
    ];

    /**
     * @return array{
     *     moves: list<array{key: string, allowed: bool}>,
     *     owner_only: list<string>,
     *     rejections_left: int,
     *     undo_until: string|null,
     *     today: string,
     *     refund: array{can_record: bool, fee_refunded: bool|null, refund_note: string|null}|null,
     * }
     */
    public static function for(Reservation $reservation, User $user): array
    {
        $moves = [];
        $ownerOnly = [];

        foreach (self::MOVES as $key => [$action, $ability]) {
            $to = self::target($reservation->status, $action);

            if ($to === null || ($key === 'reject' && self::rejectionsLeft($reservation) === 0)) {
                continue;
            }

            if (! Gate::forUser($user)->allows($ability, $reservation)) {
                $ownerOnly[] = $key;

                continue;
            }

            $moves[] = ['key' => $key, 'allowed' => self::passes($reservation, $to)];
        }

        return [
            'moves' => $moves,
            'owner_only' => $ownerOnly,
            'rejections_left' => self::rejectionsLeft($reservation),
            'undo_until' => $reservation->undoUntil()?->toIso8601String(),
            'today' => BusinessDate::today()->toDateString(),
            'refund' => self::refund($reservation, $user),
        ];
    }

    /** Where `$action` takes a reservation from `$from`, by the transition table; null when it has no such row. */
    private static function target(ReservationStatus $from, string $action): ?ReservationStatus
    {
        foreach (ReservationStatus::transitions() as $row) {
            if ($row['from'] === $from && $row['action'] === $action) {
                return $row['to'];
            }
        }

        return null;
    }

    private static function rejectionsLeft(Reservation $reservation): int
    {
        return max(0, Reservation::MAX_PROOF_REJECTIONS - $reservation->proof_rejections);
    }

    private static function passes(Reservation $reservation, ReservationStatus $to): bool
    {
        try {
            $reservation->assertCanMoveTo($to);

            return true;
        } catch (BusinessRuleException) {
            return false;
        }
    }

    /**
     * The refund card: on a declined or cancelled reservation that had a proof (RESERVATIONS.md "Refunds").
     *
     * @return array{can_record: bool, fee_refunded: bool|null, refund_note: string|null}|null
     */
    private static function refund(Reservation $reservation, User $user): ?array
    {
        if (! in_array($reservation->status, [ReservationStatus::Declined, ReservationStatus::Cancelled], true)
            || ! PaymentProof::query()->where('payment_proofs.reservation_id', $reservation->id)->exists()) {
            return null;
        }

        return [
            'can_record' => Gate::forUser($user)->allows('recordRefund', $reservation),
            'fee_refunded' => $reservation->fee_refunded,
            'refund_note' => $reservation->refund_note,
        ];
    }
}
